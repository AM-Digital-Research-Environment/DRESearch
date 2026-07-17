<?php
declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\ImportResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Typesense\Client;

#[Group('typesense')]
final class TypesenseIntegrationTest extends TestCase
{
    public function testMixedBatchResponseCannotPassThePromotionGate(): void
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
        $ready = false;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            try {
                $health = $client->health->retrieve();
                if (($health['ok'] ?? false) === true) {
                    $ready = true;
                    break;
                }
            } catch (\Throwable) {
                // GitHub service containers can accept connections a few
                // seconds after the test process begins.
            }
            usleep(500_000);
        }
        self::assertTrue($ready, 'Typesense did not become healthy within 20 seconds.');

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
}
