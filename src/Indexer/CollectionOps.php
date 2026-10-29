<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Indexer\Exception\BatchImportException;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

/**
 * Every Typesense operation the indexer performs, in one injectable class:
 * creating and counting collections, importing and deleting documents, reading
 * and promoting an alias, and deleting a collection only when no alias holds it.
 *
 * The rebuild ({@see Reindexer}) and the incremental apply ({@see ChangeApplier})
 * share it, so each safety rule lives once: an import counts as done only
 * document by document ({@see ImportResult}), an ambiguous alias write counts as
 * done only once the alias is read back, and cleanup never deletes a collection
 * an alias still points at. It replaces a GenerationPublisher that the rebuild
 * constructed five times over, and raw client calls spread across both paths;
 * tests drive it against an in-memory Typesense (the IwacSearch CollectionOps
 * is the model).
 */
final class CollectionOps
{
    /** Ids per filtered delete: keeps the filter_by string well inside a URL. */
    private const ID_FILTER_CHUNK = 250;

    public function __construct(private readonly Client $client)
    {
    }

    // ── Collections ──────────────────────────────────────────────────────────

    /** @param array<string,mixed> $schema */
    public function create(array $schema): void
    {
        $this->client->collections->create($schema);
    }

    /**
     * Documents the server holds in a collection, or -1 when its answer does
     * not say (which no verification can mistake for a match). A missing
     * collection throws Typesense's ObjectNotFound.
     */
    public function documentCount(string $collection): int
    {
        $info = $this->client->collections[$collection]->retrieve();
        return isset($info['num_documents']) ? (int) $info['num_documents'] : -1;
    }

    /**
     * How many of these ids a collection holds, one counting search per 250.
     *
     * @param array<int|string> $ids
     */
    public function countDocuments(string $collection, array $ids): int
    {
        $found = 0;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach (array_chunk($ids, self::ID_FILTER_CHUNK) as $chunk) {
            $result = $this->client->collections[$collection]->documents->search([
                'q' => '*',
                'filter_by' => 'id:=[' . implode(',', $chunk) . ']',
                'per_page' => 0,
                'enable_analytics' => false,
            ]);
            if (!isset($result['found'])) {
                throw new \RuntimeException(sprintf('Typesense did not report a count for collection "%s".', $collection));
            }
            $found += (int) $result['found'];
        }
        return $found;
    }

    /**
     * Delete a collection unless an alias — any alias, not only this profile's —
     * points at it. Checking every alias also protects a collection another
     * alias shares; when they cannot be read the collection is retained.
     *
     * @return bool false when an alias holds the collection; true once it is gone
     */
    public function deleteUnaliased(string $collection): bool
    {
        $aliases = $this->client->aliases->retrieve();
        if (!isset($aliases['aliases']) || !is_array($aliases['aliases'])) {
            throw new \RuntimeException('Cannot verify aliases; collection retained.');
        }
        foreach ($aliases['aliases'] as $alias) {
            if (($alias['collection_name'] ?? null) === $collection) {
                return false;
            }
        }
        try {
            $this->client->collections[$collection]->delete();
        } catch (ObjectNotFound) {
        }
        return true;
    }

    // ── Documents ────────────────────────────────────────────────────────────

    /**
     * Upsert a batch. Anything but an explicit per-document success is a
     * rejection, whatever the HTTP status said.
     *
     * @param list<array<string,mixed>> $documents
     * @return int documents imported (all of them)
     * @throws BatchImportException naming the rejected ids, when any was rejected
     */
    public function import(string $collection, array $documents): int
    {
        $result = ImportResult::fromResponse(
            $this->client->collections[$collection]->documents->import($documents, ['action' => 'upsert']),
            $documents,
        );
        if (!$result->isComplete()) {
            throw new BatchImportException(
                $collection,
                $result->successful(),
                $result->failedIds(),
                $result->errors(),
            );
        }
        return $result->successful();
    }

    /**
     * Delete documents by id, one filtered request per 250 ids. Ids absent
     * from the collection are not an error.
     *
     * @param array<int|string> $ids
     */
    public function deleteDocuments(string $collection, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach (array_chunk($ids, self::ID_FILTER_CHUNK) as $chunk) {
            $this->client->collections[$collection]->documents->delete([
                'filter_by' => 'id:=[' . implode(',', $chunk) . ']',
            ]);
        }
    }

    // ── Aliases ──────────────────────────────────────────────────────────────

    /** The collection an alias points at, or null when there is no alias; transport failures propagate. */
    public function aliasTarget(string $alias): ?string
    {
        try {
            return $this->client->aliases[$alias]->retrieve()['collection_name'] ?? null;
        } catch (ObjectNotFound) {
            return null;
        }
    }

    /**
     * Point an alias at a collection. A timeout can follow a PUT the server
     * applied: only an alias read back at the new target resolves that, and
     * otherwise the original error stands (cleanup independently fails closed).
     */
    public function promote(string $alias, string $collection): void
    {
        try {
            $this->client->aliases->upsert($alias, ['collection_name' => $collection]);
        } catch (\Throwable $error) {
            try {
                if ($this->aliasTarget($alias) === $collection) {
                    return;
                }
            } catch (\Throwable) {
                // Preserve the original error.
            }
            throw $error;
        }
    }
}
