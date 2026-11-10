<?php

declare(strict_types=1);

namespace DRESearch\View;

use DRESearch\Settings\SearchProfile;
use DRESearch\Settings\SortOptions;
use DRESearch\Site\BlockLayout\SearchBlockSettings;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Builds the bootstrap every DRE Search surface hands to the Svelte client:
 * a page block ({@see \DRESearch\Site\BlockLayout\SearchBlock}), the item-page
 * Project records ({@see \DRESearch\Site\ResourcePageBlockLayout\ProjectRecords})
 * and each tab of the federated results page
 * ({@see \DRESearch\View\Helper\FederatedSearch}). They used to assemble the
 * per-corpus part independently; one builder means a new bootstrap key reaches
 * every surface, and the endpoint paths live in one place.
 *
 * The per-corpus part comes from {@see SearchBlockSettings}: a block's saved
 * data, or none at all (the corpus defaults the project records and the
 * federated tabs use). The shapes are the client's `Bootstrap` and
 * `ProfileMeta` types (src/svelte/lib/types.ts); their keys do not change.
 */
final class SurfaceBootstrap
{
    /** The module's public endpoints, unprefixed; {@see endpoints()} adds the base path. */
    public const ENDPOINTS = [
        'facet'       => '/dre-search/api/facet',
        'search'      => '/dre-search/api/search',
        'export'      => '/dre-search/api/export',
        'suggest'     => '/dre-search/api/suggest',
        'map'         => '/dre-search/api/map',
        'popular'     => '/dre-search/api/popular',
        'search_all'  => '/dre-search/api/search-all',
        'union'       => '/dre-search/api/union',
        'suggest_all' => '/dre-search/api/suggest-all',
    ];

    /** What one corpus's search surface calls. */
    private const CORPUS_ENDPOINTS = ['facet', 'search', 'export', 'suggest', 'map'];

    /** @var \Closure(string):string */
    private readonly \Closure $translate;
    /** @var \Closure(string):string */
    private readonly \Closure $basePath;

    /**
     * @param callable(string):string $translate
     * @param callable(string):string $basePath
     * @param bool $popular whether surfaces offer the popular searches (popular_searches.enabled)
     */
    public function __construct(callable $translate, callable $basePath, private readonly bool $popular = false)
    {
        $this->translate = \Closure::fromCallable($translate);
        $this->basePath = \Closure::fromCallable($basePath);
    }

    public static function forView(PhpRenderer $view, bool $popular = false): self
    {
        return new self(
            static fn(string $s): string => (string) $view->translate($s),
            static fn(string $path): string => (string) $view->basePath($path),
            $popular,
        );
    }

    /**
     * The per-corpus part every surface shares, in the client's `Bootstrap`
     * names.
     *
     * @param list<string>|null $facets the sidebar facets; null = the settings' own
     * @param array{min:int,max:int}|null $yearBounds the slider's span, shown only with the year facet
     * @return array<string,mixed>
     */
    public function corpus(SearchProfile $profile, SearchBlockSettings $settings, ?array $facets = null, ?array $yearBounds = null): array
    {
        $t = $this->translate;
        $facets ??= $settings->facets();
        $facetLabels = [];
        foreach ($facets as $field) {
            $facetLabels[$field] = $t($profile->facetLabel($field));
        }
        $showYear = $settings->showYear();
        return [
            'profile'            => $profile->name(),
            'card_kind'          => $profile->kind(),
            // Corpus-specific search-box hint (e.g. "Search genres…"), so corpora
            // that share a card kind can still read distinctly. Null → the client
            // uses its kind-derived default.
            'search_placeholder' => $profile->placeholder() !== '' ? $t($profile->placeholder()) : null,
            'date_mode'          => $profile->dateMode(),
            'show_year'          => $showYear,
            'year_bounds'        => $showYear ? $yearBounds : null,
            'facets'             => $facets,
            'facet_labels'       => $facetLabels,
            // Validated against the corpus's sorts, so a stale saved value never
            // reaches the client; the options drop year sorts on date-less
            // corpora and add the count or custom sorts where configured.
            'default_sort'       => $settings->defaultSort(),
            'sort_options'       => SortOptions::forProfile($profile, $t),
            'per_page'           => $settings->perPage(),
            // The result views to offer (list, gallery, map), decided here from
            // the profile so the client never infers them from its name.
            'views'              => $profile->views(),
        ];
    }

    /**
     * An embedded surface: a page block (`block_id`) or the Project records
     * (`scope_item`, with `block_id` null). The client builds result links as
     * `${item_url_base}/${id}`.
     *
     * @param array<string,mixed> $corpus {@see corpus()}
     * @return array<string,mixed>
     */
    public function embedded(array $corpus, string $siteSlug, ?int $blockId, ?int $scopeItem = null): array
    {
        $scope = ['block_id' => $blockId];
        if ($scopeItem !== null) {
            $scope['scope_item'] = $scopeItem;
        }
        $names = self::CORPUS_ENDPOINTS;
        if ($this->popular) {
            $names[] = 'popular';
        }
        return $scope + $corpus + [
            'item_url_base' => $this->itemUrlBase($siteSlug),
            'endpoints'     => $this->endpoints($names),
        ];
    }

    /**
     * One tab of the federated results page, in the client's `ProfileMeta`
     * names (the federated shell adds the endpoints and links once).
     *
     * @param array<string,mixed> $corpus {@see corpus()}
     * @return array<string,mixed>
     */
    public function federatedTab(SearchProfile $profile, array $corpus): array
    {
        return [
            'name'         => $corpus['profile'],
            'label'        => ($this->translate)($profile->label()),
            'kind'         => $corpus['card_kind'],
            'date_mode'    => $corpus['date_mode'],
            'show_year'    => $corpus['show_year'],
            'year_bounds'  => $corpus['year_bounds'],
            'facets'       => $corpus['facets'],
            'facet_labels' => $corpus['facet_labels'],
            'default_sort' => $corpus['default_sort'],
            'sort_options' => $corpus['sort_options'],
            'per_page'     => $corpus['per_page'],
            'placeholder'  => $corpus['search_placeholder'],
            'views'        => $corpus['views'],
        ];
    }

    /**
     * Base-path-resolved endpoint URLs, by name ({@see ENDPOINTS}).
     *
     * @param list<string> $names
     * @return array<string,string>
     */
    public function endpoints(array $names): array
    {
        $urls = [];
        foreach ($names as $name) {
            $urls[$name] = ($this->basePath)(self::ENDPOINTS[$name]);
        }
        return $urls;
    }

    public function itemUrlBase(string $siteSlug): string
    {
        return ($this->basePath)('/s/' . $siteSlug . '/item');
    }
}
