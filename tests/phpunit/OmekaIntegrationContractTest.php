<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Controller\SearchController;
use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Site\BlockLayout\ResearchItemsSearchBlock;
use DRESearch\View\ClientStrings;
use PHPUnit\Framework\TestCase;

/** Contracts with Omeka that unit-level code cannot see on its own. */
final class OmekaIntegrationContractTest extends TestCase
{
    /**
     * Every public controller action must be on the anonymous ACL allow-list:
     * a missing entry makes the endpoint fail with PermissionDenied before the
     * action runs (the v1.12.1 histogram outage).
     */
    public function testEveryPublicActionIsOnTheAnonymousAllowList(): void
    {
        require_once dirname(__DIR__, 2) . '/Module.php';
        $actions = [];
        foreach ((new \ReflectionClass(SearchController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === SearchController::class && str_ends_with($method->getName(), 'Action')) {
                $actions[] = substr($method->getName(), 0, -strlen('Action'));
            }
        }
        sort($actions);
        $allowed = \DRESearch\Module::PUBLIC_ACTIONS;
        sort($allowed);
        self::assertSame($actions, $allowed);

        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $routed = [];
        array_walk_recursive($config['router']['routes'], static function ($value, $key) use (&$routed): void {
            if ($key === 'action') {
                $routed[] = $value;
            }
        });
        foreach ($actions as $action) {
            self::assertContains($action, $routed, "Action {$action} has no route.");
        }
    }

    public function testBlockDataIsValidatedAndNormalisedOnSave(): void
    {
        if (!class_exists(\Omeka\Entity\SitePageBlock::class)) {
            self::markTestSkipped('Requires Omeka core.');
        }
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $registry = ProfileRegistry::fromArray($config['dre_search']['profiles']);
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter(new \Laminas\Log\Writer\Noop());
        $proxy = new SearchProxy(
            new TypesenseClientProvider('', 0, 'http', ''),
            $registry,
            new BlockScopeResolver(\Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])),
            $logger,
        );
        $layout = new ResearchItemsSearchBlock($proxy, $registry);

        $block = new \Omeka\Entity\SitePageBlock();
        $block->setData([
            'locked_filter' => 'project_s:=`A`) || (type_s:=`B`',
            'results_per_page' => 500,
            'default_sort' => 'bogus',
            'facets' => ['type_s', 'not_a_facet'],
        ]);
        $errors = new \Omeka\Stdlib\ErrorStore();
        $layout->onHydrate($block, $errors);
        self::assertTrue($errors->hasErrors(), 'An escaping locked filter is refused at save time.');
        $data = $block->getData();
        self::assertSame(50, $data['results_per_page']);
        self::assertContains($data['default_sort'], $registry->get('research_items')->sortOptionValues());
        self::assertSame(['type_s'], $data['facets']);

        $ok = new \Omeka\Entity\SitePageBlock();
        $ok->setData(['locked_filter' => ' project_s:=`A` ']);
        $clean = new \Omeka\Stdlib\ErrorStore();
        $layout->onHydrate($ok, $clean);
        self::assertFalse($clean->hasErrors());
        self::assertSame('project_s:=`A`', $ok->getData()['locked_filter']);
    }

    public function testClientStringsAreInjectedOnlyWhereTheyAreTranslated(): void
    {
        $table = ClientStrings::table();
        self::assertArrayHasKey('search_results', $table);
        self::assertSame('Search results', $table['search_results']);

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $view->getHelperPluginManager()->setService('translate', new class {
            public function __invoke(string $s): string
            {
                return $s === 'Search results' ? 'Résultats de recherche' : $s;
            }
        });
        ClientStrings::reset();
        ClientStrings::inject($view);
        $script = (string) $view->headScript();
        self::assertStringContainsString('window.dreSearchTranslations', $script);
        self::assertStringContainsString('"search_results":"Résultats de recherche"', $script);
        self::assertStringNotContainsString('"filters"', $script, 'Untranslated strings are not shipped.');
        ClientStrings::reset();
    }
}
