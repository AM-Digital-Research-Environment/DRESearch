<?php

declare(strict_types=1);

namespace DRESearch\View;

use Laminas\View\Renderer\PhpRenderer;

/**
 * Puts the Svelte client in the page head — the one place the search block,
 * the header bar and the federated results page load it from.
 *
 * Without hints the browser found the bundle one round trip at a time: the
 * entry, then the shared runtime chunk it imports, then (after the entry ran)
 * the page chunk and its stylesheet. The build manifest names all of them, so
 * the head now announces them up front: `modulepreload` for the entry's static
 * imports on every page, plus the page chunk and a stylesheet `preload` for
 * the surface that will mount.
 *
 * Hashed chunks are preloaded WITHOUT Omeka's `?v=` query: the module graph
 * requests them by the URL the entry resolves (`./chunks/…`, no query), and a
 * preload is only reused when the URL matches exactly. Their names change with
 * their content, so they need no version either. The page stylesheet is a
 * `preload`, not a stylesheet link, to keep it off the critical render path;
 * Vite's loader inserts the stylesheet at mount and the browser reuses the
 * preloaded response (same URL, same CORS mode — hence `crossorigin`).
 */
final class BundleAssets
{
    /** Surface chunks, keyed as in the build manifest (their source paths). */
    public const SEARCH_BLOCK = 'src/svelte/App.svelte';
    public const FEDERATED = 'src/svelte/components/FederatedApp.svelte';
    public const ENTRY = 'src/svelte/main.ts';

    private const MANIFEST = '/asset/dist/manifest.json';

    /**
     * @param string|null         $surface  One of the surface constants, when this page mounts it.
     * @param BundleManifest|null $manifest Defaults to the built asset/dist/manifest.json.
     */
    public static function inject(PhpRenderer $view, ?string $surface = null, ?BundleManifest $manifest = null): void
    {
        $asset = static fn(string $file, bool $versioned = true): string
            => (string) $view->assetUrl($file, 'DRESearch', false, $versioned);
        $headLink = $view->headLink();
        // Skeleton + bar-shell styles: the server-rendered placeholder is above
        // the fold, so these must be present at first paint — keep render-blocking.
        $headLink->appendStylesheet($asset('css/dre-search.css'));
        // The entry stylesheet contains the header UI; page chunks load their own CSS.
        $headLink->appendStylesheet($asset('dist/dre-search.css'));
        $view->headScript()->appendFile($asset('dist/dre-search.js'), 'module', ['defer' => true]);

        $manifest ??= BundleManifest::fromFile(dirname(__DIR__, 2) . self::MANIFEST);
        $modules = $manifest->modules(self::ENTRY, false);
        $styles = [];
        if ($surface !== null) {
            array_push($modules, ...$manifest->modules($surface));
            $styles = $manifest->styles($surface);
        }
        foreach (array_unique($modules) as $file) {
            self::hint($view, ['rel' => 'modulepreload', 'href' => $asset('dist/' . $file, false)]);
        }
        foreach ($styles as $file) {
            // Vite's loader inserts the stylesheet with crossorigin="", and a
            // preload is only reused by a request in the same CORS mode.
            self::hint($view, [
                'rel' => 'preload',
                'as' => 'style',
                'crossorigin' => 'anonymous',
                'href' => $asset('dist/' . $file, false),
            ]);
        }

        ClientStrings::inject($view);
    }

    /**
     * Append a <link> hint once. headLink() dedupes stylesheets by URL, but not
     * arbitrary links, and a page can carry several surfaces.
     *
     * @param array{rel: string, href: string, as?: string, crossorigin?: string} $attributes
     */
    private static function hint(PhpRenderer $view, array $attributes): void
    {
        $headLink = $view->headLink();
        foreach ($headLink->getContainer() as $item) {
            if (($item->rel ?? null) === $attributes['rel'] && ($item->href ?? null) === $attributes['href']) {
                return;
            }
        }
        $headLink($attributes);
    }
}
