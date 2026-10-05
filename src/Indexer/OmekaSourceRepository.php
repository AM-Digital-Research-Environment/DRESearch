<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;
use DRESearch\Settings\SearchProfile;
use DRESearch\Settings\SourcePredicate;
use Omeka\Entity\Item;

/** Reads only metadata visible to an anonymous Omeka visitor. */
final class OmekaSourceRepository
{
    private array $propIdCache = [];
    private readonly array $valueTerms;

    public function __construct(private readonly Connection $connection, private readonly SearchProfile $profile)
    {
        $this->valueTerms = $profile->readProperties();
    }

    public function rows(?array $ids = null, int $after = 0, int $limit = 500): array
    {
        $scope = SourcePredicate::compile($this->profile, fn(string $term): ?int => $this->propertyId($term));
        $where = 'resource_type = :rt AND is_public = 1';
        $params = ['rt' => Item::class];
        if ($ids !== null) {
            if ($ids === []) {
                return [];
            }
            $where .= ' AND id IN (' . implode(',', array_map('intval', $ids)) . ')';
        } else {
            $where .= ' AND id > :after';
            $params['after'] = $after;
        }
        $rows = $this->connection->executeQuery(
            'SELECT id, title, is_public FROM resource WHERE ' . $where
            . ($scope !== '' ? ' AND ' . $scope : '') . ' ORDER BY id LIMIT ' . max(1, $limit),
            $params,
        )->fetchAllAssociative();
        $titles = $this->publicTitles(array_map('intval', array_column($rows, 'id')));
        foreach ($rows as &$row) {
            $row['title'] = $titles[(int) $row['id']] ?? '';
        }
        unset($row);
        return $rows;
    }

    /** Omeka's cached resource.title can be derived from a private title value. */
    public function publicTitles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $idList = implode(',', array_map('intval', $ids));
        $titles = [];
        foreach (
            $this->connection->executeQuery(
                "SELECT id, title FROM resource WHERE id IN ($idList) AND is_public = 1",
            )->fetchAllAssociative() as $row
        ) {
            $titles[(int) $row['id']] = (string) ($row['title'] ?? '');
        }
        $default = $this->propertyId('dcterms:title') ?? 0;
        $seen = [];
        $public = [];
        foreach (
            $this->connection->executeQuery(
                'SELECT v.resource_id, v.value, v.is_public FROM value v'
                . ' JOIN resource r ON r.id = v.resource_id AND r.is_public = 1'
                . ' LEFT JOIN resource_template rt ON rt.id = r.resource_template_id'
                . " WHERE v.resource_id IN ($idList) AND v.property_id = COALESCE(rt.title_property_id, $default)"
                . ' AND v.value IS NOT NULL ORDER BY v.resource_id, v.id',
            )->fetchAllAssociative() as $row
        ) {
            $id = (int) $row['resource_id'];
            if (!isset($seen[$id])) {
                $titles[$id] = '';
                $seen[$id] = true;
            }
            if (!isset($public[$id]) && $row['is_public'] && trim((string) $row['value']) !== '') {
                $titles[$id] = trim((string) $row['value']);
                $public[$id] = true;
            }
        }
        return $titles;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, list<array{vrid:?int, value:?string, uri:?string, title:?string}>>>
     */
    public function loadValues(array $ids): array
    {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '' || $this->valueTerms === []) {
            return [];
        }
        $terms = [];
        foreach ($this->valueTerms as $term) {
            $pid = $this->propertyId($term);
            if ($pid !== null) {
                $terms[$pid] = $term;
            }
        }
        if ($terms === []) {
            return [];
        }
        $propertyList = implode(',', array_keys($terms));

        $sql = "SELECT v.resource_id AS rid, v.property_id AS pid,"
            . ' v.value_resource_id AS vrid, v.value AS val, v.uri AS turi, t.title AS ttitle'
            . ' FROM value v'
            . ' LEFT JOIN resource t ON v.value_resource_id = t.id'
            . " WHERE v.resource_id IN ($idList)"
            . " AND v.property_id IN ($propertyList) AND v.is_public = 1"
            . ' AND (v.value_resource_id IS NULL OR t.is_public = 1)'
            . ' ORDER BY v.resource_id ASC, v.property_id ASC, v.id ASC';

