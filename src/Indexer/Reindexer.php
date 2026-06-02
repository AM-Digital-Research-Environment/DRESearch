<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use Closure;
use Doctrine\DBAL\Connection;
use DRESearch\Settings\SearchProfile;
use Omeka\Entity\Item;
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

    private readonly string $alias;
    /** @var list<string> */
    private readonly array $valueTerms;
    /** @var array<string,?int> */
    private array $propIdCache = [];

    /** @param Closure(string):void $log */
    public function __construct(
        private readonly Connection $connection,
        private readonly Client $client,
        private readonly SearchProfile $profile,
        private readonly Closure $log,
    ) {
        $this->alias = $profile->collection();
        $this->valueTerms = $profile->readProperties();
    }

    /** @return array{documents:int, collection:string} */
    public function run(): array
    {
        ($this->log)(sprintf("Starting reindex of '%s'…", $this->profile->name()));

        $mapper = $this->buildMapper();

        $base = $this->collectionBase();
        $collection = $base . gmdate('YmdHis');
        $this->client->collections->create((new SchemaProvider())->collection($collection, $this->profile));
        ($this->log)(sprintf('Created collection %s', $collection));

        $templateId = $this->profile->templateId();
        $itemSetId = $this->profile->itemSetId();
        $itemLink = $this->profile->itemLink();
        $total = 0;
        $lastId = 0;
        $batch = [];

        $sql = 'SELECT id, title, is_public FROM resource'
            . ' WHERE resource_type = :rt AND id > :lastId';
        $params = ['rt' => Item::class];
        // A profile may scope by template, by item set, or both. Publications
        // span several templates but share one item set, so template_id is null
        // there and the set alone defines the corpus.
        if ($templateId !== null) {
            $sql .= ' AND resource_template_id = :tid';
            $params['tid'] = $templateId;
        }
        if ($itemSetId !== null) {
            $sql .= ' AND id IN (SELECT item_id FROM item_item_set WHERE item_set_id = :setId)';
            $params['setId'] = $itemSetId;
        }
        $sql .= ' ORDER BY id ASC LIMIT ' . self::PAGE;

        while (true) {
            $rows = $this->connection
                ->executeQuery($sql, ['lastId' => $lastId] + $params)
                ->fetchAllAssociative();

            if (!$rows) {
                break;
            }

            $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
            $lastId = (int) end($ids);
            $valuesByItem = $this->loadValues($ids);
            $thumbnails = $this->loadThumbnails($ids);
            $counts = $itemLink !== null ? $this->loadItemCounts($ids, $itemLink) : [];

            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $item = [
                    'id'        => $id,
                    'title'     => (string) ($r['title'] ?? ''),
                    'is_public' => (bool) $r['is_public'],
                ];
                if ($itemLink !== null) {
                    $item['item_count'] = $counts[$id] ?? 0;
                }
                $batch[] = $mapper->map($item, $valuesByItem[$id] ?? [], $thumbnails[$id] ?? null);
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

        $this->dropOldCollections($collection, $base);
        ($this->log)(sprintf('Done — %d documents indexed.', $total));

        return ['documents' => $total, 'collection' => $collection];
    }

    private function buildMapper(): MapperInterface
    {
        if ($this->profile->kind() === 'project') {
            return new ProjectMapper($this->profile);
        }
        if ($this->profile->kind() === 'publication') {
            return new PublicationMapper($this->profile);
        }

        $auth = new AuthorityResolver($this->connection, $this->profile);
        $auth->load();
        ($this->log)(sprintf('Authority lookup: %d items', $auth->count()));
        return new ResearchItemMapper($auth, $this->profile);
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

    /**
     * @param list<int> $ids
     * @return array<int, array<string, list<array{vrid:?int, value:?string, uri:?string, title:?string}>>>
     */
    private function loadValues(array $ids): array
    {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '' || $this->valueTerms === []) {
            return [];
        }
        $termList = "'" . implode("','", $this->valueTerms) . "'";

        $sql = "SELECT v.resource_id AS rid, CONCAT(vo.prefix, ':', p.local_name) AS term,"
            . ' v.value_resource_id AS vrid, v.value AS val, v.uri AS turi, t.title AS ttitle'
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
                'uri'   => $row['turi'] !== null ? (string) $row['turi'] : null,
                'title' => $row['ttitle'] !== null ? (string) $row['ttitle'] : null,
            ];
        }
        return $out;
    }

    /**
     * Count, per linked target id, the resources of `from_template` whose
     * `property` points at it — i.e. how many research items belong to each
     * project. One query per page.
     *
     * @param list<int> $ids
     * @param array{from_template:int,property:string,public_only:bool} $itemLink
     * @return array<int, int>
     */
    private function loadItemCounts(array $ids, array $itemLink): array
    {
        $idList = implode(',', array_map('intval', $ids));
        $propId = $this->propertyId($itemLink['property']);
        if ($idList === '' || $propId === null) {
            return [];
        }

        $publicClause = !empty($itemLink['public_only']) ? ' AND r.is_public = 1' : '';
        $sql = 'SELECT v.value_resource_id AS pid, COUNT(DISTINCT v.resource_id) AS cnt'
            . ' FROM value v'
            . ' JOIN resource r ON v.resource_id = r.id'
            . ' WHERE v.property_id = :pid AND r.resource_template_id = :tpl'
            . $publicClause
            . " AND v.value_resource_id IN ($idList)"
            . ' GROUP BY v.value_resource_id';

        $out = [];
        $result = $this->connection->executeQuery(
            $sql,
            ['pid' => $propId, 'tpl' => (int) $itemLink['from_template']],
        );
        foreach ($result->fetchAllAssociative() as $row) {
            $out[(int) $row['pid']] = (int) $row['cnt'];
        }
        return $out;
    }

    /** Resolve (and cache) a property id from its "prefix:local" term. */
    private function propertyId(string $term): ?int
    {
        if (array_key_exists($term, $this->propIdCache)) {
            return $this->propIdCache[$term];
        }
        [$prefix, $local] = array_pad(explode(':', $term, 2), 2, '');
        $id = $this->connection->executeQuery(
            'SELECT p.id FROM property p JOIN vocabulary vo ON p.vocabulary_id = vo.id'
            . ' WHERE vo.prefix = :prefix AND p.local_name = :local',
            ['prefix' => $prefix, 'local' => $local],
        )->fetchOne();
        return $this->propIdCache[$term] = ($id !== false ? (int) $id : null);
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

    private function dropOldCollections(string $keep, string $base): void
    {
        try {
            $collections = $this->client->collections->retrieve();
        } catch (\Throwable $e) {
            ($this->log)('Cleanup skipped: ' . $e->getMessage());
            return;
        }

        foreach ($collections as $collection) {
            $name = is_array($collection) ? (string) ($collection['name'] ?? '') : '';
            if ($name === '' || $name === $keep || !str_starts_with($name, $base)) {
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
