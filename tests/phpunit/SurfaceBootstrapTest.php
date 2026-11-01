<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Service\View\Helper\SearchBarFactory;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Site\BlockLayout\SearchBlockSettings;
use DRESearch\View\BundleManifest;
use DRESearch\View\ClientStrings;
use DRESearch\View\Helper\DreBootstrapJson;
use DRESearch\View\Helper\FederatedSearch;
use DRESearch\View\SurfaceBootstrap;
use Laminas\ServiceManager\ServiceManager;
use Laminas\View\Renderer\PhpRenderer;
use PHPUnit\Framework\TestCase;

/** One bootstrap builder for the blocks, the project records and the federated tabs. */
final class SurfaceBootstrapTest extends TestCase
{
    use SiteViewFixture;

    protected function tearDown(): void
    {
        BundleManifest::reset();
        ClientStrings::reset();
    }

    public function testABlockBootstrapKeepsTheClientShape(): void
    {
        $profile = $this->registry()->get('research_projects');
        self::assertNotNull($profile);
        $settings = new SearchBlockSettings(['facets' => ['section_ss'], 'results_per_page' => 7, 'default_sort' => 'bogus'], $profile);
        $surface = new SurfaceBootstrap(static fn(string $s): string => '[' . $s . ']', static fn(string $p): string => '/omeka' . $p, true);

        $bootstrap = $surface->embedded(
            $surface->corpus($profile, $settings, null, ['min' => 2019, 'max' => 2026]),
            'amira',
            42,
        );

        self::assertSame([
            'block_id', 'profile', 'card_kind', 'search_placeholder', 'date_mode', 'show_year', 'year_bounds',
            'facets', 'facet_labels', 'default_sort', 'sort_options', 'per_page', 'item_url_base', 'endpoints',
        ], array_keys($bootstrap));
        self::assertSame(42, $bootstrap['block_id']);
        self::assertSame('research_projects', $bootstrap['profile']);
        self::assertSame('project', $bootstrap['card_kind']);
        self::assertSame('range', $bootstrap['date_mode']);
        self::assertSame(['min' => 2019, 'max' => 2026], $bootstrap['year_bounds']);
        self::assertSame(['section_ss'], $bootstrap['facets']);
        self::assertSame(['section_ss' => '[Research section]'], $bootstrap['facet_labels']);
        self::assertSame($profile->defaultSort(), $bootstrap['default_sort'], 'A stale saved sort never reaches the client.');
        self::assertSame(7, $bootstrap['per_page']);
        self::assertSame('/omeka/s/amira/item', $bootstrap['item_url_base']);
        self::assertSame([
            'facet' => '/omeka/dre-search/api/facet',
            'search' => '/omeka/dre-search/api/search',
            'export' => '/omeka/dre-search/api/export',
            'suggest' => '/omeka/dre-search/api/suggest',
            'map' => '/omeka/dre-search/api/map',
            'popular' => '/omeka/dre-search/api/popular',
        ], $bootstrap['endpoints']);
    }

    public function testWithoutTheYearFacetTheSliderGetsNoBounds(): void
    {
        $profile = $this->registry()->get('research_projects');
        self::assertNotNull($profile);
        $surface = new SurfaceBootstrap(static fn(string $s): string => $s, static fn(string $p): string => $p);
        $corpus = $surface->corpus($profile, new SearchBlockSettings(['show_year' => '0'], $profile), null, ['min' => 1, 'max' => 2]);
        self::assertFalse($corpus['show_year']);
        self::assertNull($corpus['year_bounds']);
        self::assertArrayNotHasKey('popular', $surface->embedded($corpus, 'amira', 1)['endpoints']);
    }

