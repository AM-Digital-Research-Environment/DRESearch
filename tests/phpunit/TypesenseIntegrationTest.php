<?php
declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\ImportResult;
use DRESearch\Indexer\SchemaProvider;
use DRESearch\Settings\SearchProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Typesense\Client;

#[Group('typesense')]
final class TypesenseIntegrationTest extends TestCase
{
    /**
     * Every shipped profile must produce a schema Typesense actually accepts.
     * Unit tests only assert the array we build, so field-combination rules that
     * live in the server (a geopoint may not declare `sort: false`, a non-indexed
     * field may not be faceted, …) used to surface for the first time mid-reindex,
     * against production data.
     */
    public function testEveryShippedProfileSchemaIsAcceptedByTypesense(): void
    {
        $client = $this->client();
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $profiles = $config['dre_search']['profiles'] ?? [];
        self::assertNotEmpty($profiles, 'No search profiles are configured.');

        foreach ($profiles as $name => $definition) {
            $profile = SearchProfile::fromArray((string) $name, $definition);
            $collection = 'dre_schema_' . bin2hex(random_bytes(5));
            $schema = (new SchemaProvider())->collection($collection, $profile);
            try {
                $client->collections->create($schema);
            } catch (\Throwable $e) {
                self::fail(sprintf('Profile "%s" produced a schema Typesense rejected: %s', $name, $e->getMessage()));
            }
            $client->collections[$collection]->delete();
        }
    }

    public function testMixedBatchResponseCannotPassThePromotionGate(): void
    {
        $client = $this->client();

        $collection = 'dre_ci_' . bin2hex(random_bytes(6));
        $client->collections->create([
            'name' => $collection,
            'fields' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'year', 'type' => 'int32'],
            ],
        ]);
        try {
            $documents = [
                ['id' => 'ok', 'title' => 'Valid', 'year' => 2026],
                ['id' => 'bad', 'title' => 'Invalid', 'year' => 'not-an-int'],
            ];
            $response = $client->collections[$collection]->documents->import($documents, ['action' => 'upsert']);
            $result = ImportResult::fromResponse($response, $documents);

            self::assertSame(1, $result->successful());
            self::assertSame(['bad'], $result->failedIds());
            self::assertFalse($result->isComplete());
        } finally {
            $client->collections[$collection]->delete();
        }
    }

    public function testVersionThirtyUnionMergesCollectionsWithGlobalPaging(): void
    {
        $client = $this->client();
        $prefix = 'dre_union_' . bin2hex(random_bytes(5));
        $collections = [$prefix . '_a', $prefix . '_b'];
        foreach ($collections as $collection) {
            $client->collections->create([
                'name' => $collection,
                'fields' => [
                    ['name' => 'title', 'type' => 'string', 'sort' => true],
                    ['name' => '_profile', 'type' => 'string', 'index' => false],
                ],
            ]);
        }
        try {
            $client->collections[$collections[0]]->documents->create([
                'id' => 'a', 'title' => 'Archive Lagos', '_profile' => 'research_items',
            ]);
            $client->collections[$collections[1]]->documents->create([
                'id' => 'b', 'title' => 'Archive Accra', '_profile' => 'research_projects',
            ]);
            $searches = array_map(static fn(string $collection): array => [
                'collection' => $collection,
                'q' => 'archive',
                'query_by' => 'title',
                'sort_by' => '_text_match:desc,title:asc',
            ], $collections);
            $firstPage = $client->multiSearch->perform(
                ['union' => true, 'searches' => $searches],
                ['page' => 1, 'per_page' => 1],
            );
            $secondPage = $client->multiSearch->perform(
                ['union' => true, 'searches' => $searches],
                ['page' => 2, 'per_page' => 1],
            );

            self::assertSame(2, $firstPage['found']);
            self::assertSame(2, $secondPage['found']);
            self::assertCount(1, $firstPage['hits']);
            self::assertCount(1, $secondPage['hits']);
            $documents = array_column(
                array_merge($firstPage['hits'], $secondPage['hits']),
                'document',
            );
            $profiles = array_column($documents, '_profile');
            sort($profiles);
            self::assertSame(
                ['research_items', 'research_projects'],
                $profiles,
            );
        } finally {
            foreach ($collections as $collection) {
                $client->collections[$collection]->delete();
            }
        }
    }

    private function client(): Client
    {
        $host = getenv('TYPESENSE_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('TYPESENSE_HOST is not configured.');
        }
        $client = new Client([
            'api_key' => getenv('TYPESENSE_API_KEY') ?: 'dre-search-ci-key',
            'nodes' => [[
                'host' => $host,
                'port' => getenv('TYPESENSE_PORT') ?: '8108',
                'protocol' => 'http',
            ]],
            'connection_timeout_seconds' => 5,
        ]);
        for ($attempt = 0; $attempt < 40; $attempt++) {
            try {
                if (($client->health->retrieve()['ok'] ?? false) === true) {
                    return $client;
                }
            } catch (\Throwable) {
                // The GitHub service container may still be starting.
            }
            usleep(500_000);
        }
        self::fail('Typesense did not become healthy within 20 seconds.');
    }
}
