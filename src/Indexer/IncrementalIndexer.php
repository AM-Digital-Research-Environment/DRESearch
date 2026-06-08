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
 * Scope: only the saved item's OWN document is refreshed. Reverse-link
 * aggregates it feeds on OTHER records (e.g. a person's item_count when an item
 * crediting them is edited) are corpus-wide and left to the next full reindex.
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
        if ($itemId <= 0) {
            return;
        }
        $client = $this->provider->getClient();
        if ($client === null) {
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
                if ($reindexer->indexOne($itemId)) {
                    $this->logger->info(sprintf(
                        'DRESearch: item %d re-indexed in collection "%s"',
                        $itemId,
                        $profile->collection()
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
            }
        }
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
}
