<?php
declare(strict_types=1);

namespace DRESearch\View\Helper;

/**
 * Injects the DRE Search Svelte bundle + styles into the page head, returning ''.
 *
 * The theme header's search bar is rendered as layout chrome — i.e. AFTER the
 * layout has already echoed <head> — so a bundle injected from the bar helper
 * would miss the head. The theme therefore calls this helper near the top of
 * layout.phtml (before the head is rendered), guarded by
 *   $this->getHelperPluginManager()->has('dreSearchAssets')
 * so the bundle loads on every site page that shows the header bar. headLink /
 * headScript dedupe by URL, so a page that also carries a search block or the
 * federated results page still loads the bundle exactly once.
 */
class SearchAssets extends AbstractDreSearchHelper
{
    public function __invoke(): string
    {
        if ($this->siteSlug() !== null) {
            $this->injectBundle();
        }
        return '';
    }
}
