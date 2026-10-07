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
     * The PHP minimum is stated in several places, and they must agree: the
     * release ships dependencies resolved for config.platform, while Module.php
     * decides below which version to skip the vendor autoloader, whose
     * platform check would otherwise fail every request on the site.
     */
    public function testThePhpMinimumAgreesEverywhere(): void
    {
        require_once dirname(__DIR__, 2) . '/Module.php';
        $root = dirname(__DIR__, 2);
        $min = \DRESearch\MIN_PHP_VERSION;
        [$major, $minor] = array_map('intval', explode('.', $min));
        self::assertSame($major * 10000 + $minor * 100, \DRESearch\MIN_PHP_VERSION_ID);

        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        self::assertSame('>=' . $min, $composer['require']['php']);
        self::assertSame($min . '.0', $composer['config']['platform']['php']);
        $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true);
        self::assertSame($min . '.0', $lock['platform-overrides']['php']);

        $ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');
        self::assertStringContainsString("php: ['" . $min . "',", $ci, 'CI tests the minimum.');
        $release = (string) file_get_contents($root . '/.github/workflows/release.yml');
        self::assertStringContainsString("php-version: '" . $min . "'", $release, 'The release is packaged on the minimum.');
        self::assertStringContainsString('PHP >= ' . $min, $release);
        self::assertStringContainsString(
            'PHP_VERSION_ID < ' . \DRESearch\MIN_PHP_VERSION_ID,
            (string) file_get_contents($root . '/bin/dre-search'),
        );
        self::assertStringContainsString('PHP ' . $min . '+', (string) file_get_contents($root . '/README.md'));
    }

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
