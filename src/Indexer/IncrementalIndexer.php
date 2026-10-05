<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Closure;
use Doctrine\DBAL\Connection;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Laminas\Log\LoggerInterface;

/** Omeka writes enqueue SQL work; a background job imports bounded batches. */
final class IncrementalIndexer
{
    private bool $scheduled = false;
    private readonly ChangeQueue $queue;

    public function __construct(
        private readonly Connection $connection,
        private readonly TypesenseClientProvider $provider,
        private readonly ProfileRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly ?RebuildStateStore $stateStore = null,
        private readonly ?Closure $schedule = null,
    ) {
        $this->queue = new ChangeQueue($connection);
    }

    public function indexItem(int $itemId): void
    {
        $this->syncItems([$itemId]);
    }

    public function syncItem(int $itemId): void
    {
        $this->syncItems([$itemId]);
    }

    public function deleteItem(int $itemId): void
    {
        $this->syncItems([$itemId]);
    }

    public function syncItemWithDependencies(int $itemId): void
    {
        $this->syncItems(array_merge([$itemId], $this->dependencies($itemId)));
    }

    public function dependencies(int $itemId): array
    {
        try {
            return array_map('intval', $this->connection->executeQuery(
                'SELECT DISTINCT value_resource_id AS id FROM value WHERE resource_id = :source AND value_resource_id IS NOT NULL'
                . ' UNION SELECT resource_id AS id FROM value WHERE value_resource_id = :target'
                . ' UNION SELECT a.resource_id AS id FROM value a JOIN value b ON a.value_resource_id = b.resource_id'
                . ' WHERE b.value_resource_id = :ancestor',
                ['source' => $itemId, 'target' => $itemId, 'ancestor' => $itemId],
            )->fetchFirstColumn());
        } catch (\Throwable $error) {
            $this->markDirty('Dependency capture failed: ' . $error->getMessage());
            return [];
        }
    }

    public function syncItems(array $itemIds, string $reason = 'Omeka write'): void
    {
        if ($itemIds === [] || !$this->provider->isConfigured()) {
            return;
        }
        try {
            $this->queue->enqueue($this->registry->names(), $itemIds);
            // One dispatcher call per request/import process; saves never wait on Typesense.
            if (!$this->scheduled && $this->schedule !== null) {
                $this->scheduled = true;
                register_shutdown_function(function (): void {
                    try {
                        ($this->schedule)();
                    } catch (\Throwable $error) {
                        $this->logger->warn('DRESearch: queued changes await retry: ' . $error->getMessage());
                    }
                });
            }
        } catch (\Throwable $error) {
            $this->markDirty($reason . ': queue write failed: ' . $error->getMessage());
        }
    }

    /** Retained work is retried by this job, the maintenance action, or a rebuild. */
    public function drain(?Closure $cancel = null): void
    {
        $client = $this->provider->getClient();
        if ($client === null) {
            return;
        }
        $failures = [];
        foreach ($this->registry->all() as $profile) {
            if ($cancel !== null && $cancel()) {
                return;
            }
            $lock = new RebuildLock($this->connection, $profile->name(), $profile->collection(), $this->stateStore);
            try {
                $lock->acquire(30);
                $target = (new GenerationPublisher($client, $profile->collection()))->target();
                if ($target === null) {
                    continue; // A first rebuild will consume these changes.
                }
                $indexer = new Reindexer($this->connection, $client, $profile, static function (string $message): void {
                });
                $indexer->drainQueue($this->queue, $target, $cancel);
            } catch (\Throwable $error) {
                $failures[] = $profile->name() . ': ' . $error->getMessage();
            } finally {
                $lock->release();
            }
        }
        if ($failures !== []) {
            throw new \RuntimeException('Queued changes retained for retry. ' . implode('; ', $failures));
        }
    }

    public function markDirty(string $reason): void
    {
        try {
            $this->stateStore?->markDirty($this->registry->names(), $reason);
        } catch (\Throwable $error) {
            $this->logger->err('DRESearch: could not record stale index state: ' . $error->getMessage());
        }
    }
}
