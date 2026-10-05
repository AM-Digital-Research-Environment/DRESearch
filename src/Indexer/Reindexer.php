<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Closure;
use Doctrine\DBAL\Connection;
use DRESearch\Indexer\Exception\BatchImportException;
use DRESearch\Indexer\Exception\ReindexCancelledException;
use DRESearch\Indexer\Exception\VerificationException;
use DRESearch\Settings\SearchProfile;
use Typesense\Client;

/**
 * Rebuilds the Typesense index for one {@see SearchProfile} from the Omeka
 * database.
 *
 * Strategy: build a fresh, timestamp-versioned collection, stream the profile's
 * source resources into it PAGED (keyset by id) and batch-upserted, then swap
 * the live alias to it atomically and drop the previous versions. Reads never
 * touch the half-built collection, and memory stays flat regardless of corpus
 * size — the only thing held whole is the small authority lookup (item kind).
 *
 * Everything corpus-specific — the resource template + item set to read, the
 * property terms, the mapper, and the optional reverse item-count — comes from
 * the profile, so the reindex follows the same config a reuser overrides.
 */
final class Reindexer
{
    /** Resources read per SQL page. */
    private const PAGE = 500;
    /** Documents per Typesense import call. */
    private const BATCH = 100;

    private readonly string $alias;

    /** @param Closure(string):void $log */
    public function __construct(
        private readonly Connection $connection,
        private readonly Client $client,
        private readonly SearchProfile $profile,
        private readonly Closure $log,
        private readonly ?RebuildStateStore $stateStore = null,
        private readonly int $retentionDays = 30,
        private readonly string $jobId = 'manual',
        private readonly ?Closure $cancel = null,
    ) {
        $this->alias = $profile->collection();
    }

