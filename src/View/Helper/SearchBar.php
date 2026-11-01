<?php

declare(strict_types=1);

namespace DRESearch\View\Helper;

use DRESearch\View\SurfaceBootstrap;

/**
 * Renders the theme header's federated search bar: a mount point + a compact
 * bootstrap the Svelte client reads to build the autocomplete (suggestions across
 * every corpus, each tagged with its type) and to know where to send a full
 * search (the federated results page).
 *
 * Called from the theme header, guarded by
 *   $this->getHelperPluginManager()->has('dreSearchBar')
 * so the theme falls back to its core search form when the module is absent.
 * Returns '' off a site route. Renders even when Typesense is unconfigured — the
 * box still works and still navigates to the results page (which shows its own
 * "unavailable" notice); only the live suggestions go quiet.
 *
 * It renders on every page of the site, so it is given only what it reads —
 * whether Typesense is configured — rather than the search proxy and the
 * profile registry behind it ({@see \DRESearch\Service\View\Helper\SearchBarFactory}).
 *
 * @see view/common/block-layout/dre-search-block.phtml for the sibling mount contract
 * @see src/svelte/main.ts (the [data-dre-search-bar] mount)
 */
class SearchBar extends AbstractDreSearchHelper
{
    public function __construct(private readonly bool $available)
    {
    }

    /**
     * @param string $id         unique mount id (mobile vs desktop instances differ)
     * @param string $class      extra classes for the mount node (e.g. responsive utilities)
     * @param bool   $collapsible render as a magnifier button that expands the input (mobile)
     */
    public function __invoke(string $id, string $class = '', bool $collapsible = false): string
    {
        $view = $this->getView();
        $slug = $this->siteSlug();
        if ($slug === null) {
            return '';
        }
        $this->injectBundle();

        $resultsUrl = $view->url('site/dre-search', ['site-slug' => $slug]);

        $surface = SurfaceBootstrap::forView($view);
        $bootstrap = [
            'variant'       => 'bar',
            'available'     => $this->available,
            // Client builds suggestion links as `${item_url_base}/${id}`.
            'item_url_base' => $surface->itemUrlBase($slug),
            'results_url'   => $resultsUrl,
            'placeholder'   => (string) $view->translate('Search…'), // @translate
            'collapsible'   => $collapsible,
            'endpoints'     => $surface->endpoints(['suggest_all']),
        ];

        $escId      = $view->escapeHtmlAttr($id);
        $escClass   = $view->escapeHtmlAttr(trim('main-header__search-bar ' . $class));
        $escState   = $view->escapeHtmlAttr('dre-search-bar-state-' . $id);
        $escResults = $view->escapeHtmlAttr($resultsUrl);
        $noscript   = $view->escapeHtml((string) $view->translate('Search'));
        $json       = $view->dreBootstrapJson($bootstrap);

        // The mount node intentionally uses its OWN classes/attrs (not the theme's
        // .main-search-button / .main-header-search), so the theme's existing
        // header-search toggle in script.js leaves it alone.
        return <<<HTML
        <div class="{$escClass}" data-dre-search-bar data-dre-bar-id="{$escId}">
            <div class="dre-search-bar__skeleton" aria-hidden="true"></div>
            <noscript><a href="{$escResults}">{$noscript}</a></noscript>
        </div>
        <script type="application/json" id="{$escState}">{$json}</script>
        HTML;
    }
}
