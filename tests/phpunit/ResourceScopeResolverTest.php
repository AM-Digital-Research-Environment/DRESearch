<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\Exception\RequestValidationException;
use DRESearch\Search\FilterExpression;
use DRESearch\Search\QueryBuilder;
use DRESearch\Search\ResourceScopeResolver;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\SearchRequest;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use PHPUnit\Framework\TestCase;

/**
 * The `scope_item` scope: a project item's research records, rebuilt on the
 * server from the item the current user can read.
 */
final class ResourceScopeResolverTest extends TestCase
{
    private const PROJECT_SET = 20;

    /** Items the "current user" can read, by id. Absent ids stand for absent or private items. */
    private const ITEMS = [
        1797 => ['class' => 'foaf:Project', 'item_sets' => [20], 'title' => 'Muslim Youth in Kenya'],
        1800 => ['class' => null, 'item_sets' => [20], 'title' => 'Set-only project'],
        1801 => ['class' => 'foaf:Project', 'item_sets' => [], 'title' => 'Class-only project'],
        2000 => ['class' => 'dctype:Text', 'item_sets' => [1], 'title' => 'A research item'],
        2001 => ['class' => 'foaf:Project', 'item_sets' => [20], 'title' => 'Break`) || (is_public:=false'],
        2002 => ['class' => 'foaf:Project', 'item_sets' => [20], 'title' => ''],
    ];

    /** @var list<int> */
    private array $reads = [];

    private function resolver(): ResourceScopeResolver
    {
        return new ResourceScopeResolver(
            function (int $id): ?array {
                $this->reads[] = $id;
                $item = self::ITEMS[$id] ?? null;
                return $item === null ? null : ['class' => $item['class'], 'item_sets' => $item['item_sets']];
            },
            static fn(int $id): string => self::ITEMS[$id]['title'] ?? '',
            self::PROJECT_SET,
        );
    }

    public function testAProjectIsScopedToItsExactIndexedTitle(): void
    {
        $resolver = $this->resolver();
        self::assertSame('project_s:=`Muslim Youth in Kenya`', $resolver->resolve(1797, 'research_items'));
        self::assertSame('project_s:=`Set-only project`', $resolver->resolve(1800, 'research_items'), 'The project item set alone qualifies.');
        self::assertSame('project_s:=`Class-only project`', $resolver->resolve(1801, 'research_items'), 'The foaf:Project class alone qualifies.');
    }

    /** Absent, private, not a project, wrong corpus, unusable title: one answer for all. */
    public function testEveryRefusalIsTheSameError(): void
    {
        $resolver = $this->resolver();
        $answers = [];
        foreach (
            [
                'absent or private' => [999999, 'research_items'],
                'not a project' => [2000, 'research_items'],
                'wrong profile' => [1797, 'research_projects'],
                'backtick in the title' => [2001, 'research_items'],
                'no public title' => [2002, 'research_items'],
            ] as $case => [$id, $profile]
        ) {
            try {
                $resolver->resolve($id, $profile);
                self::fail("{$case}: a scope was resolved.");
            } catch (RequestValidationException $error) {
                $answers[$case] = [$error->publicCode(), $error->getMessage(), $error->status()];
            }
        }
        self::assertCount(1, array_unique(array_map('serialize', $answers)), 'No answer tells the cases apart.');
        self::assertSame(['invalid_block_scope', 'The requested block scope is not available.', 400], reset($answers));
    }

    public function testTheWrongProfileIsRefusedBeforeTheItemIsRead(): void
    {
        $resolver = $this->resolver();
        try {
            $resolver->resolve(1797, 'research_publications');
            self::fail('A scope was resolved for another corpus.');
        } catch (RequestValidationException) {
        }
        self::assertSame([], $this->reads);
    }

    /**
     * Typesense has no escape for a backtick inside a backtick-quoted value; a
     * title carrying one, or a trailing wildcard/backslash, or a quoted phrase,
     * would be read as syntax. Such a title yields no scope rather than a
     * mangled (and so different, possibly wider) one.
     */
    public function testATitleThatCannotBeMatchedLiterallyYieldsNoScope(): void
    {
        foreach (
            [
                'Break`) || (is_public:=false',
                'Back`tick',
                '`',
                'Prefix*',
                'Trailing\\',
                '"Quoted phrase"',
                ' padded ',
                "New\nline",
                '',
                str_repeat('x', FilterExpression::MAX_LENGTH),
            ] as $title
        ) {
            self::assertNull(ResourceScopeResolver::filterFor($title), json_encode($title) ?: '');
        }
        $filter = ResourceScopeResolver::filterFor('Islam & (Politics) || "Peace" — Côte d’Ivoire');
        self::assertSame('project_s:=`Islam & (Politics) || "Peace" — Côte d’Ivoire`', $filter);
        self::assertNull(FilterExpression::problem((string) $filter));
    }

