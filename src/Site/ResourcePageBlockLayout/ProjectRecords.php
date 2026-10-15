<?php

declare(strict_types=1);

namespace DRESearch\Site\ResourcePageBlockLayout;

use DRESearch\Search\Exception\RequestValidationException;
use DRESearch\Search\ResourceScopeResolver;
use DRESearch\Search\SearchProxy;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use DRESearch\Settings\SortOptions;
use DRESearch\Site\BlockLayout\SearchBlockSettings;
use DRESearch\View\BundleAssets;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;

/**
 * "Project records (DRE Search)": a research project's item page lists the
 * project's research records through the research-items search — query box,
 * facets, sort and paging — locked to that project.
 *
 * The block sends only the project's item id (`scope_item`); the server
 * rebuilds the `project_s` filter from the item it can read
 * ({@see ResourceScopeResolver}), so a visitor cannot widen the list.
 *
 * It renders nothing — leaving the page to Omeka's own Linked resources — when
 * the item is not a project, has no records, or search is unavailable.
 */
final class ProjectRecords implements ResourcePageBlockLayoutInterface
{
    /** The registered name, which the admin Resource pages screen stores. */
    public const NAME = 'dreSearchProjectRecords';
    public const TEMPLATE = 'common/resource-page-block-layout/dre-search-project-records';

    public function __construct(
        private readonly SearchProxy $proxy,
        private readonly ProfileRegistry $registry,
        /** Reads as the current user; without it no item has records. */
        private readonly ?ApiManager $api = null,
    ) {
    }

    public function getLabel(): string
    {
        return 'Project records (DRE Search)'; // @translate
    }

    public function getCompatibleResourceNames(): array
    {
        return ['items'];
    }

    public function render(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): string
    {
        if (!$resource instanceof ItemRepresentation) {
            return '';
        }
        $itemId = (int) $resource->id();
        return $this->renderProject(
            $view,
            $itemId,
            $resource->resourceClass()?->term(),
            array_map('intval', array_keys($resource->itemSets())),
            fn(SearchProfile $profile): int => $this->countRecords($itemId, $profile),
        );
    }

    /**
     * The block for one item, from plain values (what {@see render()} reads
     * off the representation).
     *
     * @param list<int> $itemSetIds
     * @param \Closure(SearchProfile):int $countRecords the item's records as the
     *        current user may see them; called only for a project
     */
    public function renderProject(
        PhpRenderer $view,
        int $itemId,
        ?string $classTerm,
        array $itemSetIds,
        \Closure $countRecords,
    ): string {
        $profile = $this->registry->get(ResourceScopeResolver::PROFILE);
        if (
            $profile === null
            || $itemId < 1
            || !ResourceScopeResolver::isProject($classTerm, $itemSetIds, $profile->itemSet('project'))
            || $countRecords($profile) < 1
            || !$this->proxy->isAvailable()
        ) {
            return '';
        }

        $settings = new SearchBlockSettings([], $profile);
        // The list is locked to this project, so its Project facet would hold
        // one value.
        $facets = array_values(array_diff($settings->facets(), [ResourceScopeResolver::FIELD]));
        try {
            $initial = $this->proxy->search($profile->name(), [
                'q' => '',
                'page' => 1,
                'per_page' => $settings->perPage(),
                'sort' => $settings->defaultSort(),
                'facets' => $facets,
                'scope_item' => $itemId,
            ]);
        } catch (RequestValidationException) {
            // No usable scope: a private project, or a title that cannot be
            // matched literally.
            return '';
        }
        $found = (int) ($initial['found'] ?? 0);
        if (empty($initial['available']) || $found < 1) {
            return '';
        }

        BundleAssets::inject($view, BundleAssets::SEARCH_BLOCK);
        return $view->partial(self::TEMPLATE, [
            'itemId' => $itemId,
            'found' => $found,
            'bootstrap' => $this->bootstrap($view, $profile, $itemId, $settings, $facets, $initial),
        ]);
    }

    /**
     * The client bootstrap: the research items block's, with `scope_item` in
     * place of `block_id`.
     *
     * @param list<string> $facets
     * @param array<string,mixed> $initial
     * @return array<string,mixed>
     */
    public function bootstrap(
        PhpRenderer $view,
        SearchProfile $profile,
        int $itemId,
        SearchBlockSettings $settings,
        array $facets,
        array $initial,
    ): array {
        $t = fn(string $s): string => (string) $view->translate($s);
        $facetLabels = [];
        foreach ($facets as $field) {
            $facetLabels[$field] = $t($profile->facetLabel($field));
        }
        $showYear = $settings->showYear();
        $siteSlug = $view->currentSite()?->slug() ?? '';
        $bootstrap = [
            'block_id' => null,
            'scope_item' => $itemId,
            'profile' => $profile->name(),
            'card_kind' => $profile->kind(),
            'search_placeholder' => $profile->placeholder() !== '' ? $t($profile->placeholder()) : null,
            'date_mode' => $profile->dateMode(),
            'show_year' => $showYear,
            'year_bounds' => $showYear ? $this->proxy->yearBounds($profile->name()) : null,
            'facets' => $facets,
            'facet_labels' => $facetLabels,
            'default_sort' => $settings->defaultSort(),
            'sort_options' => SortOptions::forProfile($profile, $t),
            'per_page' => $settings->perPage(),
            'item_url_base' => $view->basePath('/s/' . $siteSlug . '/item'),
            'endpoints' => [
                'facet' => $view->basePath('/dre-search/api/facet'),
                'search' => $view->basePath('/dre-search/api/search'),
                'export' => $view->basePath('/dre-search/api/export'),
                'suggest' => $view->basePath('/dre-search/api/suggest'),
                'map' => $view->basePath('/dre-search/api/map'),
            ],
            'initial_response' => $initial,
        ];
        if ($this->proxy->popularEnabled()) {
            $bootstrap['endpoints']['popular'] = $view->basePath('/dre-search/api/popular');
        }
        return $bootstrap;
    }

    /**
     * Items of the corpus's template that link to the project through
     * `dcterms:isPartOf`, read as the current user: one cheap API count that
     * keeps the block (and its search request) off every project without
     * records.
     */
    private function countRecords(int $itemId, SearchProfile $profile): int
    {
        if ($this->api === null) {
            return 0;
        }
        $query = [
            'property' => [['joiner' => 'and', 'property' => 'dcterms:isPartOf', 'type' => 'res', 'text' => (string) $itemId]],
            'limit' => 1,
        ];
        if ($profile->templateId() !== null) {
            $query['resource_template_id'] = $profile->templateId();
        }
        try {
            return (int) $this->api->search('items', $query, ['returnScalar' => 'id'])->getTotalResults();
        } catch (\Throwable) {
            return 0;
        }
    }
}
