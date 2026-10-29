<?php

declare(strict_types=1);

namespace DRESearch\Test\Support\Typesense;

use Typesense\Document;
use Typesense\Documents;

/** @internal The documents of one {@see FakeCollection}. */
final class FakeDocuments extends Documents
{
    public function __construct(private readonly FakeTypesense $server, private readonly string $collection)
    {
    }

    /**
     * The real import contract: one outcome per document, `{"success":true}` or
     * `{"success":false,"error":…}`; decoded rows for an array of documents, as
     * the SDK returns them, JSONL for a JSONL body.
     *
     * @param array<array<string,mixed>>|string $documents
     * @param array<string,mixed> $options
     * @return list<array<string,mixed>>|string
     */
    public function import($documents, array $options = [])
    {
        if (is_array($documents)) {
            $docs = array_values($documents);
        } else {
            $docs = [];
            foreach (preg_split('/\R/', trim($documents)) ?: [] as $line) {
                if ($line !== '') {
                    $docs[] = (array) json_decode($line, true);
                }
            }
        }
        $this->server->imports[] = [$this->collection, count($docs)];
        $rows = [];
        foreach ($docs as $doc) {
            if ($this->server->importDecision !== null && !($this->server->importDecision)($doc)) {
                $rows[] = ['success' => false, 'code' => 400, 'error' => 'Field `title` must be a string.'];
                continue;
            }
            $this->store($doc);
            $rows[] = ['success' => true];
        }
        if (is_array($documents)) {
            return $rows;
        }
        return implode("\n", array_map(static fn(array $row): string => (string) json_encode($row), $rows));
    }

    /**
     * Filtered delete. Ids absent from the collection are not an error, as on
     * the real server.
     *
     * @param array<string,mixed> $queryParams
     * @return array{num_deleted:int}
     */
    public function delete(array $queryParams = []): array
    {
        $filter = (string) ($queryParams['filter_by'] ?? '');
        $this->server->filterDeletes[] = [$this->collection, $filter];
        $ids = FakeTypesense::idsOf($filter);
        $before = $this->server->collections[$this->collection] ?? [];
        $after = array_values(array_filter(
            $before,
            static fn(array $doc): bool => !in_array((string) ($doc['id'] ?? ''), $ids, true),
        ));
        $this->server->collections[$this->collection] = $after;
        return ['num_deleted' => count($before) - count($after)];
    }

    /**
     * A search only counts: it understands an id filter (or none) and returns
     * no hits.
     *
     * @param array<string,mixed> $searchParams
     * @return array<string,mixed>
     */
    public function search(array $searchParams): array
    {
        $this->server->searches[] = [$this->collection, $searchParams];
        $docs = $this->server->collections[$this->collection] ?? [];
        $filter = (string) ($searchParams['filter_by'] ?? '');
        if ($filter !== '') {
            $ids = FakeTypesense::idsOf($filter);
            $docs = array_filter($docs, static fn(array $doc): bool => in_array((string) ($doc['id'] ?? ''), $ids, true));
        }
        return ['found' => count($docs), 'hits' => []];
    }

    public function offsetGet($documentId): Document
    {
        throw new \LogicException('The fake does not serve single documents.');
    }

    public function offsetExists($documentId): bool
    {
        return in_array((string) $documentId, $this->server->ids($this->collection), true);
    }

    public function offsetSet($offset, $value): void
    {
    }

    public function offsetUnset($documentId): void
    {
    }

    /** @param array<string,mixed> $doc a document with a known id replaces the stored one */
    private function store(array $doc): void
    {
        $stored = $this->server->collections[$this->collection] ?? [];
        foreach ($stored as $i => $existing) {
            if ((string) ($existing['id'] ?? '') === (string) ($doc['id'] ?? '')) {
                $stored[$i] = $doc;
                $this->server->collections[$this->collection] = $stored;
                return;
            }
        }
        $stored[] = $doc;
        $this->server->collections[$this->collection] = $stored;
    }
}