        $out = [];
        $rows = $this->connection->executeQuery($sql)->fetchAllAssociative();
        $titles = $this->publicTitles(array_values(array_unique(array_filter(array_column($rows, 'vrid')))));
        foreach ($rows as $row) {
            $rid = (int) $row['rid'];
            $out[$rid][$terms[(int) $row['pid']]][] = [
                'vrid'  => $row['vrid'] !== null ? (int) $row['vrid'] : null,
                'value' => $row['val'] !== null ? (string) $row['val'] : null,
                'uri'   => $row['turi'] !== null ? (string) $row['turi'] : null,
                'title' => $row['vrid'] !== null ? ($titles[(int) $row['vrid']] ?? '') : null,
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
    public function loadItemCounts(array $ids, array $itemLink): array
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
    public function loadReverseLinks(array $ids, array $links): array
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
    public function reverseCount(array $ids, array $rule): array
    {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '') {
            return [];
        }

        $where = ["v.value_resource_id IN ($idList)", "v.is_public = 1", "r.is_public = 1"];
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
    public function reverseRolesPerProperty(array $ids, array $rule): array
    {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '') {
            return [];
        }

        $where = ["v.value_resource_id IN ($idList)", "v.is_public = 1", "r.is_public = 1"];
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
    public function propertyId(string $term): ?int
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
     * Thumbnail derivative URL per source item. By default the item's own first
     * thumbnailed media; when the profile sets `thumbnail_property`, the thumbnail
     * is taken from the resource that property links to instead (podcasts hop
     * dcterms:isPartOf → the series item, whose image is the episode's logo, since
     * the episode's own media is the audio file).
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function loadThumbnails(array $ids): array
    {
        $via = $this->profile->thumbnailFromProperty();
        if ($via !== null) {
            return $this->loadThumbnailsVia($ids, $via);
        }
        return $this->mediaThumbnails($ids);
    }

    /**
     * First thumbnailed media per item → a relative derivative URL, for the given
     * item ids. The shared primitive behind both the direct and the linked-resource
     * thumbnail paths.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function mediaThumbnails(array $ids): array
    {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '') {
            return [];
        }
        $sql = 'SELECT m.item_id AS iid, m.storage_id AS sid FROM media m'
            . ' JOIN resource mr ON mr.id = m.id AND mr.is_public = 1'
            . ' JOIN resource parent ON parent.id = m.item_id AND parent.is_public = 1'
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

    /**
     * Resolve each source item's thumbnail from the resource its `$term` property
     * links to (its first linked target), using that target's own first thumbnailed
     * media. Used by the podcasts corpus to show the series logo on every episode.
     * The property id is a validated int, inlined like the other source predicates.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function loadThumbnailsVia(array $ids, string $term): array
    {
        $propId = $this->propertyId($term);
        if ($propId === null) {
            return [];
        }
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '') {
            return [];
        }

        // Each source item → its first linked target (lowest value id).
        $sql = 'SELECT resource_id AS rid, value_resource_id AS vrid FROM value'
            . " WHERE resource_id IN ($idList) AND property_id = $propId"
            . ' AND is_public = 1 AND value_resource_id IN (SELECT id FROM resource WHERE is_public = 1)'
            . ' ORDER BY resource_id ASC, id ASC';

        $targetByItem = [];
        foreach ($this->connection->executeQuery($sql)->fetchAllAssociative() as $row) {
            $rid = (int) $row['rid'];
            if (isset($targetByItem[$rid])) {
                continue; // keep the first linked target only
            }
            $targetByItem[$rid] = (int) $row['vrid'];
        }
        if ($targetByItem === []) {
            return [];
        }

        $targetThumbs = $this->mediaThumbnails(array_values(array_unique($targetByItem)));

        $out = [];
        foreach ($targetByItem as $rid => $tid) {
            if (isset($targetThumbs[$tid])) {
                $out[$rid] = $targetThumbs[$tid];
            }
        }
        return $out;
    }
}
