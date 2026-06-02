<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;
use DRESearch\Settings\FacetConfig;

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
 * A few thousand rows; comfortably in memory regardless of corpus size.
 */
final class AuthorityResolver
{
    /** @var array<int, array{title:string, sets:array<int,bool>, typeItemId:?int, partOfId:?int}> */
    private array $byId = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly FacetConfig $facetConfig,
    ) {
    }

    public function load(): void
    {
        $this->byId = [];

        $sets = $this->facetConfig->allItemSets();
        if (!$sets) {
            return;
        }
        $setList = implode(',', array_map('intval', $sets));

        // 1. Item-set membership.
        $sql = "SELECT item_id, item_set_id FROM item_item_set WHERE item_set_id IN ($setList)";
        foreach ($this->connection->executeQuery($sql)->fetchAllNumeric() as [$itemId, $setId]) {
            $itemId = (int) $itemId;
            $this->byId[$itemId] ??= ['title' => '', 'sets' => [], 'typeItemId' => null, 'partOfId' => null];
            $this->byId[$itemId]['sets'][(int) $setId] = true;
        }

        if (!$this->byId) {
            return;
        }
        $idList = implode(',', array_map('intval', array_keys($this->byId)));

        // 2. Titles.
        $sql = "SELECT id, title FROM resource WHERE id IN ($idList)";
        foreach ($this->connection->executeQuery($sql)->fetchAllNumeric() as [$id, $title]) {
            $id = (int) $id;
            if (isset($this->byId[$id])) {
                $this->byId[$id]['title'] = (string) $title;
            }
        }

        // 3. dcterms:type and dcterms:isPartOf targets (the discriminators).
        $sql = "SELECT v.resource_id, CONCAT(vo.prefix, ':', p.local_name) AS term, v.value_resource_id
                FROM value v
                JOIN property p ON v.property_id = p.id
                JOIN vocabulary vo ON p.vocabulary_id = vo.id
                WHERE v.resource_id IN ($idList)
                  AND v.value_resource_id IS NOT NULL
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
