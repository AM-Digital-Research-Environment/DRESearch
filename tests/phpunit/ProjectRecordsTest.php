<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use DRESearch\Site\BlockLayout\SearchBlockSettings;
use DRESearch\Site\ResourcePageBlockLayout\ProjectRecords;
use DRESearch\View\Helper\DreBootstrapJson;
use Laminas\View\Renderer\PhpRenderer;
use PHPUnit\Framework\TestCase;

/** The item-page "Project records (DRE Search)" block: when it renders, and what it mounts. */
final class ProjectRecordsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(\Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface::class)) {
            self::markTestSkipped('Requires Omeka core.');
        }
    }

    public function testItIsAnItemPageBlock(): void
    {
        $block = $this->block();
        self::assertSame(['items'], $block->getCompatibleResourceNames());
        self::assertSame('Project records (DRE Search)', $block->getLabel());
    }

    public function testItRendersNothingOnAnItemThatIsNotAProject(): void
    {
        $counted = 0;
        $count = function () use (&$counted): int {
            $counted++;
            return 487;
        };
        $html = $this->block()->renderProject($this->view(), 2000, 'dctype:Text', [1, 21], $count);
        self::assertSame('', $html);
        self::assertSame(0, $counted, 'No records query for an item that is not a project.');
    }

    public function testItRendersNothingForAProjectWithoutRecords(): void
    {
        $counted = 0;
        $count = function (SearchProfile $profile) use (&$counted): int {
            $counted++;
            self::assertSame('research_items', $profile->name());
            return 0;
        };
        self::assertSame('', $this->block()->renderProject($this->view(), 1797, 'foaf:Project', [20], $count));
        self::assertSame(1, $counted);
    }

    public function testItRendersNothingWhileSearchIsUnavailable(): void
    {
        // Unconfigured Typesense: the page keeps Omeka's own Linked resources.
        $html = $this->block()->renderProject($this->view(), 1797, 'foaf:Project', [20], static fn(): int => 487);
        self::assertSame('', $html);
        $html = $this->block()->renderProject($this->view(), 1797, null, [20], static fn(): int => 487);
        self::assertSame('', $html, 'Membership of the project item set qualifies the same way.');
    }

    public function testItRendersNothingWithoutTheResearchItemsCorpus(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $profiles = $config['dre_search']['profiles'];
        unset($profiles['research_items']);
        $block = $this->block(ProfileRegistry::fromArray($profiles));
        self::assertSame('', $block->renderProject($this->view(), 1797, 'foaf:Project', [20], static fn(): int => 487));
    }

    public function testTheBootstrapIsTheResearchItemsBlockLockedToTheProject(): void
    {
        $registry = $this->registry();
        $profile = $registry->get('research_items');
        self::assertNotNull($profile);
        $settings = new SearchBlockSettings([], $profile);
        $facets = array_values(array_diff($settings->facets(), ['project_s']));
        $initial = ['available' => true, 'found' => 487, 'page' => 1, 'hits' => [], 'facets' => []];

        $bootstrap = $this->block($registry)->bootstrap($this->view(), $profile, 1797, $settings, $facets, $initial);

        self::assertNull($bootstrap['block_id']);
        self::assertSame(1797, $bootstrap['scope_item']);
        self::assertSame('research_items', $bootstrap['profile']);
        self::assertSame('item', $bootstrap['card_kind']);
        self::assertNotContains('project_s', $bootstrap['facets'], 'The locked Project facet is hidden.');
        self::assertContains('type_s', $bootstrap['facets']);
        self::assertSame($profile->defaultSort(), $bootstrap['default_sort']);
        self::assertSame($settings->perPage(), $bootstrap['per_page']);
        self::assertSame('/omeka/dre-search/api/search', $bootstrap['endpoints']['search']);
        self::assertSame('/omeka/s/amira/item', $bootstrap['item_url_base']);
        self::assertSame($initial, $bootstrap['initial_response']);
        self::assertArrayNotHasKey('project_s', $bootstrap['facet_labels']);
    }

    public function testTheTemplateKeepsTheMountContract(): void
    {
        $view = $this->view();
        $view->setResolver(new \Laminas\View\Resolver\TemplatePathStack([
            'script_paths' => [dirname(__DIR__, 2) . '/view'],
        ]));
        $html = (string) $view->render(ProjectRecords::TEMPLATE, [
            'itemId' => 1797,
            'found' => 487,
            'bootstrap' => ['scope_item' => 1797, 'block_id' => null, 'profile' => 'research_items', 'title' => '</script><b>'],
        ]);

        self::assertStringContainsString('class="dre-search-project-records dre-search-block"', $html);
        self::assertStringContainsString('data-dre-search-root', $html);
        self::assertStringContainsString('data-dre-block-id="r1797"', $html);
        self::assertStringContainsString('data-dre-heading-level="3"', $html);
        self::assertStringContainsString('<script type="application/json" id="dre-search-state-r1797">', $html);
        self::assertStringContainsString('Records of this project', $html);
        self::assertStringContainsString('<span class="dre-search-project-records__count">487</span>', $html);
        self::assertStringNotContainsString('</script><b>', $html, 'The bootstrap cannot close its script.');
        preg_match('#<script type="application/json" id="dre-search-state-r1797">(.*?)</script>#s', $html, $match);
        self::assertSame(1797, json_decode($match[1] ?? '', true)['scope_item'] ?? null);
    }

    private function registry(): ProfileRegistry
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        return ProfileRegistry::fromArray($config['dre_search']['profiles']);
    }

    private function block(?ProfileRegistry $registry = null): ProjectRecords
    {
        $registry ??= $this->registry();
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter(new \Laminas\Log\Writer\Noop());
        $proxy = new SearchProxy(
            new TypesenseClientProvider('', 0, 'http', ''),
            $registry,
            new BlockScopeResolver(\Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])),
            $logger,
        );
        return new ProjectRecords($proxy, $registry);
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
        $helpers->setService('currentSite', new class {
            public function __invoke(): object
            {
                return new class {
                    public function slug(): string
                    {
                        return 'amira';
                    }
                };
            }
        });
        $helpers->setService('dreBootstrapJson', new DreBootstrapJson());
        $basePath = $helpers->get('basePath');
        self::assertInstanceOf(\Laminas\View\Helper\BasePath::class, $basePath);
        $basePath->setBasePath('/omeka');
        return $view;
    }
}
