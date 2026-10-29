<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Closure;
use Doctrine\DBAL\Connection;
use DRESearch\Indexer\Exception\BatchImportException;
use DRESearch\Search\SearchCache;
use DRESearch\Settings\SearchProfile;
use Laminas\Log\LoggerInterface;

/**
 * Applies queued changes to one profile's collection: the incremental side of
 * indexing, shared by the background worker ({@see IncrementalIndexer}) and by
 * a rebuild, which replays the queue into its staging collection before
 * promotion and drains it on the new live generation afterwards.
 *
 * Every batch is reconciled against current SQL state, never against the event
 * that queued it: an id still in the profile's public scope is mapped and
 * upserted, any other (deleted, made private, moved out of scope) is deleted.
 * The caller holds the profile lock, so an older batch cannot overwrite a
 * newer one.
 */
final class ChangeApplier
{
    /** Documents per Typesense import call. */
    private const BATCH = 100;

    /** @var Closure():void */
    private readonly Closure $invalidate;

    /**
     * @param Closure():void|null $invalidate run after each drained batch, before
     *        its acknowledgement; defaults to moving the search-cache epoch so no
     *        cached page outlives the change
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionOps $ops,
        private readonly SearchProfile $profile,
        private readonly LoggerInterface $logger,
        private readonly ?RebuildStateStore $stateStore = null,
        ?Closure $invalidate = null,
    ) {
        $this->invalidate = $invalidate ?? static function () use ($connection): void {
            (new SearchCache($connection))->invalidate();
        };
    }

    /**
     * Reconcile a bounded set of ids against current SQL state, deletions
     * included.
     *
     * @param list<int> $ids
     * @param bool $tolerateRejected on a live generation: remove a document
     *        Typesense rejects and report it, instead of throwing
     * @return list<int> rejected ids (only when tolerated), already removed
     * @throws BatchImportException on a rejection that is not tolerated
     */
    public function apply(array $ids, string $collection, bool $tolerateRejected = false): array
    {
        $source = new OmekaSourceRepository($this->connection, $this->profile);
        // Targeted: resolve only the authorities this batch links to.
        $assembler = new DocumentAssembler(
            $source,
            MapperRegistry::default()->create($this->profile, $this->connection, true),
            $this->profile,
        );
        $rejected = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), self::BATCH) as $chunk) {
            $rows = $source->rows($chunk);
            $docs = $assembler->documents($rows);
            if ($docs !== []) {
                try {
                    $this->ops->import($collection, array_values($docs));
                } catch (BatchImportException $error) {
                    if (!$tolerateRejected) {
                        throw $error;
                    }
                    $failed = array_map('intval', $error->failedIds());
                    // Fail closed: the old copy may carry metadata that has
                    // since changed, such as a value just made private.
                    $this->ops->deleteDocuments($collection, $failed);
                    $this->logger->warn(sprintf(
                        'DRESearch: Typesense rejected %d "%s" document(s): %s',
                        count($failed),
                        $this->profile->name(),
                        implode(' | ', $error->errors()),
                    ), ['profile' => $this->profile->name(), 'collection' => $collection, 'ids' => $failed, 'errors' => $error->errors()]);
                    array_push($rejected, ...$failed);
                }
            }
            $present = array_map(static fn(array $row): int => (int) $row['id'], $rows);
            $removed = array_diff($chunk, $present);
            if ($removed !== []) {
                $this->ops->deleteDocuments($collection, $removed);
            }
        }
        return $rejected;
    }

    /**
     * Apply and acknowledge the profile's queue on a live generation.
     *
     * A document Typesense rejects (a schema violation in the source data) is
     * removed from the live generation and its queue row acknowledged, so one
     * bad record cannot hold the whole corpus paused behind it; the ids are
     * logged and recorded for the admin page. Only the revision each row had
     * when it was read is acknowledged: an edit arriving meanwhile stays queued.
     *
     * @param Closure():bool|null $cancel checked before every batch
     * @param Closure():void|null $beat called after every batch (lease heartbeat)
     * @return list<int> ids rejected and removed
     */
    public function drain(ChangeQueue $queue, string $collection, ?Closure $cancel = null, ?Closure $beat = null): array
    {
        $rejected = [];
        while ($rows = $queue->page($this->profile->name())) {
            if ($cancel !== null && $cancel()) {
                break;
            }
            array_push($rejected, ...$this->apply(array_map('intval', array_column($rows, 'item_id')), $collection, true));
            ($this->invalidate)();
            $queue->acknowledge($this->profile->name(), $rows);
            if ($beat !== null) {
                $beat();
            }
        }
        $rejected = array_values(array_unique($rejected));
        if ($rejected !== []) {
            $this->logger->err(sprintf(
                'DRESearch: Typesense rejected %d "%s" document(s); they were removed from search until fixed: %s',
                count($rejected),
                $this->profile->name(),
                implode(', ', array_slice($rejected, 0, 50)),
            ), ['profile' => $this->profile->name(), 'collection' => $collection, 'ids' => array_slice($rejected, 0, 500)]);
            $this->stateStore?->recordRejected($this->profile->name(), $rejected);
        }
        return $rejected;
    }
}
