<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\SearchExecutor;
use PHPUnit\Framework\TestCase;

final class SearchExecutorTest extends TestCase
{
    public function testSingleSearchTravelsAsPostBodyWithItsCollection(): void
    {
        $client = $this->client(['results' => [['found' => 3, 'hits' => []]]]);
        $result = SearchExecutor::single($client, 'records_current', ['q' => str_repeat('ሀ', 450)]);
        self::assertSame(3, $result['found']);
        self::assertSame('records_current', $client->multiSearch->calls[0]['body']['searches'][0]['collection']);
        self::assertSame([], $client->collections->calls, 'Long queries must not use the 4,000-byte GET endpoint.');
    }

    public function testPerSearchErrorsAreRaised(): void
    {
        $this->expectException(\RuntimeException::class);
        SearchExecutor::single($this->client(['results' => [['code' => 404, 'error' => 'Not found.']]]), 'gone', ['q' => 'x']);
    }

    public function testRecordedQueriesUseTheSearchEndpointOnlyWhileTheyFit(): void
    {
        $client = $this->client(['results' => [['found' => 0]]]);
        SearchExecutor::single($client, 'c', ['q' => 'river', 'enable_analytics' => true, 'x-typesense-user-id' => 'u']);
        self::assertCount(1, $client->collections->calls);
        self::assertCount(0, $client->multiSearch->calls);

        SearchExecutor::single($client, 'c', ['q' => str_repeat('ሀ', 450), 'enable_analytics' => true, 'x-typesense-user-id' => 'u']);
        $sent = $client->multiSearch->calls[0];
        self::assertFalse($sent['body']['searches'][0]['enable_analytics']);
        self::assertArrayNotHasKey('x-typesense-user-id', $sent['body']['searches'][0]);
    }

    /**
     * A duck-typed Typesense\Client: the two entry points SearchExecutor uses,
     * each recording what it was sent.
     *
     * @param array<string,mixed> $multiResponse
     * @return object{
     *     collections: object{calls: list<mixed>},
     *     multiSearch: object{calls: list<array{body: array<string,mixed>, params: array<string,mixed>}>}
     * }
     */
    private function client(array $multiResponse): object
    {
        $documents = new class {
            /** @var list<array<string,mixed>> */
            public array $calls = [];

            /** @param array<string,mixed> $params */
            public function search(array $params): array
            {
                $this->calls[] = $params;
                return ['found' => 0, 'hits' => []];
            }
        };
        $collections = new class ($documents) implements \ArrayAccess {
            /** @var list<mixed> */
            public array $calls = [];

            public function __construct(private readonly object $documents)
            {
            }

            public function offsetGet(mixed $offset): object
            {
                $this->calls[] = $offset;
                return (object) ['documents' => $this->documents];
            }

            public function offsetExists(mixed $offset): bool
            {
                return true;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
            }

            public function offsetUnset(mixed $offset): void
            {
            }
        };
        $multi = new class ($multiResponse) {
            /** @var list<array{body:array<string,mixed>,params:array<string,mixed>}> */
            public array $calls = [];

            /** @param array<string,mixed> $response */
            public function __construct(private readonly array $response)
            {
            }

            /**
             * @param array<string,mixed> $body
             * @param array<string,mixed> $params
             */
            public function perform(array $body, array $params = []): array
            {
                $this->calls[] = ['body' => $body, 'params' => $params];
                return $this->response;
            }
        };
        return (object) ['collections' => $collections, 'multiSearch' => $multi];
    }
}
