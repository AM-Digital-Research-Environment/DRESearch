<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use DRESearch\Indexer\Exception\RebuildLockedException;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Laminas\Log\LoggerInterface;

/**
 * Omeka writes enqueue SQL work for the profiles whose scope holds the changed
 * items; one coalesced background worker ({@see WorkerLease}) imports bounded
 * batches. Saves never wait on Typesense.
 */
final class IncrementalIndexer
{
    /** Longest one drain job keeps looping while writes keep arriving. */
    private const MAX_WORK_SECONDS = 1800;
    /** Long-running CLI processes (imports, batch jobs) wake the worker this often. */
    private const CLI_WAKE_SECONDS = 30;
    /** Attempts for a dependency query that lost a lock-wait/deadlock race. */
    private const ATTEMPTS = 3;

    private bool $scheduled = false;
    private int $lastCliWake = 0;
    private readonly ChangeQueue $queue;
    private readonly ScopeMatcher $scope;
    private readonly WorkerLease $lease;

    public function __construct(
        private readonly Connection $connection,
        private readonly TypesenseClientProvider $provider,
        private readonly ProfileRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly ?RebuildStateStore $stateStore = null,
        private readonly ?Closure $schedule = null,
    ) {
        $this->queue = new ChangeQueue($connection);
        $this->scope = new ScopeMatcher($connection, $registry);
        $this->lease = new WorkerLease($connection);
    }

    public function syncItemWithDependencies(int $itemId): void
    {
        $this->syncItems(array_merge([$itemId], $this->dependencies($itemId)));
    }

    /** @return list<int> */
    public function dependencies(int $itemId): array
    {
        return $this->dependenciesOf([$itemId]);
    }

    /**
     * Items whose documents embed something from these items: what they link
     * to, what links to them, and the second hop that carries authority
     * hierarchies (city → country). One batched query per 500 ids.
     *
     * @param list<int> $itemIds
     * @return list<int>
     */
    public function dependenciesOf(array $itemIds): array
    {
        $itemIds = ScopeMatcher::normalize($itemIds);
        if ($itemIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($itemIds, 500) as $chunk) {
            $list = implode(',', $chunk);
            $sql = "SELECT DISTINCT value_resource_id AS id FROM value WHERE resource_id IN ($list) AND value_resource_id IS NOT NULL"
                . " UNION SELECT resource_id AS id FROM value WHERE value_resource_id IN ($list)"
                . ' UNION SELECT a.resource_id AS id FROM value a JOIN value b ON a.value_resource_id = b.resource_id'
                . " WHERE b.value_resource_id IN ($list)";
            for ($attempt = 1;; $attempt++) {
                try {
                    array_push($out, ...array_map('intval', $this->connection->executeQuery($sql)->fetchFirstColumn()));
                    break;
                } catch (RetryableException $error) {
                    if ($attempt < self::ATTEMPTS) {
                        usleep(20_000 * $attempt);
                        continue;
                    }
                    $this->markDirty('Dependency capture failed: ' . $error->getMessage());
                    return [];
                } catch (\Throwable $error) {
                    // Unknown dependants could live in any corpus.
                    $this->markDirty('Dependency capture failed: ' . $error->getMessage());
                    return [];
                }
            }
        }
        return ScopeMatcher::normalize($out);
    }

    /**
     * Which profiles currently scope these items, for capture BEFORE a write
     * that can move them out of scope (template/item-set change, deletion).
     * When the lookup fails the answer is every profile — a superset is safe.
     *
     * @param list<int> $itemIds
     * @return array<string,list<int>>
     */
    public function membership(array $itemIds): array
    {
        $itemIds = ScopeMatcher::normalize($itemIds);
        if ($itemIds === [] || !$this->provider->isConfigured()) {
            return [];
        }
        try {
            return $this->scope->match($itemIds);
        } catch (\Throwable $error) {
            $this->logger->warn('DRESearch: scope lookup failed; queueing for every corpus: ' . $error->getMessage());
            return array_fill_keys($this->registry->names(), $itemIds);
        }
    }

    /**
     * Queue items for the profiles whose scope holds them now, plus any
     * profiles that held them before the write ($former), then make sure a
     * worker will run.
     *
     * @param list<int> $itemIds
     * @param array<string,list<int>> $former profile => ids captured before the write
     */
    public function syncItems(array $itemIds, string $reason = 'Omeka write', array $former = []): void
    {
        if (!$this->provider->isConfigured()) {
            return;
        }
        $byProfile = ScopeMatcher::merge($this->membership($itemIds), $former);
        if ($byProfile === []) {
            return;
        }
        try {
            $this->queue->enqueueScoped($byProfile);
        } catch (\Throwable $error) {
            $this->markDirty($reason . ': queue write failed: ' . $error->getMessage(), array_keys($byProfile));
            return;
        }
        $this->scheduleDrain();
    }

