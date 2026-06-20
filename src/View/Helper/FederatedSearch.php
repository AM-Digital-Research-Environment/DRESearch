<?php
declare(strict_types=1);

namespace DRESearch\View\Helper;

use DRESearch\Search\SearchProxy;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use DRESearch\Settings\SortOptions;

/**
 * Renders the federated results page surface: a mount point + a bootstrap that
 * carries metadata for EVERY corpus (so the client can show one type-tab per
 * corpus, then render the active corpus's real facets / cards / sort / paging by
 * reusing the per-corpus App).
 *
 * Deliberately inlines only profile *metadata* — no per-profile first page of
 * results — so the bootstrap stays small; the active corpus is fetched on mount
 * via the search-all endpoint.
 *
 * @see src/svelte/components/FederatedApp.svelte
 * @see src/svelte/main.ts (the [data-dre-federated-root] mount)
 */
class FederatedSearch extends AbstractDreSearchHelper
{
    private const PER_PAGE_DEFAULT = 20;

    public function __construct(
        private readonly SearchProxy $proxy,
        private readonly ProfileRegistry $registry,
    ) {
    }

    public function __invoke(string $initialQuery = ''): string
    {
        $view = $this->getView();
        $slug = $this->siteSlug();
        if ($slug === null) {
            return '';
        }
        $this->injectBundle();

        $t = fn(string $s): string => (string) $view->translate($s);

        $profiles = [];
        foreach ($this->registry->all() as $profile) {
            $profiles[] = $this->profileMeta($profile, $t);
        }
        $default = $this->registry->default();

        $bootstrap = [
            'variant'         => 'federated',
            'available'       => $this->proxy->isAvailable(),
            'item_url_base'   => $view->basePath('/s/' . $slug . '/item'),
            'initial_query'   => $initialQuery,
            'default_profile' => $default !== null ? $default->name() : '',
            'profiles'        => $profiles,
            'endpoints'       => [
                'search'      => $view->basePath('/dre-search/api/search'),
                'export'      => $view->basePath('/dre-search/api/export'),
                'search_all'  => $view->basePath('/dre-search/api/search-all'),
                'suggest'     => $view->basePath('/dre-search/api/suggest'),
                'suggest_all' => $view->basePath('/dre-search/api/suggest-all'),
            ],
        ];

        $json = $view->dreBootstrapJson($bootstrap);

        return <<<HTML
        <div class="dre-federated" data-dre-federated-root data-dre-fed-id="main">
            <div class="dre-federated__skeleton" aria-hidden="true">
                <div class="dre-federated__skeleton-tabs"></div>
                <div class="dre-federated__skeleton-row"></div>
                <div class="dre-federated__skeleton-row"></div>
                <div class="dre-federated__skeleton-row"></div>
            </div>
        </div>
        <script type="application/json" id="dre-federated-state-main">{$json}</script>
        HTML;
    }

    /**
     * Per-corpus metadata for one tab. Mirrors the per-profile portion of
     * {@see \DRESearch\Site\BlockLayout\AbstractSearchBlock::render()} but with
     * defaults (no saved block data): all facets shown, the corpus default sort,
     * the standard page size.
     *
     * @param callable(string):string $t
     * @return array<string,mixed>
     */
    private function profileMeta(SearchProfile $profile, callable $t): array
    {
        $name = $profile->name();

        $facets = $profile->fieldNames();
        $facetLabels = [];
        foreach ($facets as $field) {
            $facetLabels[$field] = $t($profile->facetLabel($field));
        }

        $showYear = $profile->hasYearFacet();

        // Validate the configured default against the sorts this corpus offers.
        $sortValues = $profile->sortOptionValues();
        $defaultSort = $profile->defaultSort();
        if (!in_array($defaultSort, $sortValues, true)) {
            $defaultSort = $sortValues[0] ?? 'relevance';
        }

        return [
            'name'         => $name,
            'label'        => $t($profile->label()),
            'kind'         => $profile->kind(),
            'date_mode'    => $profile->dateMode(),
            'show_year'    => $showYear,
            'year_bounds'  => $showYear ? $this->proxy->yearBounds($name) : null,
            'facets'       => $facets,
            'facet_labels' => $facetLabels,
            'default_sort' => $defaultSort,
            'sort_options' => SortOptions::forProfile($profile, $t),
            'per_page'     => self::PER_PAGE_DEFAULT,
            'placeholder'  => $profile->placeholder() !== '' ? $t($profile->placeholder()) : null,
        ];
    }
}