    /**
     * The federated page: one tab per corpus with a block's defaults, in the
     * ProfileMeta names, and the year spans from one request for all corpora.
     */
    public function testTheFederatedPageBuildsEveryTabFromTheSameBuilder(): void
    {
        $registry = $this->registry();
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter(new \Laminas\Log\Writer\Noop());
        $proxy = new SearchProxy(
            new TypesenseClientProvider('', 0, 'http', ''),
            $registry,
            new BlockScopeResolver(\Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])),
            $logger,
        );
        $helper = new FederatedSearch($proxy, $registry);
        $helper->setView($this->view());

        $html = $helper('kenya');
        preg_match('#<script type="application/json" id="dre-federated-state-main">(.*?)</script>#s', $html, $match);
        $bootstrap = json_decode($match[1] ?? '', true);
        self::assertIsArray($bootstrap);
        self::assertSame('kenya', $bootstrap['initial_query']);
        self::assertSame('research_items', $bootstrap['default_profile']);
        self::assertSame(['facet', 'search', 'export', 'search_all', 'union', 'map', 'suggest', 'suggest_all'], array_keys($bootstrap['endpoints']));
        self::assertCount(count($registry->all()), $bootstrap['profiles']);
        $tab = $bootstrap['profiles'][0];
        self::assertSame([
            'name', 'label', 'kind', 'date_mode', 'show_year', 'year_bounds', 'facets', 'facet_labels',
            'default_sort', 'sort_options', 'per_page', 'placeholder',
        ], array_keys($tab));
        $items = $registry->get('research_items');
        self::assertNotNull($items);
        self::assertSame($items->fieldNames(), $tab['facets'], 'Every facet, as a block with no saved data.');
        self::assertSame(20, $tab['per_page']);
        self::assertNull($tab['year_bounds'], 'Typesense is unconfigured here.');
        $genres = array_values(array_filter($bootstrap['profiles'], static fn(array $p): bool => $p['name'] === 'research_genres'))[0];
        self::assertSame('Search genres…', $genres['placeholder']);
    }

    /** Without JavaScript, the results page hands its query to Omeka's item browse. */
    public function testTheFederatedPageCarriesItsQueryToTheNoscriptBrowse(): void
    {
        $view = $this->view();
        $view->setResolver(new \Laminas\View\Resolver\TemplatePathStack([
            'script_paths' => [dirname(__DIR__, 2) . '/view'],
        ]));
        $view->getHelperPluginManager()->setService('dreFederatedSearch', new class {
            public function __invoke(string $query): string
            {
                return '<div data-dre-federated-root></div>';
            }
        });
        $html = html_entity_decode((string) $view->render('dre-search/federated', ['query' => "Côte d'Ivoire"]), ENT_QUOTES | ENT_HTML5);
        self::assertStringContainsString(
            '<a href="/omeka/s/amira/item/browse?fulltext_search=C%C3%B4te+d%27Ivoire">Browse the items instead.</a>',
            $html,
        );
        $html = html_entity_decode((string) $view->render('dre-search/federated', ['query' => '']), ENT_QUOTES | ENT_HTML5);
        self::assertStringContainsString('<a href="/omeka/s/amira/item/browse">', $html);
    }

    /** The header bar renders on every page; it needs only the connection settings. */
    public function testTheHeaderBarIsBuiltWithoutTheSearchProxy(): void
    {
        $services = new ServiceManager();
        $services->setService(TypesenseClientProvider::class, new TypesenseClientProvider('typesense', 8108, 'http', 'k'));
        $bar = (new SearchBarFactory())($services, 'dreSearchBar');
        $bar->setView($this->view());

        $html = $bar('desktop');
        preg_match('#<script type="application/json" id="dre-search-bar-state-desktop">(.*?)</script>#s', $html, $match);
        $bootstrap = json_decode($match[1] ?? '', true);
        self::assertIsArray($bootstrap);
        self::assertTrue($bootstrap['available']);
        self::assertSame(['suggest_all' => '/omeka/dre-search/api/suggest-all'], $bootstrap['endpoints']);
        self::assertSame('/omeka/s/amira/item', $bootstrap['item_url_base']);
        self::assertFalse($services->has(SearchProxy::class));
    }

    private function registry(): ProfileRegistry
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        return ProfileRegistry::fromArray($config['dre_search']['profiles']);
    }

    private function view(): PhpRenderer
    {
        $view = new PhpRenderer();
        $helpers = $view->getHelperPluginManager();
        $helpers->setService('translate', new class {
            public function __invoke(string $s): string
            {
                return $s;
            }
        });
        $helpers->setService('assetUrl', new class {
            public function __invoke(string $file, ?string $module = null, bool $override = false, bool $versioned = true): string
            {
                return '/modules/' . $module . '/asset/' . $file;
            }
        });
        $this->stubSiteHelpers($view);
        $helpers->setService('dreBootstrapJson', new DreBootstrapJson());
        $basePath = $helpers->get('basePath');
        self::assertInstanceOf(\Laminas\View\Helper\BasePath::class, $basePath);
        $basePath->setBasePath('/omeka');
        BundleManifest::reset();
        return $view;
    }
}
