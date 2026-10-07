<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\View\BundleAssets;
use DRESearch\View\BundleManifest;
use DRESearch\View\ClientStrings;
use PHPUnit\Framework\TestCase;

final class BundleAssetsTest extends TestCase
{
    /** The shape Vite writes: an entry, two lazy page chunks and a shared runtime chunk. */
    private const MANIFEST = [
        '_i18n-A.js' => ['file' => 'chunks/i18n-A.js', 'name' => 'i18n'],
        'src/svelte/App.svelte' => [
            'file' => 'chunks/App-B.js',
            'isDynamicEntry' => true,
            'imports' => ['_i18n-A.js'],
            'css' => ['chunks/App-C.css'],
        ],
        'src/svelte/components/FederatedApp.svelte' => [
            'file' => 'chunks/FederatedApp-D.js',
            'isDynamicEntry' => true,
            'imports' => ['_i18n-A.js', 'src/svelte/App.svelte'],
            'css' => ['chunks/FederatedApp-E.css'],
        ],
        'src/svelte/main.ts' => [
            'file' => 'dre-search.js',
            'isEntry' => true,
            'imports' => ['_i18n-A.js'],
            'dynamicImports' => ['src/svelte/App.svelte', 'src/svelte/components/FederatedApp.svelte'],
            'css' => ['dre-search.css'],
        ],
    ];

    protected function tearDown(): void
    {
        BundleManifest::reset();
        ClientStrings::reset();
    }

    public function testWalksStaticImportsTransitively(): void
    {
        $manifest = BundleManifest::fromArray(self::MANIFEST);

        self::assertSame(['chunks/i18n-A.js'], $manifest->modules(BundleAssets::ENTRY, false));
        self::assertSame(['chunks/App-B.js', 'chunks/i18n-A.js'], $manifest->modules(BundleAssets::SEARCH_BLOCK));
        self::assertSame(
            ['chunks/FederatedApp-D.js', 'chunks/i18n-A.js', 'chunks/App-B.js'],
            $manifest->modules(BundleAssets::FEDERATED),
            'A page chunk brings the chunks it imports; dynamic imports are not followed.',
        );
        self::assertSame(['chunks/FederatedApp-E.css', 'chunks/App-C.css'], $manifest->styles(BundleAssets::FEDERATED));
    }

    public function testToleratesMissingMalformedAndCyclicManifests(): void
    {
        self::assertSame([], BundleManifest::fromFile(__DIR__ . '/no-such-manifest.json')->modules(BundleAssets::ENTRY));

        $cyclic = BundleManifest::fromArray([
            'a' => ['file' => 'a.js', 'imports' => ['b']],
            'b' => ['file' => 'b.js', 'imports' => ['a', 'missing']],
            'junk' => 'not a chunk',
        ]);
        self::assertSame(['a.js', 'b.js'], $cyclic->modules('a'));
        self::assertFalse($cyclic->has('junk'));
    }

    public function testHeadPreloadsTheSurfaceChunksOnceAndUnversioned(): void
    {
        $view = $this->view();
        $manifest = BundleManifest::fromArray(self::MANIFEST);
        BundleAssets::inject($view, BundleAssets::SEARCH_BLOCK, $manifest);
        // A second block on the same page, and the theme's header-bar call.
        BundleAssets::inject($view, BundleAssets::SEARCH_BLOCK, $manifest);
        BundleAssets::inject($view, null, $manifest);

        $links = $this->html($view->headLink());
        foreach (['chunks/i18n-A.js', 'chunks/App-B.js'] as $file) {
            self::assertSame(
                1,
                substr_count($links, '<link href="/modules/DRESearch/asset/dist/' . $file . '" rel="modulepreload">'),
                $file,
            );
        }
        self::assertSame(
            1,
            substr_count($links, '<link as="style" crossorigin="anonymous" href="/modules/DRESearch/asset/dist/chunks/App-C.css" rel="preload">'),
        );
        self::assertStringNotContainsString('FederatedApp', $links);
        self::assertSame(1, substr_count($links, 'dist/dre-search.css?v=1'), 'The entry stylesheet stays versioned and render-blocking.');
        self::assertSame(1, substr_count($this->html($view->headScript()), 'dist/dre-search.js?v=1'));
    }

    public function testTheHeaderBarPreloadsOnlyWhatTheEntryImports(): void
    {
        $view = $this->view();
        BundleAssets::inject($view, null, BundleManifest::fromArray(self::MANIFEST));
        $links = $this->html($view->headLink());
        self::assertStringContainsString('dist/chunks/i18n-A.js" rel="modulepreload"', $links);
        self::assertStringNotContainsString('App-B', $links, 'Pages without a search block do not fetch its chunk.');
        self::assertStringNotContainsString('rel="preload"', $links);
    }

    public function testWithoutAManifestTheBundleStillLoads(): void
    {
        $view = $this->view();
        BundleAssets::inject($view, BundleAssets::SEARCH_BLOCK, BundleManifest::fromArray([]));
        self::assertStringNotContainsString('modulepreload', $this->html($view->headLink()));
        self::assertStringContainsString('dist/dre-search.js?v=1', $this->html($view->headScript()));
    }

    /** The head as a browser reads it (the helpers entity-encode attribute values). */
    private function html(\Stringable $helper): string
    {
        return html_entity_decode((string) $helper, ENT_QUOTES | ENT_HTML5);
    }

    private function view(): \Laminas\View\Renderer\PhpRenderer
    {
        $view = new \Laminas\View\Renderer\PhpRenderer();
        $plugins = $view->getHelperPluginManager();
        $plugins->setService('assetUrl', new class {
            public function __invoke(string $file, ?string $module = null, bool $override = false, bool $versioned = true): string
            {
                return '/modules/' . $module . '/asset/' . $file . ($versioned ? '?v=1' : '');
            }
        });
        $plugins->setService('translate', new class {
            public function __invoke(string $s): string
            {
                return $s;
            }
        });
        return $view;
    }
}
