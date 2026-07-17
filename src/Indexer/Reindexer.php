<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use Closure;
use Doctrine\DBAL\Connection;
use DRESearch\Indexer\Exception\BatchImportException;
use DRESearch\Indexer\Exception\ReindexCancelledException;
use DRESearch\Indexer\Exception\VerificationException;
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
        private readonly ?RebuildStateStore $stateStore = null,
        private readonly int $retentionDays = 30,
        private readonly string $jobId = 'manual',
        private readonly ?Closure $cancel = null,
    ) {
        $this->alias = $profile->collection();
        $this->valueTerms = $profile->readProperties();
    }

    /**
     * @return array{documents:int,attempted:int,failed:int,collection:string,previous:?string,duration_ms:int}
     */
    public function run(): array
    {
        $started = hrtime(true);
        ($this->log)(sprintf("Starting reindex of '%s'…", $this->profile->name()));
        $lock = new RebuildLock(
            $this->connection,
            $this->profile->name(),
            $this->alias,
            $this->stateStore,
        );
        $lock->acquire();
        $collection = '';
        $previous = null;
        $created = false;
        $promoted = false;
        $attempted = 0;
        $imported = 0;
        $failed = 0;

        try {
            $previous = $this->aliasTarget();
            $this->recoverOrphanedCollections($previous);
            $base = $this->collectionBase();
            $collection = $base . 'g' . (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('YmdHisv')
                . '_' . bin2hex(random_bytes(3));
            $token = bin2hex(random_bytes(16));
            $this->throwIfCancelled();
            $mapper = $this->buildMapper();
            $this->stateStore?->markBuilding(
                $this->profile->name(),
                $this->alias,
                $collection,
                $token,
                $this->jobId,
            );
            // From this point the name is owned by this session. Attempt cleanup
            // even if the create response is lost after Typesense accepted it.
            $created = true;
            $this->client->collections->create((new SchemaProvider())->collection($collection, $this->profile));
            ($this->log)(sprintf('Created staging collection %s', $collection));

            $itemLink = $this->profile->itemLink();
            $reverseLinks = $this->profile->reverseLinks();
            $lastId = 0;
            $batch = [];

            $predicate = $this->sourcePredicate();
            $sql = 'SELECT id, title, is_public FROM resource'
                . ' WHERE resource_type = :rt AND id > :lastId'
                . ($predicate !== '' ? ' AND ' . $predicate : '')
                . ' ORDER BY id ASC LIMIT ' . self::PAGE;
            $params = ['rt' => Item::class];

            while (true) {
                $this->throwIfCancelled();
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
                        $attempted += count($batch);
                        try {
                            $imported += $this->flush($collection, $batch);
                        } catch (BatchImportException $e) {
                            $imported += $e->successful();
                            $failed += count($e->failedIds());
                            throw $e;
                        }
                        $batch = [];
                        ($this->log)(sprintf('Imported %d documents…', $imported));
                        $this->throwIfCancelled();
                    }
                }
            }

            if ($batch) {
                $attempted += count($batch);
                try {
                    $imported += $this->flush($collection, $batch);
                } catch (BatchImportException $e) {
                    $imported += $e->successful();
                    $failed += count($e->failedIds());
                    throw $e;
                }
            }

            $this->throwIfCancelled();
            try {
                $this->stateStore?->markVerifying($this->profile->name(), $attempted, $imported, $failed);
            } catch (\Throwable $stateError) {
                ($this->log)(sprintf('Could not persist verifying state: %s', $stateError->getMessage()));
            }
            $info = $this->client->collections[$collection]->retrieve();
            $verified = (int) ($info['num_documents'] ?? -1);
            if ($verified !== $imported || $imported !== $attempted) {
                throw new VerificationException(sprintf(
                    'Staging verification failed: attempted=%d imported=%d stored=%d.',
                    $attempted,
                    $imported,
                    $verified,
                ));
            }

            $this->throwIfCancelled();
            $this->client->aliases->upsert($this->alias, ['collection_name' => $collection]);
            $promoted = true;
            $durationMs = $this->durationMs($started);
            try {
                $this->stateStore?->markLive(
                    $this->profile->name(),
                    $collection,
                    $previous,
                    $durationMs,
                    $attempted,
                    $imported,
                    $failed,
                );
            } catch (\Throwable $stateError) {
                // The verified alias is already live. Do not report the rebuild as
                // failed or attempt to delete it because operational metadata lagged.
                ($this->log)(sprintf('Alias promoted, but rebuild state could not be persisted: %s', $stateError->getMessage()));
            }
            ($this->log)(sprintf("Promoted alias '%s' → '%s' (rollback: %s)", $this->alias, $collection, $previous ?? 'none'));

            $this->cleanupRetiredCollections($collection, $previous);
            ($this->log)(sprintf('Done — %d documents verified and promoted.', $imported));

            return [
                'documents' => $imported,
                'attempted' => $attempted,
                'failed' => $failed,
                'collection' => $collection,
                'previous' => $previous,
                'duration_ms' => $durationMs,
            ];
        } catch (\Throwable $e) {
            $deleted = false;
            if ($created && !$promoted) {
                $deleted = $this->deleteOwnedStaging($collection);
            }
            $status = $e instanceof ReindexCancelledException ? 'cancelled' : 'failed';
            $code = match (true) {
                $e instanceof BatchImportException => 'batch_import_failed',
                $e instanceof VerificationException => 'document_count_mismatch',
                $e instanceof ReindexCancelledException => 'cancelled',
                default => 'rebuild_failed',
            };
            try {
                if ($collection !== '') {
                    if ($deleted) {
                        $this->stateStore?->forgetGeneration($collection);
                    } else {
                        $this->stateStore?->markGeneration($collection, $status);
                    }
                }
                $this->stateStore?->markTerminal(
                    $this->profile->name(),
                    $status,
                    $code,
                    $this->durationMs($started),
                    $attempted,
                    $imported,
                    $failed,
                );
            } catch (\Throwable $stateError) {
                ($this->log)(sprintf('Could not persist failed rebuild state: %s', $stateError->getMessage()));
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Incremental path: re-map ONE source item and upsert it straight into the
     * live collection (the alias), reusing the same per-item loaders + mapper as
     * {@see run()}. The production {@see run()} path is untouched.
     *
     * Returns false when the id isn't a source resource of THIS profile (so the
     * caller can try the next profile); true after a successful upsert.
     *
     * Scope note: this refreshes the saved item's OWN document. The reverse-link
     * aggregates it contributes to on OTHER records (e.g. a person's item_count
     * when a research item that credits them is edited) are corpus-wide and are
     * left to drift until the next full reindex — the same trade-off the bulk
     * reindexer implies.
     *
     * Throws on a Typesense import failure so the caller can log it; never blocks
     * the Omeka save (the IncrementalIndexer wraps this in try/catch).
     */
    public function indexOne(int $id): bool
    {
        return $this->syncOne($id) === 'upserted';
    }

    /**
     * Converge one document with current profile scope.
     *
     * @return 'upserted'|'deleted'|'missing_alias'|'ignored'
     */
    public function syncOne(int $id): string
    {
        if ($id <= 0) {
            return 'ignored';
        }
        if ($this->aliasTarget() === null) {
            return 'missing_alias';
        }

        $predicate = $this->sourcePredicate();
        $sql = 'SELECT id, title, is_public FROM resource'
            . ' WHERE resource_type = :rt AND id = :id'
            . ($predicate !== '' ? ' AND ' . $predicate : '');
        $row = $this->connection
            ->executeQuery($sql, ['rt' => Item::class, 'id' => $id])
            ->fetchAssociative();
        if ($row === false) {
            $this->deleteOne($id);
            return 'deleted';
        }

        $doc = $this->buildDoc($row, $this->buildMapper());

        $result = ImportResult::fromResponse(
            $this->client->collections[$this->alias]->documents->import([$doc], ['action' => 'upsert']),
            [$doc],
        );
        if (!$result->isComplete()) {
            throw new BatchImportException(
                $this->alias,
                $result->successful(),
                $result->failedIds(),
                $result->errors(),
            );
        }
        return 'upserted';
    }

    /**
     * Incremental path: remove one document from the live collection by id.
     * Idempotent on the Typesense side; the caller swallows a 404 (the item was
     * never indexed, or already cleared by a reindex).
     */
    public function deleteOne(int $id): void
    {
        try {
            $this->client->collections[$this->alias]->documents[(string) $id]->delete();
        } catch (\Throwable $e) {
            if (!$this->isNotFound($e)) {
                throw $e;
            }
        }
    }

    /**
     * Build one Typesense document from a source `resource` row, loading just
     * that item's values / thumbnail / reverse-link figures. The single-item
     * counterpart to {@see run()}'s page loop; kept separate so run() keeps its
     * page-level batch loading (loading per-item there would be N× the queries).
     *
     * @param array{id:int|string, title:?string, is_public:int|bool} $row
     * @return array<string,mixed>
     */
    private function buildDoc(array $row, MapperInterface $mapper): array
    {
        $id = (int) $row['id'];
        $ids = [$id];

        $valuesByItem = $this->loadValues($ids);
        $thumbnails   = $this->loadThumbnails($ids);
        $itemLink     = $this->profile->itemLink();
        $reverseLinks = $this->profile->reverseLinks();
        $counts = $itemLink !== null ? $this->loadItemCounts($ids, $itemLink) : [];
        [$reverseCounts, $reverseRoles] = $reverseLinks !== null
            ? $this->loadReverseLinks($ids, $reverseLinks)
            : [[], []];

        $item = [
            'id'        => $id,
            'title'     => (string) ($row['title'] ?? ''),
            'is_public' => (bool) $row['is_public'],
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

        return $mapper->map($item, $valuesByItem[$id] ?? [], $thumbnails[$id] ?? null);
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
        if ($this->profile->kind() === 'podcast') {
            return new PodcastMapper($this->profile);
        }
        if ($this->profile->kind() === 'video') {
            return new VideoMapper($this->profile);
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
            . " AND CONCAT(vo.prefix, ':', p.local_name) IN ($termList)"
            . ' ORDER BY v.resource_id ASC, v.property_id ASC, v.id ASC';

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
     * Thumbnail derivative URL per source item. By default the item's own first
     * thumbnailed media; when the profile sets `thumbnail_property`, the thumbnail
     * is taken from the resource that property links to instead (podcasts hop
     * dcterms:isPartOf → the series item, whose image is the episode's logo, since
     * the episode's own media is the audio file).
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadThumbnails(array $ids): array
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
    private function mediaThumbnails(array $ids): array
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

    /**
     * Resolve each source item's thumbnail from the resource its `$term` property
     * links to (its first linked target), using that target's own first thumbnailed
     * media. Used by the podcasts corpus to show the series logo on every episode.
     * The property id is a validated int, inlined like the other source predicates.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadThumbnailsVia(array $ids, string $term): array
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
            . ' AND value_resource_id IS NOT NULL'
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

    /** @param list<array<string,mixed>> $docs */
    private function flush(string $collection, array $docs): int
    {
        $result = ImportResult::fromResponse(
            $this->client->collections[$collection]->documents->import($docs, ['action' => 'upsert']),
            $docs,
        );
        if (!$result->isComplete()) {
            throw new BatchImportException(
                $collection,
                $result->successful(),
                $result->failedIds(),
                $result->errors(),
            );
        }
        return $result->successful();
    }

    private function aliasTarget(): ?string
    {
        try {
            $alias = $this->client->aliases[$this->alias]->retrieve();
            $target = is_array($alias) ? (string) ($alias['collection_name'] ?? '') : '';
            return $target !== '' ? $target : null;
        } catch (\Throwable $e) {
            if ($this->isNotFound($e)) {
                return null;
            }
            throw $e;
        }
    }

    private function deleteOwnedStaging(string $collection): bool
    {
        try {
            $this->client->collections[$collection]->delete();
            ($this->log)(sprintf('Deleted session staging collection %s', $collection));
            return true;
        } catch (\Throwable $e) {
            if ($this->isNotFound($e)) {
                return true;
            }
            ($this->log)(sprintf('Could not delete session staging collection %s: %s', $collection, $e->getMessage()));
            return false;
        }
    }

    private function cleanupRetiredCollections(string $live, ?string $rollback): void
    {
        if ($this->stateStore === null) {
            return;
        }
        $keep = array_values(array_filter([$live, $rollback], 'is_string'));
        foreach ($this->stateStore->cleanupCandidates($this->profile->name(), $keep, $this->retentionDays) as $name) {
            try {
                $this->client->collections[$name]->delete();
                $this->stateStore->forgetGeneration($name);
                ($this->log)(sprintf('Deleted retired owned collection %s', $name));
            } catch (\Throwable $e) {
                ($this->log)(sprintf('Could not delete retired collection %s: %s', $name, $e->getMessage()));
            }
        }
    }

    /**
     * Lock ownership proves no earlier worker is still writing. Remove its
     * unpublished, metadata-owned staging collections, while preserving and
     * reconciling any collection that is already the live alias target.
     */
    private function recoverOrphanedCollections(?string $liveTarget): void
    {
        if ($this->stateStore === null) {
            return;
        }
        foreach ($this->stateStore->orphanedCollections($this->profile->name()) as $name) {
            if ($name === $liveTarget) {
                $this->stateStore->markGeneration($name, 'live');
                continue;
            }
            try {
                $this->client->collections[$name]->delete();
                $this->stateStore->forgetGeneration($name);
                ($this->log)(sprintf('Removed orphaned staging collection %s', $name));
            } catch (\Throwable $e) {
                if ($this->isNotFound($e)) {
                    $this->stateStore->forgetGeneration($name);
                    continue;
                }
                $this->stateStore->markGeneration($name, 'failed');
                ($this->log)(sprintf('Could not remove orphaned staging collection %s: %s', $name, $e->getMessage()));
            }
        }
    }

    private function throwIfCancelled(): void
    {
        if ($this->cancel !== null && ($this->cancel)()) {
            throw new ReindexCancelledException(sprintf('Reindex of "%s" was cancelled before promotion.', $this->profile->name()));
        }
    }

    private function durationMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    private function isNotFound(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'not found')
            || str_contains($message, '404')
            || str_contains($message, 'could not find');
    }
}
