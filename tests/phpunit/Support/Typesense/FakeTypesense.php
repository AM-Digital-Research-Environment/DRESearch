<?php

declare(strict_types=1);

namespace DRESearch\Test\Support\Typesense;

use Closure;
use Typesense\Client;

/**
 * An in-memory stand-in for a Typesense server, ported from IwacSearch's.
 *
 * Built by hand rather than with PHPUnit mocks because the code under test
 * navigates the client's shape — `$client->collections[$name]->documents`,
 * `$client->aliases[$alias]->retrieve()` — and expressing that as nested mock
 * expectations obscures what a test asserts. Here the fake just IS a tiny
 * server: collections, an alias table, and a log of what was asked of it, with
 * switches for the failures the indexer must survive (a rejected document, an
 * alias write whose response is lost, an alias list that cannot be read).
 *
 * The real `Typesense\Client` is not final: {@see client()} builds one without
 * its constructor and swaps in fakes of the surfaces the module touches, so the
 * fake satisfies the production type hints.
 */
final class FakeTypesense
{
    /** @var array<string, list<array<string,mixed>>> collection name => documents */
    public array $collections = [];

    /** @var array<string, string> alias => collection name */
    public array $aliases = [];

    /** @var list<string> collections deleted, in order */
    public array $dropped = [];

    /**
     * Per-document import outcome: true accepts, false rejects it with an
     * error line, as a schema violation shows up in real life.
     *
     * @var ?Closure(array<string,mixed>): bool
     */
    public ?Closure $importDecision = null;

    /** When set, an alias write fails with it — after applying, if $aliasWriteApplied. */
    public ?\Throwable $aliasWriteFailure = null;
    public bool $aliasWriteApplied = false;

    /** When set, reading one alias fails with it. */
    public ?\Throwable $aliasReadFailure = null;

    /** When set, listing the aliases fails with it. */
    public ?\Throwable $aliasListFailure = null;

    /** When set, deleting a collection fails with it. */
    public ?\Throwable $dropFailure = null;

    /** @var list<array{0:string,1:string}> filtered deletes received, as [collection, filter_by] */
    public array $filterDeletes = [];

    /** @var list<array{0:string,1:array<string,mixed>}> searches received, as [collection, params] */
    public array $searches = [];

    /** @var list<array{0:string,1:int}> imports received, as [collection, document count] */
    public array $imports = [];

    public function client(): Client
    {
        // Bypass the real constructor: it validates node config and builds an
        // HTTP client the fakes replace anyway.
        $client = (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        $client->collections = new FakeCollections($this);
        $client->aliases = new FakeAliases($this);
        return $client;
    }

    /**
     * A collection holding documents with these ids, as a previous build would
     * have left it.
     *
     * @param list<int|string> $ids
     */
    public function seed(string $collection, array $ids = []): void
    {
        $this->collections[$collection] ??= [];
        foreach ($ids as $id) {
            $this->collections[$collection][] = ['id' => (string) $id];
        }
    }

    /** @return list<string> document ids in a collection, in storage order */
    public function ids(string $collection): array
    {
        return array_map(static fn(array $doc): string => (string) ($doc['id'] ?? ''), $this->collections[$collection] ?? []);
    }

    /**
     * The ids of an `id:[…]` or `id:=[…]` filter — the only filter the module
     * sends for documents by id.
     *
     * @return list<string>
     */
    public static function idsOf(string $filter): array
    {
        if (preg_match('/^id:=?\[([0-9,]*)\]$/D', $filter, $match) !== 1) {
            throw new \RuntimeException('The fake only understands id:[…] filters, got: ' . $filter);
        }
        return $match[1] === '' ? [] : explode(',', $match[1]);
    }
}
