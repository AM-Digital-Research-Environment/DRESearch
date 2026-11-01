<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Service\BlockLayout\SearchBlockFactory;
use DRESearch\Settings\BlockProfiles;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Site\BlockLayout\SearchBlock;
use DRESearch\View\Helper\DreBootstrapJson;
use Laminas\ServiceManager\ServiceManager;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The page-block layouts: one class, one entry per stored layout name. */
final class SearchBlockTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(SitePageBlockRepresentation::class)) {
            self::markTestSkipped('Requires Omeka core.');
        }
    }

    /**
     * Omeka lists in the block picker only the names configured under
     * block_layouts, and pages store those names: the configuration and
     * BlockProfiles must name the same layouts, every one with the factory.
     */
    public function testTheConfiguredLayoutsAreExactlyTheRegisteredOnes(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $factories = $config['block_layouts']['factories'];
        $configured = array_keys($factories);
        sort($configured);
        $registered = BlockProfiles::names();
        sort($registered);
        self::assertSame($registered, $configured);
        self::assertSame([SearchBlockFactory::class], array_values(array_unique($factories)));
    }

    /**
     * The names editors' pages already store. Renaming one would orphan
     * every block saved under it (Omeka renders an unknown layout as a
     * placeholder), so the list is pinned here.
     */
    public function testStoredLayoutNamesNeverChange(): void
    {
        self::assertSame([
            'dreSearch' => 'research_items',
            'dreSearchProjects' => 'research_projects',
            'dreSearchPublications' => 'research_publications',
            'dreSearchPodcasts' => 'research_podcasts',
            'dreSearchVideos' => 'research_videos',
            'dreSearchPeople' => 'research_people',
            'dreSearchSections' => 'research_sections',
            'dreSearchOrganisations' => 'research_organisations',
            'dreSearchGenres' => 'research_genres',
            'dreSearchLanguages' => 'research_languages',
            'dreSearchLocations' => 'research_locations',
            'dreSearchSubjects' => 'research_subjects',
        ], array_map(static fn(array $layout): string => $layout['profile'], BlockProfiles::LAYOUTS));
    }

    /** @return iterable<string,array{string}> */
    public static function layouts(): iterable
    {
        foreach (BlockProfiles::names() as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('layouts')]
    public function testEveryRegisteredLayoutMapsToAProfileAndRenders(string $name): void
    {
        $registry = $this->registry();
        $profileName = BlockProfiles::profileFor($name);
        self::assertNotNull($profileName);
        $profile = $registry->get($profileName);
        self::assertNotNull($profile, "{$name} searches a shipped profile.");

        $layout = $this->factory($registry)($name);
        self::assertSame(BlockProfiles::labelFor($name), $layout->getLabel());

        $html = (string) $layout->render($this->view(), $this->block(42, ['title' => 'Find']));
        self::assertStringContainsString('data-dre-search-root', $html);
        self::assertStringContainsString('data-dre-block-id="42"', $html);
        preg_match('#<script type="application/json" id="dre-search-state-42">(.*?)</script>#s', $html, $match);
        $bootstrap = json_decode($match[1] ?? '', true);
        self::assertIsArray($bootstrap);
        self::assertSame($profileName, $bootstrap['profile']);
        self::assertSame($profile->kind(), $bootstrap['card_kind']);
        self::assertSame(42, $bootstrap['block_id']);
        self::assertFalse($bootstrap['initial_response']['available'], 'Typesense is unconfigured here.');
    }

    /** A saved block lends its locked filter only to the profile its layout searches. */
    public function testABlockScopeBelongsToTheProfileOfItsLayout(): void
    {
        $db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE site_page_block (id INTEGER PRIMARY KEY, layout TEXT, data TEXT)');
        $db->executeStatement("INSERT INTO site_page_block VALUES (7, 'dreSearchProjects', '{\"locked_filter\":\"section_ss:=`Mobilities`\"}')");
        $db->executeStatement("INSERT INTO site_page_block VALUES (8, 'html', '{\"locked_filter\":\"section_ss:=`Mobilities`\"}')");
        $resolver = new BlockScopeResolver($db);

        self::assertSame('section_ss:=`Mobilities`', $resolver->resolve(7, 'research_projects'));
        foreach ([[7, 'research_items'], [8, 'research_projects']] as [$id, $profile]) {
            try {
                $resolver->resolve($id, $profile);
                self::fail("Block {$id} lent its scope to {$profile}.");
            } catch (\DRESearch\Search\Exception\RequestValidationException $e) {
                self::assertSame('invalid_block_scope', $e->publicCode());
            }
        }
    }

    /**
     * A link into the block's own state (a shared search, a filter chip, page
     * 2) makes the client fetch that state and discard the default first page,
     * so the server does not render one. Another block's state, or the host
     * page's own parameters, leave this block's first page in place.
     */
    public function testTheFirstPageIsSkippedWhenTheUrlCarriesTheBlocksOwnState(): void
    {
        $registry = $this->registry();
        $render = function (string $query) use ($registry): array {
            $proxy = $this->services($registry, 'dreSearch')->get(SearchProxy::class);
            self::assertInstanceOf(SearchProxy::class, $proxy);
            $layout = new SearchBlock($proxy, $registry, 'dreSearch', static fn(): string => $query);
            $html = (string) $layout->render($this->view(), $this->block(42, []));
            preg_match('#<script type="application/json" id="dre-search-state-42">(.*?)</script>#s', $html, $match);
            $bootstrap = json_decode($match[1] ?? '', true);
            self::assertIsArray($bootstrap);
            return $bootstrap;
        };

        self::assertArrayNotHasKey('initial_response', $render('b42.f.type_s=Text&b42.page=2'));
        self::assertArrayNotHasKey('initial_response', $render('b42.q=kenya'));
        self::assertArrayHasKey('initial_response', $render('b7.q=kenya&page=3'));
        self::assertArrayHasKey('initial_response', $render('b42.view=gallery'));
    }

    public function testAnUnknownLayoutNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->factory($this->registry())('dreSearchNothing');
    }

    private function registry(): ProfileRegistry
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        return ProfileRegistry::fromArray($config['dre_search']['profiles']);
    }

    /**
     * The factory as Omeka's block-layout manager calls it, each layout over a
     * database holding block 42 saved under that layout's name.
     *
     * @return \Closure(string):SearchBlock
     */
    private function factory(ProfileRegistry $registry): \Closure
    {
        return function (string $name) use ($registry): SearchBlock {
            return (new SearchBlockFactory())($this->services($registry, $name), $name);
        };
    }

    /** The module services a block needs, over a database holding block 42 saved as `$layout`. */
    private function services(ProfileRegistry $registry, string $layout): ServiceManager
    {
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter(new \Laminas\Log\Writer\Noop());
        $db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE site_page_block (id INTEGER PRIMARY KEY, layout TEXT, data TEXT)');
        $db->executeStatement("INSERT INTO site_page_block (id, layout, data) VALUES (42, ?, '{}')", [$layout]);
        $services = new ServiceManager();
        $services->setService(ProfileRegistry::class, $registry);
        $services->setService(SearchProxy::class, new SearchProxy(
            new TypesenseClientProvider('', 0, 'http', ''),
            $registry,
            new BlockScopeResolver($db),
            $logger,
        ));
        return $services;
    }

    /** @param array<string,mixed> $data */
    private function block(int $id, array $data): SitePageBlockRepresentation
    {
        return new class ($id, $data) extends SitePageBlockRepresentation {
            /** @param array<string,mixed> $blockData */
            public function __construct(private readonly int $blockId, private readonly array $blockData)
            {
            }

            public function id()
            {
                return $this->blockId;
            }

            public function data()
            {
                return $this->blockData;
            }

            public function page()
            {
                return new class extends \Omeka\Api\Representation\SitePageRepresentation {
                    public function __construct()
                    {
                    }

                    public function site()
                    {
                        return new class extends \Omeka\Api\Representation\SiteRepresentation {
                            public function __construct()
                            {
                            }

                            public function slug(): string
                            {
                                return 'amira';
                            }
                        };
                    }
                };
            }
        };
    }

    private function view(): PhpRenderer
    {
        $view = new PhpRenderer();
        $view->setResolver(new \Laminas\View\Resolver\TemplatePathStack([
            'script_paths' => [dirname(__DIR__, 2) . '/view'],
        ]));
        $helpers = $view->getHelperPluginManager();
        $helpers->setService('translate', new class {
            public function __invoke(string $s): string
            {
                return $s;
            }
        });
        $helpers->setService('dreBootstrapJson', new DreBootstrapJson());
        $basePath = $helpers->get('basePath');
        self::assertInstanceOf(\Laminas\View\Helper\BasePath::class, $basePath);
        $basePath->setBasePath('/omeka');
        return $view;
    }
}
