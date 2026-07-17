<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Laminas\Log\LoggerInterface;
use Throwable;

/**
 * Live updates to the Typesense index, triggered by Omeka's api.*.post events.
 *
 * DRE is multi-profile: each profile is its own collection scoped to a resource
 * template / item set. When an item is saved we re-map it into whichever
 * profile(s) it belongs to and upsert just that document; when one is deleted we
 * remove its id from every profile collection (idempotent). This closes the gap
 * where an edit waited for the next admin-triggered full reindex.
 *
 * Mirrors IwacSearch's incremental indexer, adapted to the profile model: the
 * actual single-item map + upsert reuses {@see Reindexer::indexOne()} /
 * {@see Reindexer::deleteOne()}, so the mapping logic lives in exactly one place.
 *
 * Scope: the saved item's own document is reconciled across every profile, then
 * bounded incoming/outgoing links are refreshed for cross-document labels and
 * aggregates. Fan-outs beyond the inline cap mark profiles dirty for a rebuild.
 *
 * Resilience: Typesense is optional. With no client configured every call is a
 * no-op, and any Typesense error is logged and swallowed — an indexing failure
 * MUST NOT block the Omeka save the user is completing.
 */
final class IncrementalIndexer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TypesenseClientProvider $provider,
        private readonly ProfileRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly ?RebuildStateStore $stateStore = null,
        private readonly int $inlineCap = 200,
    ) {
    }

    /**
     * Re-map and upsert one item into every profile whose scope it matches.
     *
     * indexOne() self-checks scope (a single cheap SELECT) and returns false for
     * a non-matching profile before doing any mapping work, so looping all
     * profiles is fine — only the matching one(s) pay the full map cost.
     */
    public function indexItem(int $itemId): void
    {
        $this->syncItem($itemId);
    }

    /** Reconcile one item against every current profile scope. */
    public function syncItem(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }
        $client = $this->provider->getClient();
        if ($client === null) {
            if ($this->provider->isConfigured()) {
                $this->safeMarkDirty($this->registry->names(), 'Incremental sync skipped: Typesense client unavailable.');
            }
            return; // Typesense not configured — nothing to do
        }

        // A no-op log sink: the bulk reindexer streams progress through it, but
        // incremental upserts surface failures by throwing (caught below), so we
        // don't want the per-save "Authority lookup: N items" chatter.
        $log = static function (string $message): void {
        };

        foreach ($this->registry->all() as $profile) {
            try {
                $reindexer = new Reindexer($this->connection, $client, $profile, $log);
                $result = $reindexer->syncOne($itemId);
                if ($result === 'upserted') {
                    $this->logger->info(sprintf(
                        'DRESearch: item %d re-indexed in collection "%s"',
                        $itemId,
                        $profile->collection()
                    ));
                } elseif ($result === 'deleted') {
                    $this->logger->info(sprintf(
                        'DRESearch: item %d removed from "%s" after leaving profile scope',
                        $itemId,
                        $profile->collection(),
                    ));
                } elseif ($result === 'missing_alias') {
                    $this->logger->info(sprintf(
                        'DRESearch: incremental sync skipped for item %d; alias "%s" has not been built yet',
                        $itemId,
                        $profile->collection(),
                    ));
                }
            } catch (Throwable $e) {
                // Never block the Omeka save; the full reindex is the backstop.
                $this->logger->warn(sprintf(
                    'DRESearch: failed to re-index item %d in "%s" — %s',
                    $itemId,
                    $profile->collection(),
                    $e->getMessage()
                ));
                $this->safeMarkDirty([$profile->name()], 'Incremental synchronization failed; run a full rebuild.');
            }
        }
    }

    /**
     * Hybrid cross-document consistency: update the saved item plus bounded
     * outgoing/incoming linked resources whose titles, thumbnails, or reverse
     * aggregates may have changed. Larger fan-outs become an explicit dirty flag.
     */
    public function syncItemWithDependencies(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }
        $this->syncItem($itemId);
        $limit = max(1, $this->inlineCap) + 1;
        try {
            $rows = $this->connection->executeQuery(
                'SELECT DISTINCT CASE WHEN resource_id = :caseId THEN value_resource_id ELSE resource_id END AS rid'
                . ' FROM value WHERE (resource_id = :sourceId AND value_resource_id IS NOT NULL)'
                . ' OR value_resource_id = :targetId LIMIT ' . $limit,
                ['caseId' => $itemId, 'sourceId' => $itemId, 'targetId' => $itemId],
            )->fetchFirstColumn();
        } catch (Throwable $e) {
            $this->logger->warn(sprintf('DRESearch: dependency lookup for item %d failed — %s', $itemId, $e->getMessage()));
            $this->safeMarkDirty($this->registry->names(), 'Dependency lookup failed; reconciliation required.');
            return;
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $rows),
            static fn(int $id): bool => $id > 0 && $id !== $itemId,
        )));
        if (count($ids) > $this->inlineCap) {
            $this->safeMarkDirty(
                $this->registry->names(),
                sprintf('Dependency fan-out for item %d exceeded the inline cap; reconciliation required.', $itemId),
            );
            return;
        }
        foreach ($ids as $id) {
            $this->syncItem($id);
        }
    }

    /** @param list<int> $itemIds */
    public function syncItems(array $itemIds, string $reason = 'batch operation'): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn(int $id): bool => $id > 0)));
        if (count($ids) > $this->inlineCap) {
            $this->safeMarkDirty(
                $this->registry->names(),
                sprintf('%s affected %d items (inline cap %d); reconciliation required.', $reason, count($ids), $this->inlineCap),
            );
            return;
        }
        foreach ($ids as $id) {
            $this->syncItem($id);
        }
    }

    /** Record that an event could not determine its complete dependency set. */
    public function markDirty(string $reason): void
    {
        $this->safeMarkDirty($this->registry->names(), $reason);
    }

    /**
     * Remove a deleted item's document from every profile collection.
     *
     * The resource is already gone, so we can't read its template to know which
     * collection held it — delete from all (a 404 is a no-op).
     */
    public function deleteItem(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }
        $client = $this->provider->getClient();
        if ($client === null) {
            if ($this->provider->isConfigured()) {
                $this->safeMarkDirty($this->registry->names(), 'Incremental delete skipped: Typesense client unavailable.');
            }
            return;
        }

        $log = static function (string $message): void {
        };

        foreach ($this->registry->all() as $profile) {
            try {
                (new Reindexer($this->connection, $client, $profile, $log))->deleteOne($itemId);
                $this->logger->info(sprintf(
                    'DRESearch: item %d deleted from collection "%s"',
                    $itemId,
                    $profile->collection()
                ));
            } catch (Throwable $e) {
                if ($this->isNotFound($e)) {
                    continue; // wasn't indexed in this collection — fine
                }
                $this->logger->warn(sprintf(
                    'DRESearch: failed to delete item %d from "%s" — %s',
                    $itemId,
                    $profile->collection(),
                    $e->getMessage()
                ));
                $this->safeMarkDirty([$profile->name()], 'Incremental deletion failed; run a full rebuild.');
            }
        }
    }

    /**
     * Typesense's PHP SDK wraps HTTP errors in a few exception classes; match on
     * the message rather than importing all of them. Good enough for a "did the
     * doc exist" check.
     */
    private function isNotFound(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'not found')
            || str_contains($msg, '404')
            || str_contains($msg, 'could not find');
    }

    /** @param list<string> $profiles */
    private function safeMarkDirty(array $profiles, string $reason): void
    {
        try {
            $this->stateStore?->markDirty($profiles, $reason);
        } catch (Throwable $e) {
            // Operational metadata must never make the Omeka write fail.
            $this->logger->warn(sprintf('DRESearch: could not mark index state dirty — %s', $e->getMessage()));
        }
    }
}
