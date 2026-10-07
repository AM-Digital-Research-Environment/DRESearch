<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\SchemaProvider;
use DRESearch\Search\QueryBuilder;
use DRESearch\Search\SearchExecutor;
use DRESearch\Search\SearchRequest;
use DRESearch\Settings\SearchProfile;
use PHPUnit\Framework\TestCase;
use Typesense\Client;

final class SearchRegressionTest extends TestCase
{
    use ProfileFixture;

    private function provider(): \DRESearch\Search\TypesenseClientProvider
    {
        return new \DRESearch\Search\TypesenseClientProvider(
            (string) getenv('TYPESENSE_HOST'),
            (int) (getenv('TYPESENSE_PORT') ?: 8108),
            'http',
            (string) getenv('TYPESENSE_API_KEY'),
        );
    }

    private function proxy(\DRESearch\Search\TypesenseClientProvider $provider, SearchProfile $profile): \DRESearch\Search\SearchProxy
    {
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter(new \Laminas\Log\Writer\Noop());
        return new \DRESearch\Search\SearchProxy(
            $provider,
            new \DRESearch\Settings\ProfileRegistry(['records' => $profile]),
            new \DRESearch\Search\BlockScopeResolver(\Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])),
            $logger,
        );
    }

    public function testChipFiltersDoNotHaveToBeSidebarFacets(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $profile = SearchProfile::fromArray('research_items', $config['dre_search']['profiles']['research_items']);
        $req = SearchRequest::fromArray([
            'filters' => ['creator_ss' => ['Same name']], 'facets' => $profile->fieldNames(),
        ], $profile)->toArray();
        self::assertSame(['Same name'], $req['filters']['creator_ss']);
        self::assertNotContains('creator_ss', $req['facets']);
    }

    public function testAllQueryModesPreserveStopwordsButInternalQueriesDoNotCountAsAnalytics(): void
    {
        $builder = new QueryBuilder($this->profile());
        $request = ['q' => 'the river', 'record_query' => true, 'analytics_id' => str_repeat('a', 32)];
        self::assertTrue($builder->search($request)['enable_analytics']);
        foreach ([$builder->countOnly($request), $builder->export($request, 1), $builder->map($request, 1), $builder->facetCountsFor($request, 'type_s'), $builder->union('the river')] as $params) {
            self::assertSame(QueryBuilder::STOPWORDS_SET, $params['stopwords']);
            self::assertFalse($params['enable_analytics']);
        }
    }

    public function testFacetSearchCannotRequestUnconfiguredField(): void
    {
        $this->expectException(\DRESearch\Search\Exception\RequestValidationException::class);
        SearchRequest::fromArray(['facet_field' => 'is_public', 'facet_query' => 'true'], $this->profile(), 'facet');
    }

    public function testFulltextSnippetAndLongTailFacetsOnTypesense(): void
    {
        if (!getenv('TYPESENSE_HOST')) {
            self::markTestSkipped('Requires disposable Typesense.');
        }
        $client = new Client([
            'api_key' => getenv('TYPESENSE_API_KEY'), 'num_retries' => 0,
            'nodes' => [['host' => getenv('TYPESENSE_HOST'), 'port' => (string) (getenv('TYPESENSE_PORT') ?: 8108), 'protocol' => 'http']],
        ]);
        $collection = 'dre_regression_' . bin2hex(random_bytes(6));
        $profile = $this->profile([
            'collection' => $collection,
            'query_by' => 'title,fulltext',
            'date' => ['mode' => 'single', 'property' => 'dcterms:date'],
            'display_fields' => ['fulltext' => ['property' => 'bibo:content', 'type' => 'string', 'index' => true, 'search_only' => true]],
        ]);
        $client->collections->create((new SchemaProvider())->collection($collection, $profile));
        try {
            $docs = [];
            for ($i = 1; $i <= 101; $i++) {
                $docs[] = ['id' => (string) $i, '_kind' => 'item', '_profile' => 'records', 'title' => 'Archive river', 'is_public' => true, 'year' => 2020,
                    'type_s' => sprintf('Topic %03d', $i), 'fulltext' => 'Only zygomorphic matches here'];
            }
            $import = \DRESearch\Indexer\ImportResult::fromResponse($client->collections[$collection]->documents->import($docs), $docs);
            self::assertTrue($import->isComplete(), json_encode($import->errors(), JSON_THROW_ON_ERROR));
            $builder = new QueryBuilder($profile);
            $result = SearchExecutor::single($client, $collection, $builder->search(['q' => 'zygomorphic']));
            self::assertSame(101, $result['found']);
            self::assertArrayNotHasKey('fulltext', $result['hits'][0]['document']);
            self::assertStringContainsString(QueryBuilder::HL_START, $result['hits'][0]['highlight']['fulltext']['snippet']);
            self::assertArrayNotHasKey('highlights', $result['hits'][0]);
            $counts = $result['facet_counts'][0]['counts'];
            self::assertCount(100, $counts);
            $missing = array_values(array_diff(array_column($docs, 'type_s'), array_column($counts, 'value')))[0];
            $scoped = new QueryBuilder($profile, 'year:>=2020');
            $params = $scoped->facetSearch(['q' => '', 'filters' => ['type_s' => ['Topic 001']]], 'type_s', $missing);
            $facets = SearchExecutor::single($client, $collection, $params);
            self::assertContains($missing, array_column($facets['facet_counts'][0]['counts'], 'value'));
            self::assertStringContainsString('is_public:=true', $params['filter_by']);
            self::assertStringContainsString('year:>=2020', $params['filter_by']);
            self::assertStringNotContainsString('Topic 001', $params['filter_by']);
        } finally {
            $client->collections[$collection]->delete();
        }
    }

    /**
     * Typesense's v2 highlight format returns a string[] field as one object per
     * array ELEMENT. A match that only lives in an array field (an author name)
     * must still reach `_highlights`, keyed by the marked element value.
     */
    public function testArrayFieldMatchesReachHighlightsOnTypesense(): void
    {
        if (!getenv('TYPESENSE_HOST')) {
            self::markTestSkipped('Requires disposable Typesense.');
        }
        $collection = 'dre_regression_' . bin2hex(random_bytes(6));
        $profile = $this->profile(['collection' => $collection]);
        $provider = new \DRESearch\Search\TypesenseClientProvider(
            (string) getenv('TYPESENSE_HOST'),
            (int) (getenv('TYPESENSE_PORT') ?: 8108),
            'http',
            (string) getenv('TYPESENSE_API_KEY'),
        );
        $client = $provider->getClient();
        self::assertNotNull($client);
        $client->collections->create((new SchemaProvider())->collection($collection, $profile));
        try {
            $docs = [['id' => '1', '_kind' => 'item', '_profile' => 'records', 'title' => 'From the Editor',
                'is_public' => true, 'type_s' => 'Text', 'creator_ss' => ['Smith, Ann', 'Watkins, Lee']]];
            $client->collections[$collection]->documents->import($docs);
            $logger = new \Laminas\Log\Logger();
            $logger->addWriter(new \Laminas\Log\Writer\Noop());
            $proxy = new \DRESearch\Search\SearchProxy(
                $provider,
                new \DRESearch\Settings\ProfileRegistry(['records' => $profile]),
                new \DRESearch\Search\BlockScopeResolver(\Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])),
                $logger,
            );
            $result = $proxy->search('records', ['q' => 'Watkins']);
            self::assertSame(1, $result['found']);
            $marked = $result['hits'][0]['_highlights']['creator_ss'] ?? [];
            self::assertSame([QueryBuilder::HL_START . 'Watkins' . QueryBuilder::HL_END . ', Lee'], $marked);
            self::assertArrayNotHasKey('title', $result['hits'][0]['_highlights']);
        } finally {
            $client->collections[$collection]->delete();
        }
    }

    /**
     * A selected value outside the top values a recount returns is listed with
     * an unknown (null) count — it has results, so "0" would be false.
     */
    public function testLongTailSelectionIsListedWithAnUnknownCountOnTypesense(): void
    {
        if (!getenv('TYPESENSE_HOST')) {
            self::markTestSkipped('Requires disposable Typesense.');
        }
        $collection = 'dre_regression_' . bin2hex(random_bytes(6));
        $profile = $this->profile(['collection' => $collection]);
        $provider = $this->provider();
        $client = $provider->getClient();
        self::assertNotNull($client);
        $client->collections->create((new SchemaProvider())->collection($collection, $profile));
        try {
            $docs = [];
            for ($i = 1; $i <= 101; $i++) {
                // Topic 001 gets two documents so the long tail is well defined.
                $docs[] = ['id' => (string) $i, '_kind' => 'item', '_profile' => 'records', 'title' => 'Archive', 'is_public' => true,
                    'type_s' => sprintf('Topic %03d', $i === 101 ? 1 : $i), 'creator_ss' => []];
            }
            $docs[] = ['id' => '102', '_kind' => 'item', '_profile' => 'records', 'title' => 'Archive', 'is_public' => true,
                'type_s' => 'Topic 999', 'creator_ss' => []];
            $client->collections[$collection]->documents->import($docs);
            $result = $this->proxy($provider, $profile)->search('records', ['filters' => ['type_s' => ['Topic 999']]]);
            self::assertSame(1, $result['found']);
            $listed = array_column($result['facets'][0]['counts'], 'count', 'value');
            self::assertArrayHasKey('Topic 999', $listed);
            if (count($listed) > 100) {
                self::assertNull($listed['Topic 999'], 'Beyond the recounted top values the count is unknown, not zero.');
            }
        } finally {
            $client->collections[$collection]->delete();
        }
    }

    public function testUnreachableTypesenseIsCalledOncePerRequest(): void
    {
        $provider = new \DRESearch\Search\TypesenseClientProvider('127.0.0.1', 18199, 'http', 'k', 2.0, 2.0, 0.5);
        $proxy = $this->proxy($provider, $this->profile());
        $started = microtime(true);
        self::assertFalse($proxy->search('records', ['q' => 'x'])['available']);
        $first = microtime(true) - $started;
        $started = microtime(true);
        self::assertFalse($proxy->search('records', ['q' => 'y'])['available']);
        self::assertNull($proxy->yearBounds('records'));
        self::assertLessThan(0.2, microtime(true) - $started, sprintf('Breaker open after a %.1fs connect failure.', $first));
    }

    public function testMissingStopwordRetriesWholeMultiSearchConsistently(): void
    {
        $multi = new class {
            public array $calls = [];
            public function perform(array $body, array $params): array
            {
                $this->calls[] = $body;
                return count($this->calls) === 1
                    ? ['results' => [['error' => 'Could not find the stopword set.'], ['found' => 1]]]
                    : ['results' => [['found' => 5], ['found' => 5]]];
            }
        };
        $client = (object) ['multiSearch' => $multi];
        $result = SearchExecutor::multi($client, ['searches' => [['q' => 'the river', 'stopwords' => 'missing'], ['q' => 'the river', 'stopwords' => 'missing']]]);
        self::assertSame(5, $result['results'][0]['found']);
        self::assertCount(2, $multi->calls);
        foreach ($multi->calls[1]['searches'] as $search) {
            self::assertArrayNotHasKey('stopwords', $search);
        }
    }
}
