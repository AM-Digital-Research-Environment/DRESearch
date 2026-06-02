<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use Closure;
use Doctrine\DBAL\Connection;
use DRESearch\Settings\FacetConfig;
use Omeka\Entity\Item;
use Typesense\Client;

/**
 * Rebuilds the Typesense index from the Omeka database.
 *
 * Strategy: build a fresh, timestamp-versioned collection, stream research
 * items into it PAGED (keyset by id) and batch-upserted, then swap the live
 * alias to it atomically and drop the previous versions. Reads never touch the
 * half-built collection, and memory stays flat regardless of corpus size — the
 * only thing held whole is the small authority lookup.
 *
 * The resource template and the property terms to read are taken from
 * {@see FacetConfig}, so the reindex follows the same config a reuser overrides.
 */
final class Reindexer
{
    /** Research items read per SQL page. */
    private const PAGE = 500;
    /** Documents per Typesense import call. */
    private const BATCH = 100;
    /** Versioned collection prefix; the alias points at the newest one. */
    private const BASE = 'dre_research_';

    /** Non-facet property terms always read (display, dates, creator roles). */
    private const FIXED_TERMS = [
        'dcterms:abstract', 'dcterms:description',
        'dcterms:issued', 'dcterms:created', 'dcterms:date',
        'dcterms:creator', 'dcterms:contributor', 'marcrel:aut', 'marcrel:edt',
    ];

    /** @var list<string> */
    private readonly array $valueTerms;

    /** @param Closure(string):void $log */
    public function __construct(
        private readonly Connection $connection,
        private readonly Client $client,
        private readonly string $alias,
        private readonly FacetConfig $facetConfig,
        private readonly Closure $log,
    ) {
        $this->valueTerms = array_values(array_unique(
            array_merge($this->facetConfig->properties(), self::FIXED_TERMS),
        ));
    }

    /** @return array{documents:int, collection:string} */
    public function run(): array
    {
        ($this->log)('Starting reindex…');

        $auth = new AuthorityResolver($this->connection, $this->facetConfig);
        $auth->load();
        ($this->log)(sprintf('Authority lookup: %d items', $auth->count()));
        $mapper = new ResearchItemMapper($auth, $this->facetConfig);

        $collection = self::BASE . gmdate('YmdHis');
        $this->client->collections->create((new SchemaProvider())->collection($collection, $this->facetConfig));
        ($this->log)(sprintf('Created collection %s', $collection));

        $templateId = $this->facetConfig->researchTemplateId();
        $total = 0;
        $lastId = 0;
        $batch = [];

        while (true) {
            $rows = $this->connection->executeQuery(
                'SELECT id, title, is_public FROM resource'
                . ' WHERE resource_type = :rt AND resource_template_id = :tid AND id > :lastId'
                . ' ORDER BY id ASC LIMIT ' . self::PAGE,
                ['rt' => Item::class, 'tid' => $templateId, 'lastId' => $lastId],
            )->fetchAllAssociative();

            if (!$rows) {
                break;
            }

            $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
            $lastId = (int) end($ids);
            $valuesByItem = $this->loadValues($ids);
            $thumbnails = $this->loadThumbnails($ids);

            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $batch[] = $mapper->map(
                    ['id' => $id, 'title' => (string) ($r['title'] ?? ''), 'is_public' => (bool) $r['is_public']],
                    $valuesByItem[$id] ?? [],
                    $thumbnails[$id] ?? null,
                );
                if (count($batch) >= self::BATCH) {
                    $this->flush($collection, $batch);
                    $total += count($batch);
                    $batch = [];
                    ($this->log)(sprintf('Indexed %d…', $total));
                }
            }
        }

        if ($batch) {
            $this->flush($collection, $batch);
            $total += count($batch);
        }

        $this->client->aliases->upsert($this->alias, ['collection_name' => $collection]);
        ($this->log)(sprintf("Alias '%s' → '%s'", $this->alias, $collection));

        $this->dropOldCollections($collection);
        ($this->log)(sprintf('Done — %d documents indexed.', $total));

        return ['documents' => $total, 'collection' => $collection];
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, list<array{vrid:?int, value:?string, title:?string}>>>
     */
    private function loadValues(array $ids): array
    {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '' || $this->valueTerms === []) {
            return [];
        }
        $termList = "'" . implode("','", $this->valueTerms) . "'";

        $sql = "SELECT v.resource_id AS rid, CONCAT(vo.prefix, ':', p.local_name) AS term,"
            . ' v.value_resource_id AS vrid, v.value AS val, t.title AS ttitle'
            . ' FROM value v'
            . ' JOIN property p ON v.property_id = p.id'
            . ' JOIN vocabulary vo ON p.vocabulary_id = vo.id'
            . ' LEFT JOIN resource t ON v.value_resource_id = t.id'
            . " WHERE v.resource_id IN ($idList)"
            . " AND CONCAT(vo.prefix, ':', p.local_name) IN ($termList)";

        $out = [];
        foreach ($this->connection->executeQuery($sql)->fetchAllAssociative() as $row) {
            $rid = (int) $row['rid'];
            $out[$rid][(string) $row['term']][] = [
                'vrid'  => $row['vrid'] !== null ? (int) $row['vrid'] : null,
                'value' => $row['val'] !== null ? (string) $row['val'] : null,
                'title' => $row['ttitle'] !== null ? (string) $row['ttitle'] : null,
            ];
        }
        return $out;
    }

    /**
     * First thumbnailed media per item → a relative derivative URL.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadThumbnails(array $ids): array
    {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '') {
            return [];
        }
        $sql = 'SELECT m.item_id AS iid, m.storage_id AS sid FROM media m'
            . " WHERE m.item_id IN ($idList) AND m.has_thumbnails = 1"
            . ' ORDER BY m.item_id ASC, m.position ASC, m.id ASC';

        $out = [];
        foreach ($this->connection->executeQuery($sql)->fetchAllAssociative() as $row) {
            $iid = (int) $row['iid'];
            if (isset($out[$iid])) {
                continue; // keep the first (lowest position) only
            }
            $sid = (string) ($row['sid'] ?? '');
            if ($sid !== '') {
                $out[$iid] = '/files/medium/' . $sid . '.jpg';
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $docs */
    private function flush(string $collection, array $docs): void
    {
        $results = $this->client->collections[$collection]->documents->import($docs, ['action' => 'upsert']);

        $failed = 0;
        $firstError = null;
        foreach ($results as $result) {
            if (is_array($result) && ($result['success'] ?? true) === false) {
                $failed++;
                $firstError ??= (string) ($result['error'] ?? 'unknown error');
            }
        }
        if ($failed > 0) {
            ($this->log)(sprintf('  %d document(s) failed in batch; first error: %s', $failed, (string) $firstError));
        }
    }

    private function dropOldCollections(string $keep): void
    {
        try {
            $collections = $this->client->collections->retrieve();
        } catch (\Throwable $e) {
            ($this->log)('Cleanup skipped: ' . $e->getMessage());
            return;
        }

        foreach ($collections as $collection) {
            $name = is_array($collection) ? (string) ($collection['name'] ?? '') : '';
            if ($name === '' || $name === $keep || !str_starts_with($name, self::BASE)) {
                continue;
            }
            try {
                $this->client->collections[$name]->delete();
                ($this->log)(sprintf('Dropped old collection %s', $name));
            } catch (\Throwable $e) {
                ($this->log)(sprintf('Could not drop %s: %s', $name, $e->getMessage()));
            }
        }
    }
}