    /**
     * Ask for a worker: record the request, and dispatch a job only if this
     * caller wins the lease (no live worker). Safe from any request, including
     * anonymous search traffic healing a stranded queue.
     */
    public function wake(): bool
    {
        if ($this->schedule === null) {
            return false;
        }
        try {
            $this->lease->request();
            if (!$this->lease->claim()) {
                return false;
            }
        } catch (\Throwable $error) {
            $this->logger->warn('DRESearch: could not reach the drain lease: ' . $error->getMessage());
            return false;
        }
        try {
            ($this->schedule)();
            return true;
        } catch (\Throwable $error) {
            try {
                $this->lease->release();
            } catch (\Throwable) {
            }
            $this->logger->warn('DRESearch: queued changes await retry: ' . $error->getMessage());
            return false;
        }
    }

    /**
     * The DrainSearchChanges job body: drain passes until no producer has
     * requested work since the pass began (see {@see WorkerLease}).
     *
     * @param Closure():bool $cancel
     */
    public function work(Closure $cancel): void
    {
        $deadline = time() + self::MAX_WORK_SECONDS;
        while (true) {
            $this->lease->begin();
            $pass = $this->drain($cancel, fn() => $this->lease->beat());
            if ($cancel()) {
                $this->lease->request();
                $this->lease->release();
                return;
            }
            if ($pass['failures'] !== []) {
                $this->lease->backoff();
                throw new \RuntimeException('Queued changes retained for retry. ' . implode('; ', $pass['failures']));
            }
            if ($pass['skipped'] !== []) {
                // A rebuild holds these profiles and drains their queue after
                // promotion; rows queued after that drain still need a worker.
                if (time() >= $deadline) {
                    $this->lease->backoff();
                    return;
                }
                sleep(5);
                continue;
            }
            if ($this->lease->finish()) {
                return;
            }
            if (time() >= $deadline) {
                $this->lease->backoff();
                return;
            }
        }
    }

    /**
     * One pass over every profile. A profile whose lock is held (a rebuild in
     * progress) is skipped rather than waited for: the rebuild replays and then
     * drains that queue itself.
     *
     * @param Closure():bool|null $cancel
     * @param Closure():void|null $beat called between batches (lease heartbeat)
     * @return array{skipped:list<string>,failures:list<string>,rejected:array<string,list<int>>}
     */
    public function drain(?Closure $cancel = null, ?Closure $beat = null): array
    {
        $pass = ['skipped' => [], 'failures' => [], 'rejected' => []];
        $client = $this->provider->getClient();
        if ($client === null) {
            return $pass;
        }
        foreach ($this->registry->all() as $profile) {
            if ($cancel !== null && $cancel()) {
                return $pass;
            }
            $lock = new RebuildLock($this->connection, $profile->name(), $profile->collection(), $this->stateStore);
            try {
                $lock->acquire(0);
            } catch (RebuildLockedException) {
                $pass['skipped'][] = $profile->name();
                continue;
            } catch (\Throwable $error) {
                $pass['failures'][] = $profile->name() . ': ' . $error->getMessage();
                continue;
            }
            try {
                $target = (new GenerationPublisher($client, $profile->collection()))->target();
                if ($target === null) {
                    // Never built: its first rebuild reads current SQL state.
                    $this->queue->clear($profile->name());
                    continue;
                }
                $indexer = new Reindexer($this->connection, $client, $profile, static function (string $message): void {
                });
                $rejected = $indexer->drainQueue($this->queue, $target, $cancel, $beat);
                if ($rejected !== []) {
                    $pass['rejected'][$profile->name()] = $rejected;
                    $this->logger->err(sprintf(
                        'DRESearch: Typesense rejected %d "%s" document(s); they were removed from search until fixed: %s',
                        count($rejected),
                        $profile->name(),
                        implode(', ', array_slice($rejected, 0, 50)),
                    ));
                    $this->stateStore?->recordRejected($profile->name(), $rejected);
                }
            } catch (\Throwable $error) {
                $pass['failures'][] = $profile->name() . ': ' . $error->getMessage();
            } finally {
                $lock->release();
            }
        }
        return $pass;
    }

    /** @param list<string>|null $profiles defaults to every profile (unknown impact) */
    public function markDirty(string $reason, ?array $profiles = null): void
    {
        try {
            $this->stateStore?->markDirty($profiles ?? $this->registry->names(), $reason);
        } catch (\Throwable $error) {
            $this->logger->err('DRESearch: could not record stale index state: ' . $error->getMessage());
        }
    }

    public function lease(): WorkerLease
    {
        return $this->lease;
    }

    private function scheduleDrain(): void
    {
        if ($this->schedule === null) {
            return;
        }
        // Long-running CLI processes (CSV imports, batch-edit jobs) would only
        // reach their shutdown function at the very end; wake periodically.
        if (PHP_SAPI === 'cli' && time() - $this->lastCliWake >= self::CLI_WAKE_SECONDS) {
            $this->lastCliWake = time();
            $this->wake();
        }
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;
        // After the request's own writes have committed.
        register_shutdown_function(function (): void {
            $this->wake();
        });
    }
}
