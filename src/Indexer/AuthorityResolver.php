<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;
use DRESearch\Settings\SearchProfile;

/**
 * Loads, once per reindex, a compact lookup of every authority item that backs
 * a facet (the items in the configured item sets). For each authority id it
 * keeps what the mapper needs to disambiguate the shared-property facets:
 *
 *   - title       : the authority's display title
 *   - sets        : which tracked item sets it belongs to (project vs other
 *                   dcterms:isPartOf; digitisation vs genre on dcterms:format)
 *   - typeItemId  : its own dcterms:type target (lcsh vs tag on dcterms:subject;
 *                   country vs city/region on dcterms:spatial)
 *   - partOfId    : its dcterms:isPartOf target (city/region → country)
 *
 * A few thousand rows; comfortably in memory regardless of corpus size. A full
 * rebuild {@see load()}s them once; incremental batches {@see prime()} only the
 * ids they reference.
 */
final class AuthorityResolver
{
    /** @var array<int, array{title:string, sets:array<int,bool>, typeItemId:?int, partOfId:?int}> */
    private array $byId = [];
    /** @var array<int,true> ids already resolved by {@see prime()} */
    private array $primed = [];
    private bool $complete = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly SearchProfile $profile,
    ) {
    }

    /** Whole-corpus load for a full rebuild: every tracked authority, once. */
    public function load(): void
    {
        $this->byId = [];
        $this->primed = [];
        $this->complete = true;
        $this->loadScoped(null);
    }

    /**
     * Incremental batches resolve only the authorities their values link to,
     * instead of reloading every tracked authority for each 100-id batch. The
     * entries are exactly what {@see load()} would produce for those ids, plus
     * their isPartOf parents (city/region → country). No-op after a full load.
     *
     * @param list<int> $ids linked resource ids referenced by the batch
     */
    public function prime(array $ids): void
    {
        if ($this->complete) {
            return;
        }
        $missing = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !isset($this->primed[$id])) {
                $this->primed[$id] = true;
                $missing[] = $id;
            }
        }
        if ($missing === []) {
            return;
        }
        $loaded = $this->loadScoped($missing);
        $parents = [];
        foreach ($loaded as $id) {
            $parent = $this->byId[$id]['partOfId'] ?? null;
            if ($parent !== null) {
                $parents[] = $parent;
            }
        }
        if ($parents !== []) {
            $this->prime($parents);
        }
    }

    /**
     * @param list<int>|null $ids null = every member of the tracked item sets
     * @return list<int> ids loaded into the lookup
     */
    private function loadScoped(?array $ids): array
    {
        $sets = $this->profile->allItemSets();
        if (!$sets) {
            return [];
        }
        $setList = implode(',', array_map('intval', $sets));
        $only = $ids !== null ? ' AND item_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '';

        // 1. Item-set membership.
        $sql = "SELECT item_id, item_set_id FROM item_item_set JOIN resource r ON r.id = item_id AND r.is_public = 1 WHERE item_set_id IN ($setList)" . $only;
        $loaded = [];
        foreach ($this->connection->executeQuery($sql)->fetchAllNumeric() as [$itemId, $setId]) {
            $itemId = (int) $itemId;
            $this->byId[$itemId] ??= ['title' => '', 'sets' => [], 'typeItemId' => null, 'partOfId' => null];
            $this->byId[$itemId]['sets'][(int) $setId] = true;
            $loaded[$itemId] = true;
        }

        if (!$loaded) {
            return [];
        }
        $loadedIds = array_keys($loaded);
        $idList = implode(',', $loadedIds);

        // 2. Public title values (the cached Omeka title may originate from a private value).
        foreach ((new OmekaSourceRepository($this->connection, $this->profile))->publicTitles($loadedIds) as $id => $title) {
            $this->byId[$id]['title'] = $title;
        }

        // 3. dcterms:type and dcterms:isPartOf targets (the discriminators).
        $sql = "SELECT v.resource_id, CONCAT(vo.prefix, ':', p.local_name) AS term, v.value_resource_id
                FROM value v
                JOIN property p ON v.property_id = p.id
                JOIN vocabulary vo ON p.vocabulary_id = vo.id
                WHERE v.resource_id IN ($idList)
                  AND v.is_public = 1
                  AND v.value_resource_id IN (SELECT id FROM resource WHERE is_public = 1)
                  AND CONCAT(vo.prefix, ':', p.local_name) IN ('dcterms:type', 'dcterms:isPartOf')";
        foreach ($this->connection->executeQuery($sql)->fetchAllNumeric() as [$rid, $term, $vrid]) {
            $rid = (int) $rid;
            if (!isset($this->byId[$rid])) {
                continue;
            }
            if ($term === 'dcterms:type') {
                $this->byId[$rid]['typeItemId'] = (int) $vrid;
            } elseif ($term === 'dcterms:isPartOf') {
                $this->byId[$rid]['partOfId'] = (int) $vrid;
            }
        }
        return $loadedIds;
    }

    public function count(): int
    {
        return count($this->byId);
    }

    public function title(int $id): ?string
    {
        $t = $this->byId[$id]['title'] ?? '';
        return $t !== '' ? $t : null;
    }

    public function inSet(int $id, ?int $setId): bool
    {
        return $setId !== null && !empty($this->byId[$id]['sets'][$setId]);
    }

    public function typeItemId(int $id): ?int
    {
        return $this->byId[$id]['typeItemId'] ?? null;
    }

    public function partOfId(int $id): ?int
    {
        return $this->byId[$id]['partOfId'] ?? null;
    }
}
