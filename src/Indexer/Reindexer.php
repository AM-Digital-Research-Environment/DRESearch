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
    /** Seconds a rebuild waits for an incremental drain to release the profile. */
    private const LOCK_WAIT_SECONDS = 60;

    private readonly string $alias;

    /** @param Closure(string):void $log */
    public function __construct(
        private readonly Connection $connection,
        private readonly Client $client,
        private readonly SearchProfile $profile,
        private readonly Closure $log,
        private readonly ?RebuildStateStore $stateStore = null,
        private readonly int $retentionDays = 0,
        private readonly string $jobId = 'manual',
        private readonly ?Closure $cancel = null,
        /**
         * Refuse to promote a generation holding less than this fraction of the
         * live one (0 disables). A mis-scoped template id or an emptied source
         * otherwise "succeeds" by replacing a full corpus with an empty one.
         */
        private readonly float $minRetainedRatio = 0.5,
        /** Operator override for an intentional large drop. */
        private readonly bool $allowShrink = false,
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
        // A drain holds the profile lock only for one short batch at a time;
        // wait for it instead of failing a requested rebuild outright.
        $lock->acquire(self::LOCK_WAIT_SECONDS);
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
            $this->guardAgainstShrink($previous, $verified);
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
                    $rejected = $this->drainQueue($queue, $collection, $this->cancel);
                    if ($rejected !== []) {
                        ($this->log)(sprintf('Typesense rejected %d queued document(s), removed until fixed: %s', count($rejected), implode(', ', array_slice($rejected, 0, 50))));
                        $this->stateStore?->recordRejected($this->profile->name(), $rejected);
                    }
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

    /**
     * Apply and acknowledge queued work on a live generation. Caller holds the
     * profile lock so an older batch cannot overwrite a newer one.
     *
     * A document Typesense rejects (a schema violation in the source data) is
     * removed from the live generation — fail closed: its old copy may carry
     * metadata that has since changed — and its queue row is acknowledged, so
     * one bad record cannot hold the whole corpus paused behind it.
     *
     * @param Closure():bool|null $cancel
     * @param Closure():void|null $beat called after every batch
     * @return list<int> ids rejected and removed
     */
    public function drainQueue(ChangeQueue $queue, string $collection, ?Closure $cancel = null, ?Closure $beat = null): array
    {
        $rejected = [];
        while ($rows = $queue->page($this->profile->name())) {
            if ($cancel !== null && $cancel()) {
                return $rejected;
            }
            $failed = $this->syncBatch(array_map('intval', array_column($rows, 'item_id')), $collection, true);
            if ($failed !== []) {
                $this->deleteDocuments($collection, $failed);
                array_push($rejected, ...$failed);
            }
            (new \DRESearch\Search\SearchCache($this->connection))->invalidate();
            $queue->acknowledge($this->profile->name(), $rows);
            if ($beat !== null) {
                $beat();
            }
        }
        return array_values(array_unique($rejected));
    }

    /**
     * Reconcile a bounded batch against current SQL state, including deletions.
     *
     * @param bool $tolerateRejected return Typesense-rejected ids instead of throwing
     * @return list<int> rejected ids (only when tolerated)
     */
    public function syncBatch(array $ids, string $collection, bool $tolerateRejected = false): array
    {
        $source = new OmekaSourceRepository($this->connection, $this->profile);
        // Targeted: resolve only the authorities this batch links to.
        $assembler = new DocumentAssembler($source, (new MapperFactory($this->connection, $this->profile))->create(true), $this->profile);
        $rejected = [];
        foreach (array_chunk(array_unique(array_map('intval', $ids)), self::BATCH) as $chunk) {
            $rows = $source->rows($chunk);
            $docs = $assembler->documents($rows);
            if ($docs !== []) {
                try {
                    $this->flush($collection, array_values($docs));
                } catch (BatchImportException $e) {
                    if (!$tolerateRejected) {
                        throw $e;
                    }
                    array_push($rejected, ...array_map('intval', $e->failedIds()));
                }
            }
            $present = array_map(static fn(array $row): int => (int) $row['id'], $rows);
            $removed = array_diff($chunk, $present);
            if ($removed !== []) {
                $this->deleteDocuments($collection, $removed);
            }
        }
        return $rejected;
    }

    /** @param array<int> $ids */
    private function deleteDocuments(string $collection, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach (array_chunk($ids, 250) as $chunk) {
            $this->client->collections[$collection]->documents->delete([
                'filter_by' => 'id:=[' . implode(',', $chunk) . ']',
            ]);
        }
    }

    /**
     * @throws VerificationException when the new generation is far smaller than
     *                               the live one and no override was given
     */
    private function guardAgainstShrink(?string $previous, int $documents): void
    {
        if ($this->allowShrink || $this->minRetainedRatio <= 0 || $previous === null) {
            return;
        }
        try {
            $live = (int) ($this->client->collections[$previous]->retrieve()['num_documents'] ?? 0);
        } catch (\Typesense\Exceptions\ObjectNotFound) {
            return;
        }
        if ($live > 0 && $documents < $live * $this->minRetainedRatio) {
            throw new VerificationException(sprintf(
                'Refusing to promote: the new generation has %d documents, the live one %d (below %d%%). '
                . 'Check the profile scope, or rebuild with "Allow a smaller corpus" if the drop is intended.',
                $documents,
                $live,
                (int) round($this->minRetainedRatio * 100),
            ));
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

    /**
     * Only Typesense's typed 404. Matching message text also matched transport
     * errors whose URL contained "404" (generated collection names often do),
     * which made cleanup forget a collection that still existed.
     */
    private function isNotFound(\Throwable $e): bool
    {
        return $e instanceof \Typesense\Exceptions\ObjectNotFound;
    }
}
