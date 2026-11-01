<?php

declare(strict_types=1);

namespace DRESearch\View\Helper;

use DRESearch\Search\SearchProxy;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use DRESearch\Site\BlockLayout\SearchBlockSettings;
use DRESearch\View\BundleAssets;
use DRESearch\View\SurfaceBootstrap;

/**
 * Renders the federated results page surface: a mount point + a bootstrap that
 * carries metadata for EVERY corpus (so the client can show one type-tab per
 * corpus, then render the active corpus's real facets / cards / sort / paging by
 * reusing the per-corpus App).
 *
 * Deliberately inlines only profile *metadata* — no per-profile first page of
 * results — so the bootstrap stays small; the active corpus is fetched on mount
 * via the search-all endpoint. Each tab is a block's bootstrap with no saved
 * data (all facets, the corpus default sort, the standard page size;
 * {@see SurfaceBootstrap}), and the year sliders' spans come from one request
 * for every corpus ({@see SearchProxy::yearBoundsFor()}).
 *
 * @see src/svelte/components/FederatedApp.svelte
 * @see src/svelte/main.ts (the [data-dre-federated-root] mount)
 */
class FederatedSearch extends AbstractDreSearchHelper
{
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
        $this->injectBundle(BundleAssets::FEDERATED);

        $surface = SurfaceBootstrap::forView($view);
        $withYears = array_values(array_map(
            static fn(SearchProfile $profile): string => $profile->name(),
            array_filter($this->registry->all(), static fn(SearchProfile $profile): bool => $profile->hasYearFacet()),
        ));
        $years = $withYears === [] ? [] : $this->proxy->yearBoundsFor($withYears);

        $profiles = [];
        foreach ($this->registry->all() as $profile) {
            $corpus = $surface->corpus($profile, new SearchBlockSettings([], $profile), null, $years[$profile->name()] ?? null);
            $profiles[] = $surface->federatedTab($profile, $corpus);
        }
        $default = $this->registry->default();

        $bootstrap = [
            'variant'         => 'federated',
            'available'       => $this->proxy->isAvailable(),
            'item_url_base'   => $surface->itemUrlBase($slug),
            'initial_query'   => $initialQuery,
            'default_profile' => $default !== null ? $default->name() : '',
            'profiles'        => $profiles,
            'endpoints'       => $surface->endpoints(['facet', 'search', 'export', 'search_all', 'union', 'map', 'suggest', 'suggest_all']),
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
}
