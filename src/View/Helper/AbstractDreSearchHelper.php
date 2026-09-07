<?php
declare(strict_types=1);

namespace DRESearch\View\Helper;

use Laminas\View\Helper\AbstractHelper;

/**
 * Shared plumbing for the module's two site-wide search surfaces — the header
 * search bar ({@see SearchBar}) and the federated results page
 * ({@see FederatedSearch}). Both inject the same compiled Svelte bundle and need
 * the current site slug to build resource / endpoint URLs.
 */
abstract class AbstractDreSearchHelper extends AbstractHelper
{
    /**
     * Inject the compiled Svelte bundle + styles. headLink/headScript dedupe by
     * URL, so a page that also carries a search *block* loads the bundle once.
     */
    protected function injectBundle(): void
    {
        $view = $this->getView();
        // Skeleton + bar-shell styles: the server-rendered placeholder is above
        // the fold, so these must be present at first paint — keep render-blocking.
        $view->headLink()->appendStylesheet($view->assetUrl('css/dre-search.css', 'DRESearch'));
        // The entry stylesheet contains the header UI; page chunks load their own CSS.
        $view->headLink()->appendStylesheet($view->assetUrl('dist/dre-search.css', 'DRESearch'));
        $view->headScript()->appendFile(
            $view->assetUrl('dist/dre-search.js', 'DRESearch'),
            'module',
            ['defer' => true]
        );
    }

    /**
     * Current public site slug, or null when not on a site route (the helpers
     * then render nothing, so the theme can fall back gracefully).
     */
    protected function siteSlug(): ?string
    {
        $site = $this->getView()->currentSite();
        return $site !== null ? $site->slug() : null;
    }
}