    public function testTheScopeIsEnforcedInsideEveryQueryWithVisibilityFirst(): void
    {
        $profile = $this->profile();
        $filter = $this->resolver()->resolve(1797, 'research_items');
        $params = (new QueryBuilder($profile, $filter))->search([
            'q' => '',
            'filters' => ['project_s' => ['Another project']],
        ]);
        self::assertSame(
            'is_public:=true && (project_s:=`Muslim Youth in Kenya`) && project_s:=[`Another project`]',
            $params['filter_by'],
            'A user filter on another project narrows to nothing; it never replaces the scope.',
        );
    }

    public function testBlockIdAndScopeItemCannotBeCombined(): void
    {
        $scopes = new BlockScopeResolver($this->blocks(), $this->resolver());
        try {
            $scopes->resolveScope(5, 1797, 'research_items');
            self::fail('Two scopes were accepted together.');
        } catch (RequestValidationException $error) {
            self::assertSame('conflicting_scope', $error->publicCode());
        }
        self::assertSame([], $this->reads, 'Nothing is looked up for a conflicting request.');

        self::assertSame('project_s:=`Muslim Youth in Kenya`', $scopes->resolveScope(null, 1797, 'research_items'));
        self::assertSame('type_s:=`Text`', $scopes->resolveScope(5, null, 'research_items'), 'block_id alone still works.');
        self::assertNull($scopes->resolveScope(null, null, 'research_items'));
    }

    public function testWithoutAResourceResolverAScopeItemIsRefused(): void
    {
        $this->expectExceptionObject(ResourceScopeResolver::unavailable());
        (new BlockScopeResolver($this->blocks()))->resolveScope(null, 1797, 'research_items');
    }

    public function testTheRequestCarriesAValidatedScopeItem(): void
    {
        $profile = $this->profile();
        self::assertSame(1797, SearchRequest::fromArray(['scope_item' => 1797], $profile)->toArray()['scope_item']);
        self::assertSame(1797, SearchRequest::fromArray(['scope_item' => '1797'], $profile, 'export')->toArray()['scope_item']);
        self::assertArrayNotHasKey('scope_item', SearchRequest::fromArray([], $profile)->toArray());
        self::assertSame(1797, SearchRequest::scopeItem('1797'));
        self::assertNull(SearchRequest::scopeItem(null));
        foreach ([0, -1, 'abc', '1797 OR 1', 1.5, [1797]] as $bad) {
            try {
                SearchRequest::fromArray(['scope_item' => $bad], $profile);
                self::fail('Accepted scope_item ' . json_encode($bad));
            } catch (RequestValidationException $error) {
                self::assertSame('invalid_scope_item', $error->publicCode());
            }
        }
    }

    /** Search, facet, export, map and suggest all resolve the scope before any backend call. */
    public function testEveryProxyPathResolvesTheScope(): void
    {
        $proxy = $this->proxy();
        $paths = [
            'search' => fn(array $scope) => $proxy->search('research_items', $scope),
            'facet' => fn(array $scope) => $proxy->facet('research_items', $scope + ['facet_field' => 'type_s']),
            'export' => fn(array $scope) => $proxy->export('research_items', $scope),
            'suggest' => fn(array $scope) => $proxy->suggest(
                'research_items',
                'kenya',
                $scope['block_id'] ?? null,
                null,
                $scope['scope_item'] ?? null,
            ),
        ];
        foreach ($paths as $path => $call) {
            foreach (
                [
                    'invalid_block_scope' => ['scope_item' => 2000],
                    'conflicting_scope' => ['scope_item' => 1797, 'block_id' => 5],
                ] as $code => $scope
            ) {
                try {
                    $call($scope);
                    self::fail("{$path} accepted {$code}.");
                } catch (RequestValidationException $error) {
                    self::assertSame($code, $error->publicCode(), $path);
                }
            }
            // A valid scope reaches the backend check: unconfigured, so unavailable.
            $response = $call(['scope_item' => 1797]);
            self::assertFalse($response['available'], $path);
        }
    }

    private function profile(): SearchProfile
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        return SearchProfile::fromArray('research_items', $config['dre_search']['profiles']['research_items']);
    }

    private function proxy(): SearchProxy
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter(new \Laminas\Log\Writer\Noop());
        return new SearchProxy(
            new TypesenseClientProvider('', 0, 'http', ''),
            ProfileRegistry::fromArray($config['dre_search']['profiles']),
            new BlockScopeResolver($this->blocks(), $this->resolver()),
            $logger,
        );
    }

    /** One saved research-items block (id 5) with a locked filter. */
    private function blocks(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE site_page_block (id INTEGER PRIMARY KEY, layout TEXT, data TEXT)');
        $connection->insert('site_page_block', [
            'id' => 5,
            'layout' => 'dreSearch',
            'data' => json_encode(['locked_filter' => 'type_s:=`Text`']),
        ]);
        return $connection;
    }
}
