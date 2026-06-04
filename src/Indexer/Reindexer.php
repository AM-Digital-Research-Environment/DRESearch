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

        $itemLink = $this->profile->itemLink();
        $reverseLinks = $this->profile->reverseLinks();
        $total = 0;
        $lastId = 0;
        $batch = [];

        // Source scope: the profile's base template/item-set, plus any extra
        // sources OR'd in (e.g. Locations also pulls geocoded institutions). The
        // predicate inlines validated ints, so :rt and :lastId stay the only
        // bound params and keyset pagination is unchanged.
        $predicate = $this->sourcePredicate();
        $sql = 'SELECT id, title, is_public FROM resource'
            . ' WHERE resource_type = :rt AND id > :lastId'
            . ($predicate !== '' ? ' AND ' . $predicate : '')
            . ' ORDER BY id ASC LIMIT ' . self::PAGE;
        $params = ['rt' => Item::class];

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
            [$reverseCounts, $reverseRoles] = $reverseLinks !== null
                ? $this->loadReverseLinks($ids, $reverseLinks)
                : [[], []];

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
                if ($reverseLinks !== null) {
                    $item['counts'] = [];
                    foreach ($reverseCounts as $field => $map) {
                        $item['counts'][$field] = $map[$id] ?? 0;
                    }
                    $item['roles'] = $reverseRoles[$id] ?? [];
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

    /**
     * The source-resource WHERE predicate for {@see run()}: the profile's base
     * template/item-set scope, plus each configured `extra_sources` entry OR'd
     * in. Returns '' when the corpus has no scope at all (index every item).
     *
     * Validated integers (template ids, item-set ids, resolved property ids) are
     * inlined — as in {@see reverseRolesPerProperty()} — so the paged source
     * query keeps :rt and :lastId as its only bound parameters. An extra source
     * whose `require_property` is unknown on this instance is skipped, so the
     * corpus still indexes its primary set. Places and the geocoded institutions
     * are disjoint, and a single SELECT over `(A OR B)` returns each id once, so
     * no de-duplication is needed.
     */
    private function sourcePredicate(): string
    {
        $groups = [];

        $base = $this->scopeClause($this->profile->templateId(), $this->profile->itemSetId(), null);
        if ($base !== '') {
            $groups[] = $base;
        }

        foreach ($this->profile->extraSources() as $src) {
            $propId = null;
            if (($src['require_property'] ?? null) !== null) {
                $propId = $this->propertyId((string) $src['require_property']);
                if ($propId === null) {
                    continue; // property absent here — don't fold this source in
                }
            }
            $clause = $this->scopeClause($src['template_id'] ?? null, $src['item_set_id'] ?? null, $propId);
            if ($clause !== '') {
                $groups[] = $clause;
            }
        }

        if ($groups === []) {
            return '';
        }
        return count($groups) === 1 ? $groups[0] : '(' . implode(' OR ', $groups) . ')';
    }

    /**
     * One source group as an AND-combined SQL fragment with every integer inlined:
     * an optional resource_template_id, an optional item-set membership, and an
     * optional "has a value for property P" requirement. Returns '' if nothing is
     * constrained; parenthesised when it has >1 part so it composes safely under OR.
     */
    private function scopeClause(?int $templateId, ?int $itemSetId, ?int $requirePropId): string
    {
        $parts = [];
        if ($templateId !== null) {
            $parts[] = 'resource_template_id = ' . $templateId;
        }
        if ($itemSetId !== null) {
            $parts[] = 'id IN (SELECT item_id FROM item_item_set WHERE item_set_id = ' . $itemSetId . ')';
        }
        if ($requirePropId !== null) {
            $parts[] = 'id IN (SELECT resource_id FROM value WHERE property_id = ' . $requirePropId . ')';
        }
        if ($parts === []) {
            return '';
        }
        return count($parts) === 1 ? $parts[0] : '(' . implode(' AND ', $parts) . ')';
    }

    private function buildMapper(): MapperInterface
    {
        if ($this->profile->kind() === 'project') {
            return new ProjectMapper($this->profile);
        }
        if ($this->profile->kind() === 'publication') {
            return new PublicationMapper($this->profile);
        }
        if ($this->profile->kind() === 'person') {
            return new PersonMapper($this->profile);
        }
        if ($this->profile->kind() === 'section') {
            return new SectionMapper($this->profile);
        }
        if ($this->profile->kind() === 'organisation') {
            return new OrganisationMapper($this->profile);
        }
        if ($this->profile->kind() === 'term') {
            return new TermMapper($this->profile);
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
     * Project item-count: how many research items belong to each project
     * (a single reverse-link rule). Thin wrapper over {@see reverseCount}.
     *
     * @param list<int> $ids
     * @param array{from_template:int,property:string,public_only:bool} $itemLink
     * @return array<int, int>
     */
    private function loadItemCounts(array $ids, array $itemLink): array
    {
        return $this->reverseCount($ids, [
            'properties'    => [$itemLink['property']],
            'from_template' => $itemLink['from_template'],
            'public_only'   => !empty($itemLink['public_only']),
        ]);
    }

    /**
     * Reverse-links (person & organisation corpora): compute (a) per-bucket counts
     * of the records that reference each indexed entity and (b) the set of role
     * labels it earns from the relationships configured in `reverse_links`. Each
     * fixed-label role rule (and each count bucket) is one {@see reverseCount}
     * query; a `per_property` role rule is one {@see reverseRolesPerProperty} query
     * that fans out into one label per relator property in use.
     *
     * @param list<int> $ids
     * @param array{counts?:array<string,array<string,mixed>>,roles?:list<array<string,mixed>>} $links
     * @return array{0:array<string,array<int,int>>, 1:array<int,list<string>>}
     *         [ field => [entityId => count], entityId => [role labels] ]
     */
    private function loadReverseLinks(array $ids, array $links): array
    {
        $counts = [];
        foreach (($links['counts'] ?? []) as $field => $rule) {
            $counts[(string) $field] = $this->reverseCount($ids, $rule);
        }

        $roleSets = [];
        foreach (($links['roles'] ?? []) as $rule) {
            // Per-property rule: one role per DISTINCT property referencing the
            // entity (e.g. every marcrel:* relator a person holds on research
            // items), labelled by the source template's alternate label — instead
            // of collapsing them into a single fixed-label bucket.
            if (!empty($rule['per_property'])) {
                foreach ($this->reverseRolesPerProperty($ids, $rule) as $pid => $labels) {
                    foreach ($labels as $label) {
                        $roleSets[$pid][$label] = true; // set semantics — dedupe shared labels
                    }
                }
                continue;
            }
            $label = (string) ($rule['label'] ?? '');
            if ($label === '') {
                continue;
            }
            foreach ($this->reverseCount($ids, $rule) as $pid => $cnt) {
                if ($cnt > 0) {
                    $roleSets[$pid][$label] = true; // set semantics — dedupe shared labels
                }
            }
        }
        $roles = [];
        foreach ($roleSets as $pid => $labelSet) {
            $roles[$pid] = array_keys($labelSet);
        }

        return [$counts, $roles];
    }

    /**
     * Count, per referenced target id, the DISTINCT source resources that point
     * at it — optionally narrowed to specific properties and/or a source template
     * or item set, and to public sources only. The reverse-link primitive behind
     * both the project item-count and the person counts/roles. One query per rule.
     *
     * @param list<int> $ids
     * @param array{properties?:?list<string>, from_template?:?int, from_item_set?:?int, public_only?:bool} $rule
     * @return array<int, int>
     */
    private function reverseCount(array $ids, array $rule): array
    {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '') {
            return [];
        }

        $where = ["v.value_resource_id IN ($idList)"];
        $params = [];

        $props = $rule['properties'] ?? null;
        if (is_array($props) && $props !== []) {
            $propIds = [];
            foreach ($props as $term) {
                $pid = $this->propertyId($term);
                if ($pid !== null) {
                    $propIds[] = $pid;
                }
            }
            if ($propIds === []) {
                return []; // none of the rule's properties exist on this instance
            }
            $where[] = 'v.property_id IN (' . implode(',', $propIds) . ')';
        }
        if (!empty($rule['from_template'])) {
            $where[] = 'r.resource_template_id = :tpl';
            $params['tpl'] = (int) $rule['from_template'];
        }
        if (!empty($rule['from_item_set'])) {
            $where[] = 'r.id IN (SELECT item_id FROM item_item_set WHERE item_set_id = :setId)';
            $params['setId'] = (int) $rule['from_item_set'];
        }
        if (!empty($rule['public_only'])) {
            $where[] = 'r.is_public = 1';
        }

        $sql = 'SELECT v.value_resource_id AS pid, COUNT(DISTINCT v.resource_id) AS cnt'
            . ' FROM value v'
            . ' JOIN resource r ON v.resource_id = r.id'
            . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY v.value_resource_id';

        $out = [];
        foreach ($this->connection->executeQuery($sql, $params)->fetchAllAssociative() as $row) {
            $out[(int) $row['pid']] = (int) $row['cnt'];
        }
        return $out;
    }

    /**
     * Per-property reverse roles. Like {@see reverseCount}, but instead of one
     * fixed label for the whole rule it emits one role label PER distinct property
     * that references the entity — so a person credited on research items surfaces
     * every marcrel relator they actually hold (Author, Photographer, Interviewee,
     * Translator, …), not a single "contributor" bucket. Only properties in use
     * yield a label (an empty relator simply produces no row), so the role facet
     * lists exactly the roles present in the data.
     *
     * Each role label is the source template's alternate label for the property
     * (the curator-facing role name), falling back to the property's own label,
     * then its local name. The rule narrows the same way {@see reverseCount} does —
     * by `vocabulary` (prefix, e.g. all marcrel:* roles) and/or an explicit
     * `properties` allowlist, an optional `from_template` / `from_item_set`, and
     * `public_only`. One query for the whole page.
     *
     * @param list<int> $ids
     * @param array{vocabulary?:string, properties?:?list<string>, from_template?:?int, from_item_set?:?int, public_only?:bool} $rule
     * @return array<int, list<string>> entityId => role labels (alphabetical)
     */
    private function reverseRolesPerProperty(array $ids, array $rule): array
    {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '') {
            return [];
        }

        $where = ["v.value_resource_id IN ($idList)"];
        $params = [];

        // Narrow to a whole vocabulary (e.g. the marcrel contributor-role family) …
        if (!empty($rule['vocabulary'])) {
            $where[] = 'vo.prefix = :vocab';
            $params['vocab'] = (string) $rule['vocabulary'];
        }
        // … and/or to an explicit property allowlist.
        $props = $rule['properties'] ?? null;
        if (is_array($props) && $props !== []) {
            $propIds = [];
            foreach ($props as $term) {
                $pid = $this->propertyId($term);
                if ($pid !== null) {
                    $propIds[] = $pid;
                }
            }
            if ($propIds === []) {
                return []; // none of the rule's properties exist on this instance
            }
            $where[] = 'v.property_id IN (' . implode(',', $propIds) . ')';
        }
        // from_template is a validated int, inlined (so it can also drive the
        // alternate-label join without colliding on a reused named parameter).
        $tpl = !empty($rule['from_template']) ? (int) $rule['from_template'] : null;
        if ($tpl !== null) {
            $where[] = 'r.resource_template_id = ' . $tpl;
        }
        if (!empty($rule['from_item_set'])) {
            $where[] = 'r.id IN (SELECT item_id FROM item_item_set WHERE item_set_id = :setId)';
            $params['setId'] = (int) $rule['from_item_set'];
        }
        if (!empty($rule['public_only'])) {
            $where[] = 'r.is_public = 1';
        }

        // Curator-facing role label: the source template's alternate label for the
        // property, else the property's own label, else its local name.
        $altJoin = $tpl !== null
            ? ' LEFT JOIN resource_template_property rtp'
                . ' ON rtp.resource_template_id = ' . $tpl . ' AND rtp.property_id = v.property_id'
            : '';
        $labelExpr = 'COALESCE('
            . ($tpl !== null ? "NULLIF(rtp.alternate_label, ''), " : '')
            . "NULLIF(p.label, ''), p.local_name)";

        $sql = "SELECT DISTINCT v.value_resource_id AS pid, $labelExpr AS lbl"
            . ' FROM value v'
            . ' JOIN resource r ON v.resource_id = r.id'
            . ' JOIN property p ON v.property_id = p.id'
            . ' JOIN vocabulary vo ON p.vocabulary_id = vo.id'
            . $altJoin
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY lbl';

        $out = [];
        foreach ($this->connection->executeQuery($sql, $params)->fetchAllAssociative() as $row) {
            $label = trim((string) ($row['lbl'] ?? ''));
            if ($label !== '') {
                $out[(int) $row['pid']][] = $label;
            }
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
