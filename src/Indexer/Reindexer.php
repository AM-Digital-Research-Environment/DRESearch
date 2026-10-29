<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Closure;
use Doctrine\DBAL\Connection;
use DRESearch\Indexer\Exception\BatchImportException;
use DRESearch\Indexer\Exception\ReindexCancelledException;
use DRESearch\Indexer\Exception\VerificationException;
use DRESearch\Settings\SearchProfile;
use Laminas\Log\LoggerInterface;

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
 * the profile, so the reindex follows the same config a reuser overrides. Every
 * Typesense call goes through {@see CollectionOps}; queued changes are applied
 * by {@see ChangeApplier}, the same code the incremental worker runs.
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

    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionOps $ops,
        private readonly SearchProfile $profile,
        private readonly LoggerInterface $logger,
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
        $this->info(sprintf("Starting reindex of '%s'…", $this->profile->name()));
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
        $applier = new ChangeApplier($this->connection, $this->ops, $this->profile, $this->logger, $this->stateStore);
        $dirtyRevision = $this->stateStore?->dirtyRevision($this->profile->name());

        try {
            $previous = $this->ops->aliasTarget($this->alias);
            $this->recoverOrphanedCollections($previous);
            $base = $this->collectionBase();
            $collection = $base . 'g' . (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('YmdHisv')
                . '_' . bin2hex(random_bytes(3));
            $token = bin2hex(random_bytes(16));
            $this->throwIfCancelled();
            $source = new OmekaSourceRepository($this->connection, $this->profile);
            $assembler = new DocumentAssembler(
                $source,
                MapperRegistry::default()->create($this->profile, $this->connection),
                $this->profile,
            );
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
            $this->ops->create((new SchemaProvider())->collection($collection, $this->profile));
            $this->info(sprintf('Created staging collection %s', $collection), ['collection' => $collection]);

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
                            $imported += $this->ops->import($collection, $batch);
                        } catch (BatchImportException $e) {
                            $imported += $e->successful();
                            $failed += count($e->failedIds());
                            throw $e;
                        }
                        $batch = [];
                        $this->info(sprintf('Imported %d documents…', $imported), ['collection' => $collection, 'imported' => $imported]);
                        $this->throwIfCancelled();
                    }
                }
            }

            if ($batch) {
                $attempted += count($batch);
                try {
                    $imported += $this->ops->import($collection, $batch);
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
                $this->logger->warn(sprintf('DRESearch: could not persist verifying state: %s', $stateError->getMessage()), $this->context());
            }
            $verified = $this->ops->documentCount($collection);
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
                $this->replayQueue($queue, $applier, $collection);
                // Replay can add or remove resources after the initial SQL scan.
                $verified = $this->ops->documentCount($collection);
            }
            $this->throwIfCancelled();
            $this->guardAgainstShrink($previous, $verified);
            $this->ops->promote($this->alias, $collection);
            $promoted = true;
            try {
                if ($this->stateStore !== null) {
                    (new \DRESearch\Search\SearchCache($this->connection))->invalidate();
                }
            } catch (\Throwable $error) {
                $this->logger->warn('DRESearch: could not invalidate search cache: ' . $error->getMessage(), $this->context());
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
                $this->logger->err(
                    sprintf('DRESearch: alias promoted, but rebuild state could not be persisted: %s', $stateError->getMessage()),
                    $this->context(['collection' => $collection]),
                );
            }
            $this->info(
                sprintf("Promoted alias '%s' → '%s' (rollback: %s)", $this->alias, $collection, $previous ?? 'none'),
                ['collection' => $collection, 'previous' => $previous, 'documents' => $verified],
            );

            if ($queue !== null) {
                try {
                    // Rejections are removed, logged and recorded by the applier.
                    $applier->drain($queue, $collection, $this->cancel);
                } catch (\Throwable $error) {
                    $this->logger->warn('DRESearch: live generation has pending changes; retry the queue: ' . $error->getMessage(), $this->context());
                }
            }
            try {
                $this->cleanupRetiredCollections($collection, $previous);
            } catch (\Throwable $error) {
                $this->logger->warn('DRESearch: alias promoted; retired collection cleanup deferred: ' . $error->getMessage(), $this->context());
            }
            $this->info(sprintf('Done — %d documents at promotion.', $verified), ['collection' => $collection, 'documents' => $verified]);

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
                $this->logger->err(sprintf('DRESearch: could not persist failed rebuild state: %s', $stateError->getMessage()), $this->context());
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
    private function replayQueue(ChangeQueue $queue, ChangeApplier $applier, string $collection): void
    {
        $after = 0;
        while ($rows = $queue->page($this->profile->name(), $after)) {
            $this->throwIfCancelled();
            $ids = array_map('intval', array_column($rows, 'item_id'));
            // Not tolerated: a staging generation with a rejected document is
            // never promoted.
            $applier->apply($ids, $collection);
            $after = max($ids);
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
            $live = $this->ops->documentCount($previous);
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

    private function deleteOwnedStaging(string $collection): bool
    {
        try {
            if (!$this->ops->deleteUnaliased($collection)) {
                return false;
            }
            $this->info(sprintf('Deleted session staging collection %s', $collection), ['collection' => $collection]);
            return true;
        } catch (\Throwable $e) {
            if ($this->isNotFound($e)) {
                return true;
            }
            $this->logger->warn(
                sprintf('DRESearch: could not delete session staging collection %s: %s', $collection, $e->getMessage()),
                $this->context(['collection' => $collection]),
            );
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
                if (!$this->ops->deleteUnaliased($name)) {
                    continue;
                }
                $this->stateStore->forgetGeneration($name);
                $this->info(sprintf('Deleted retired owned collection %s', $name), ['collection' => $name]);
            } catch (\Throwable $e) {
                $this->logger->warn(
                    sprintf('DRESearch: could not delete retired collection %s: %s', $name, $e->getMessage()),
                    $this->context(['collection' => $name]),
                );
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
                if (!$this->ops->deleteUnaliased($name)) {
                    continue;
                }
                $this->stateStore->forgetGeneration($name);
                $this->info(sprintf('Removed orphaned staging collection %s', $name), ['collection' => $name]);
            } catch (\Throwable $e) {
                if ($this->isNotFound($e)) {
                    $this->stateStore->forgetGeneration($name);
                    continue;
                }
                $this->stateStore->markGeneration($name, 'failed');
                $this->logger->warn(
                    sprintf('DRESearch: could not remove orphaned staging collection %s: %s', $name, $e->getMessage()),
                    $this->context(['collection' => $name]),
                );
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

    /** @param array<string,mixed> $context */
    private function info(string $message, array $context = []): void
    {
        $this->logger->info('DRESearch: ' . $message, $this->context($context));
    }

    /**
     * Every rebuild log entry names its profile and job, so one run's lines
     * can be told apart when several profiles rebuild in a row.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function context(array $extra = []): array
    {
        return ['profile' => $this->profile->name(), 'job' => $this->jobId] + $extra;
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
