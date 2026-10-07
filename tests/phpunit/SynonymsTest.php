<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\SchemaProvider;
use DRESearch\Indexer\SynonymsSync;
use DRESearch\Search\QueryBuilder;
use DRESearch\Search\SearchExecutor;
use PHPUnit\Framework\TestCase;

final class SynonymsTest extends TestCase
{
    use ProfileFixture;

    public function testBundledSynonymSetIsWellFormed(): void
    {
        $items = SynonymsSync::items(dirname(__DIR__, 2) . '/data/synonyms.json');
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertGreaterThanOrEqual(2, count($item['synonyms']));
        }
    }

    public function testMalformedSynonymFilesAreRefused(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dre-syn');
        file_put_contents($file, json_encode(['items' => [
            ['id' => 'a', 'synonyms' => ['x', 'y']],
            ['id' => 'a', 'synonyms' => ['p', 'q']],
        ]]));
        try {
            $this->expectException(\RuntimeException::class);
            SynonymsSync::items($file);
        } finally {
            unlink($file);
        }
    }

    public function testQueriesReferenceTheSetButBrowsingDoesNot(): void
    {
        $builder = new QueryBuilder($this->profile());
        self::assertSame(QueryBuilder::SYNONYM_SET, $builder->search(['q' => 'ivory coast'])['synonym_sets']);
        self::assertSame(QueryBuilder::SYNONYM_SET, $builder->countOnly(['q' => 'ivory coast'])['synonym_sets']);
        self::assertSame(QueryBuilder::SYNONYM_SET, $builder->union('ivory coast')['synonym_sets']);
        self::assertArrayNotHasKey('synonym_sets', $builder->search(['q' => '']));
    }

    public function testAMissingSynonymSetIsDroppedAndRetried(): void
    {
        $multi = new class {
            public array $calls = [];
            public function perform(array $body, array $params = []): array
            {
                $this->calls[] = $body;
                return count($this->calls) === 1
                    ? ['results' => [['code' => 404, 'error' => 'Synonym index not found']]]
                    : ['results' => [['found' => 2, 'hits' => []]]];
            }
        };
        $result = SearchExecutor::single((object) ['multiSearch' => $multi], 'c', [
            'q' => 'ivory coast', 'synonym_sets' => 'dre_synonyms', 'stopwords' => 'dre_default',
        ]);
        self::assertSame(2, $result['found']);
        self::assertArrayNotHasKey('synonym_sets', $multi->calls[1]['searches'][0]);
        self::assertSame('dre_default', $multi->calls[1]['searches'][0]['stopwords'], 'Only the missing set is dropped.');
    }

    /**
     * Typesense reports one missing set per answer: on a fresh volume the
     * stopword error comes first and the synonym error only on the retry. A
     * single retry turned every search on such a volume into an error.
     */
    public function testEachMissingSetIsDroppedInTurn(): void
    {
        $multi = new class {
            public array $calls = [];
            public function perform(array $body, array $params = []): array
            {
                $this->calls[] = $body;
                $search = $body['searches'][0];
                if (isset($search['stopwords'])) {
                    return ['results' => [['code' => 404, 'error' => 'Could not find the stopword set named `dre_default`.']]];
                }
                if (isset($search['synonym_sets'])) {
                    return ['results' => [['code' => 404, 'error' => 'Synonym index not found']]];
                }
                return ['results' => [['found' => 3, 'hits' => []]]];
            }
        };
        $result = SearchExecutor::single((object) ['multiSearch' => $multi], 'c', [
            'q' => 'ivory coast', 'synonym_sets' => 'dre_synonyms', 'stopwords' => 'dre_default',
        ]);
        self::assertSame(3, $result['found']);
        self::assertCount(3, $multi->calls);

        $collection = new class {
            public object $documents;
            public function __construct()
            {
                $this->documents = new class {
                    public int $calls = 0;
                    public function search(array $params): array
                    {
                        $this->calls++;
                        if (isset($params['stopwords'])) {
                            throw new \RuntimeException('Could not find the stopword set named `dre_default`.');
                        }
                        if (isset($params['synonym_sets'])) {
                            throw new \RuntimeException('Synonym index not found');
                        }
                        return ['found' => 4, 'hits' => []];
                    }
                };
            }
        };
        $result = SearchExecutor::single((object) ['collections' => ['c' => $collection], 'multiSearch' => $multi], 'c', [
            'q' => 'ivory coast', 'synonym_sets' => 'dre_synonyms', 'stopwords' => 'dre_default', 'enable_analytics' => true,
        ]);
        self::assertSame(4, $result['found'], 'The analytics GET path drops each missing set too.');
        self::assertSame(3, $collection->documents->calls);
    }

    public function testIvoryCoastFindsCoteDivoireOnTypesense(): void
    {
        if (!getenv('TYPESENSE_HOST')) {
            self::markTestSkipped('Requires disposable Typesense.');
        }
        $provider = new \DRESearch\Search\TypesenseClientProvider(
            (string) getenv('TYPESENSE_HOST'),
            (int) (getenv('TYPESENSE_PORT') ?: 8108),
            'http',
            (string) getenv('TYPESENSE_API_KEY'),
        );
        $client = $provider->getClient();
        self::assertNotNull($client);
        SynonymsSync::create($client)->sync();
        $collection = 'dre_synonyms_' . bin2hex(random_bytes(6));
        $profile = $this->profile(['collection' => $collection]);
        $client->collections->create((new SchemaProvider())->collection($collection, $profile));
        try {
            $docs = [
                ['id' => '1', '_kind' => 'item', '_profile' => 'records', 'title' => "Abidjan, Côte d'Ivoire", 'is_public' => true, 'creator_ss' => []],
                ['id' => '2', '_kind' => 'item', '_profile' => 'records', 'title' => 'Nairobi, Kenya', 'is_public' => true, 'creator_ss' => []],
            ];
            $client->collections[$collection]->documents->import($docs);
            $result = SearchExecutor::single($client, $collection, (new QueryBuilder($profile))->search(['q' => 'ivory coast']));
            self::assertSame(['1'], array_column(array_column($result['hits'], 'document'), 'id'));
        } finally {
            $client->collections[$collection]->delete();
        }
    }
}