    /**
     * @return array{documents:int,attempted:int,failed:int,collection:string,previous:?string,duration_ms:int}
     */
    public function run(): array
    {
        $started = hrtime(true);
        ($this->log)(sprintf("Starting reindex of '%s'…", $this->profile->name()));
        $lock = new RebuildLock(
            $this->connection,
            $this->profile->name(),
            $this->alias,
            $this->stateStore,
        );
        $lock->acquire();
        $collection = '';
        $previous = null;
        $created = false;
        $promoted = false;
        $attempted = 0;
        $imported = 0;
        $failed = 0;
        $queue = $this->stateStore !== null ? new ChangeQueue($this->connection) : null;
        $dirtyRevision = $this->stateStore?->dirtyRevision($this->profile->name());

        try {
            $previous = $this->aliasTarget();
            $this->recoverOrphanedCollections($previous);
            $base = $this->collectionBase();
            $collection = $base . 'g' . (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('YmdHisv')
                . '_' . bin2hex(random_bytes(3));
            $token = bin2hex(random_bytes(16));
            $this->throwIfCancelled();
            $source = new OmekaSourceRepository($this->connection, $this->profile);
            $assembler = new DocumentAssembler($source, (new MapperFactory($this->connection, $this->profile))->create(), $this->profile);
            $this->stateStore?->markBuilding(
                $this->profile->name(),
                $this->alias,
                $collection,
                $token,
                $this->jobId,
            );
            // From this point the name is owned by this session. Attempt cleanup
            // even if the create response is lost after Typesense accepted it.
            $created = true;
            $this->client->collections->create((new SchemaProvider())->collection($collection, $this->profile));
            ($this->log)(sprintf('Created staging collection %s', $collection));

            $lastId = 0;
            $batch = [];

            while (true) {
                $this->throwIfCancelled();
                $rows = $source->rows(after: $lastId, limit: self::PAGE);
                if (!$rows) {
                    break;
                }

                $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
                $lastId = (int) end($ids);
                foreach ($assembler->documents($rows) as $doc) {
                    $batch[] = $doc;
                    if (count($batch) >= self::BATCH) {
                        $attempted += count($batch);
                        try {
                            $imported += $this->flush($collection, $batch);
                        } catch (BatchImportException $e) {
                            $imported += $e->successful();
                            $failed += count($e->failedIds());
                            throw $e;
                        }
                        $batch = [];
                        ($this->log)(sprintf('Imported %d documents…', $imported));
                        $this->throwIfCancelled();
                    }
                }
            }

            if ($batch) {
                $attempted += count($batch);
                try {
                    $imported += $this->flush($collection, $batch);
                } catch (BatchImportException $e) {
                    $imported += $e->successful();
                    $failed += count($e->failedIds());
                    throw $e;
                }
            }

            $this->throwIfCancelled();
            try {
                $this->stateStore?->markVerifying($this->profile->name(), $attempted, $imported, $failed);
            } catch (\Throwable $stateError) {
                ($this->log)(sprintf('Could not persist verifying state: %s', $stateError->getMessage()));
            }
            $info = $this->client->collections[$collection]->retrieve();
            $verified = (int) ($info['num_documents'] ?? -1);
            if ($verified !== $imported || $imported !== $attempted) {
                throw new VerificationException(sprintf(
                    'Staging verification failed: attempted=%d imported=%d stored=%d.',
                    $attempted,
                    $imported,
                    $verified,
                ));
            }

            $this->throwIfCancelled();
            if ($queue !== null) {
                $this->replayQueue($queue, $collection);
                // Replay can add or remove resources after the initial SQL scan.
                $info = $this->client->collections[$collection]->retrieve();
                $verified = (int) $info['num_documents'];
            }
            $this->throwIfCancelled();
            (new GenerationPublisher($this->client, $this->alias))->promote($collection);
            $promoted = true;
            try {
                if ($this->stateStore !== null) {
                    (new \DRESearch\Search\SearchCache($this->connection))->invalidate();
                }
            } catch (\Throwable $error) {
                ($this->log)('Could not invalidate search cache: ' . $error->getMessage());
            }
            $durationMs = $this->durationMs($started);
            try {
                $this->stateStore?->markLive(
                    $this->profile->name(),
                    $collection,
                    $previous,
                    $durationMs,
                    $attempted,
                    $imported,
                    $failed,
                    $dirtyRevision,
                    $verified,
                );
            } catch (\Throwable $stateError) {
                // The verified alias is already live. Do not report the rebuild as
                // failed or attempt to delete it because operational metadata lagged.
                ($this->log)(sprintf('Alias promoted, but rebuild state could not be persisted: %s', $stateError->getMessage()));
            }
            ($this->log)(sprintf("Promoted alias '%s' → '%s' (rollback: %s)", $this->alias, $collection, $previous ?? 'none'));

            if ($queue !== null) {
                try {
                    $this->drainQueue($queue, $collection, $this->cancel);
                } catch (\Throwable $error) {
                    ($this->log)('Live generation has pending changes; retry the queue: ' . $error->getMessage());
                }
            }
            try {
                $this->cleanupRetiredCollections($collection, $previous);
            } catch (\Throwable $error) {
                ($this->log)('Alias promoted; retired collection cleanup deferred: ' . $error->getMessage());
            }
            ($this->log)(sprintf('Done — %d documents at promotion.', $verified));

            return [
                'documents' => $verified,
                'attempted' => $attempted,
                'failed' => $failed,
                'collection' => $collection,
                'previous' => $previous,
                'duration_ms' => $durationMs,
            ];
        } catch (\Throwable $e) {
            $deleted = false;
            if ($created && !$promoted) {
                $deleted = $this->deleteOwnedStaging($collection);
            }
            $status = $e instanceof ReindexCancelledException ? 'cancelled' : 'failed';
            $code = match (true) {
                $e instanceof BatchImportException => 'batch_import_failed',
                $e instanceof VerificationException => 'document_count_mismatch',
                $e instanceof ReindexCancelledException => 'cancelled',
                default => 'rebuild_failed',
            };
            try {
                if ($collection !== '') {
                    if ($deleted) {
                        $this->stateStore?->forgetGeneration($collection);
                    } else {
                        $this->stateStore?->markGeneration($collection, $status);
                    }
                }
                $this->stateStore?->markTerminal(
                    $this->profile->name(),
                    $status,
                    $code,
                    $this->durationMs($started),
                    $attempted,
                    $imported,
                    $failed,
                );
            } catch (\Throwable $stateError) {
                ($this->log)(sprintf('Could not persist failed rebuild state: %s', $stateError->getMessage()));
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Compatibility helper for direct callers that already hold the profile
     * lock. Omeka event handlers use IncrementalIndexer to queue this item and
     * its dependencies, then a background worker reconciles them in batches.
     * Returns true after an upsert and false for an absent/out-of-scope item.
     */
    public function indexOne(int $id): bool
    {
        return $this->syncOne($id) === 'upserted';
    }

    /**
     * Converge one document with current profile scope.
     *
     * @return 'upserted'|'deleted'|'missing_alias'|'ignored'
     */
    public function syncOne(int $id): string
    {
        if ($id <= 0) {
            return 'ignored';
        }
        if ($this->aliasTarget() === null) {
            return 'missing_alias';
        }

        $rows = (new OmekaSourceRepository($this->connection, $this->profile))->rows([$id]);
        $this->syncBatch([$id], $this->alias);
        return $rows === [] ? 'deleted' : 'upserted';
    }

    /**
     * Incremental path: remove one document from the live collection by id.
     * Idempotent on the Typesense side; the caller swallows a 404 (the item was
     * never indexed, or already cleared by a reindex).
     */
    public function deleteOne(int $id): void
    {
        try {
            $this->client->collections[$this->alias]->documents[(string) $id]->delete();
        } catch (\Throwable $e) {
            if (!$this->isNotFound($e)) {
                throw $e;
            }
        }
    }

    /**
     * Versioned-collection prefix, derived from the alias so renaming the
     * collection in config keeps versioning + cleanup consistent. Alias
     * "foo_current" → prefix "foo_"; an alias without that suffix → "<alias>_".
     */
    private function collectionBase(): string
    {
        if (str_ends_with($this->alias, '_current')) {
            return substr($this->alias, 0, -strlen('current'));
        }
        return $this->alias . '_';
    }

    /** Replay without acknowledging: a failed/cancelled promotion must retain all work. */
    private function replayQueue(ChangeQueue $queue, string $collection): void
    {
        $after = 0;
        while ($rows = $queue->page($this->profile->name(), $after)) {
            $this->throwIfCancelled();
            $ids = array_map('intval', array_column($rows, 'item_id'));
            $this->syncBatch($ids, $collection);
            $after = max($ids);
        }
    }

    /** Caller holds the profile lock so an older batch cannot overwrite a newer one. */
    public function drainQueue(ChangeQueue $queue, string $collection, ?Closure $cancel = null): void
    {
        while ($rows = $queue->page($this->profile->name())) {
            if ($cancel !== null && $cancel()) {
                return;
            }
            $this->syncBatch(array_map('intval', array_column($rows, 'item_id')), $collection);
            (new \DRESearch\Search\SearchCache($this->connection))->invalidate();
            $queue->acknowledge($this->profile->name(), $rows);
        }
    }

    /** Reconcile a bounded batch against current SQL state, including deletions. */
    public function syncBatch(array $ids, string $collection): void
    {
        $source = new OmekaSourceRepository($this->connection, $this->profile);
        $assembler = new DocumentAssembler($source, (new MapperFactory($this->connection, $this->profile))->create(), $this->profile);
        foreach (array_chunk(array_unique(array_map('intval', $ids)), self::BATCH) as $chunk) {
            $rows = $source->rows($chunk);
            $docs = $assembler->documents($rows);
            if ($docs !== []) {
                $this->flush($collection, $docs);
            }
            $present = array_map(static fn(array $row): int => (int) $row['id'], $rows);
            $removed = array_diff($chunk, $present);
            if ($removed !== []) {
                $this->client->collections[$collection]->documents->delete([
                    'filter_by' => 'id:=[' . implode(',', $removed) . ']',
                ]);
            }
        }
    }

    /** @param list<array<string,mixed>> $docs */
    private function flush(string $collection, array $docs): int
    {
        $result = ImportResult::fromResponse(
            $this->client->collections[$collection]->documents->import($docs, ['action' => 'upsert']),
            $docs,
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

    private function aliasTarget(): ?string
    {
        return (new GenerationPublisher($this->client, $this->alias))->target();
    }

    private function deleteOwnedStaging(string $collection): bool
    {
        try {
            if (!(new GenerationPublisher($this->client, $this->alias))->deleteUnaliased($collection)) {
                return false;
            }
            ($this->log)(sprintf('Deleted session staging collection %s', $collection));
            return true;
        } catch (\Throwable $e) {
            if ($this->isNotFound($e)) {
                return true;
            }
            ($this->log)(sprintf('Could not delete session staging collection %s: %s', $collection, $e->getMessage()));
            return false;
        }
    }

    private function cleanupRetiredCollections(string $live, ?string $rollback): void
    {
        if ($this->stateStore === null) {
            return;
        }
        $keep = array_values(array_filter([$live, $rollback], 'is_string'));
        foreach ($this->stateStore->cleanupCandidates($this->profile->name(), $keep, $this->retentionDays) as $name) {
            try {
                if (!(new GenerationPublisher($this->client, $this->alias))->deleteUnaliased($name)) {
                    continue;
                }
                $this->stateStore->forgetGeneration($name);
                ($this->log)(sprintf('Deleted retired owned collection %s', $name));
            } catch (\Throwable $e) {
                ($this->log)(sprintf('Could not delete retired collection %s: %s', $name, $e->getMessage()));
            }
        }
    }

    /**
     * Lock ownership proves no earlier worker is still writing. Remove its
     * unpublished, metadata-owned staging collections, while preserving and
     * reconciling any collection that is already the live alias target.
     */
    private function recoverOrphanedCollections(?string $liveTarget): void
    {
        if ($this->stateStore === null) {
            return;
        }
        foreach ($this->stateStore->orphanedCollections($this->profile->name()) as $name) {
            if ($name === $liveTarget) {
                $this->stateStore->markGeneration($name, 'live');
                continue;
            }
            try {
                if (!(new GenerationPublisher($this->client, $this->alias))->deleteUnaliased($name)) {
                    continue;
                }
                $this->stateStore->forgetGeneration($name);
                ($this->log)(sprintf('Removed orphaned staging collection %s', $name));
            } catch (\Throwable $e) {
                if ($this->isNotFound($e)) {
                    $this->stateStore->forgetGeneration($name);
                    continue;
                }
                $this->stateStore->markGeneration($name, 'failed');
                ($this->log)(sprintf('Could not remove orphaned staging collection %s: %s', $name, $e->getMessage()));
            }
        }
    }

    private function throwIfCancelled(): void
    {
        if ($this->cancel !== null && ($this->cancel)()) {
            throw new ReindexCancelledException(sprintf('Reindex of "%s" was cancelled before promotion.', $this->profile->name()));
        }
    }

    private function durationMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    private function isNotFound(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'not found')
            || str_contains($message, '404')
            || str_contains($message, 'could not find');
    }
}
