<?php

declare(strict_types=1);

namespace DRESearch\View\Helper;

use DRESearch\View\BundleAssets;
use Laminas\View\Helper\AbstractHelper;

/**
 * Shared plumbing for the module's two site-wide search surfaces — the header
 * search bar ({@see SearchBar}) and the federated results page
 * ({@see FederatedSearch}). Both inject the same compiled Svelte bundle and need
 * the current site slug to build resource / endpoint URLs.
 */
abstract class AbstractDreSearchHelper extends AbstractHelper
{
    /** @return \Laminas\View\Renderer\PhpRenderer */
    public function getView()
    {
        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = parent::getView();
        return $view;
    }

    /**
     * Inject the compiled Svelte bundle + styles. headLink/headScript dedupe by
     * URL, so a page that also carries a search *block* loads the bundle once.
     *
     * @param string|null $surface The page chunk this surface mounts, to preload
     *                             ({@see BundleAssets}); null for the header bar,
     *                             which mounts from the entry itself.
     */
    protected function injectBundle(?string $surface = null): void
    {
        BundleAssets::inject($this->getView(), $surface);
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
