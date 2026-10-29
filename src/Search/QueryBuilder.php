<?php

declare(strict_types=1);

namespace DRESearch\Search;

use DRESearch\Settings\SearchProfile;

/**
 * Translates a structured search request from the client into Typesense search
 * parameters for one {@see SearchProfile}. The is_public:=true constraint is
 * hard-coded here (not taken from the request), so a crafted client payload can
 * never widen visibility.
 *
 * The searchable fields (query_by), the valid facet fields, and the date handling
 * (single origin year vs a start/end range) all come from the profile, so this
 * stays correct across corpora.
 *
 * Expected request keys (all optional):
 *   q             string  full-text query ('' = browse everything)
 *   page          int     1-based
 *   per_page      int     capped at PER_PAGE_MAX
 *   sort          string  relevance | newest | oldest | title
 *   filters       array   field => [values]  (validated against the profile)
 *   facets        array   facet fields to count (defaults to all profile facets)
 *   year_from/to  int     optional year bounds (overlap for range profiles)
 * A saved block scope is supplied only by the server through the constructor.
 */
final class QueryBuilder
{
    public const PER_PAGE_DEFAULT = 20;
    public const PER_PAGE_MAX = 50;
    public const MAX_PAGE = 250;

    /**
     * Export paging. The result-export menu pulls the CURRENT result set as a
     * bulk citation dump — {@see SearchProxy::export()} pages at EXPORT_PER_PAGE
     * (Typesense's per-request maximum) up to EXPORT_MAX_HITS total. Exports
     * follow the live sort, so a truncated export keeps the most relevant / newest
     * rows. Mirror EXPORT_MAX_HITS in the client (lib/export.ts) for the cap hint.
     */
    public const EXPORT_PER_PAGE = 250;
    public const EXPORT_MAX_HITS = 1000;
    /** Maximum geocoded documents returned to one map view. */
    public const MAP_MAX_HITS = 1000;

    /**
     * Sentinels wrapped around matched tokens instead of the default <mark>.
     * They are Unicode private-use code points, so they never collide with real
     * content and carry no HTML meaning — the client splits on them and renders
     * <mark> via text nodes (no HTML injection from field values). See
     * {@see SearchProxy::normalize()} and the Svelte lib/highlight.ts.
     */
    public const HL_START = "\u{E000}";
    public const HL_END = "\u{E001}";

    /**
     * Typesense stopword set applied to every non-browse query, so common English
     * function words ("the", "of", "and", …) don't dilute relevance. Provisioned by
     * {@see \DRESearch\Indexer\StopwordsSync} (data/stopwords.json) on every reindex
     * and via the Maintenance "Sync stopwords" action. {@see SearchProxy::search()}
     * retries without it if the set is missing on the server, so a fresh Typesense
     * volume degrades to unfiltered search rather than failing.
     */
    public const STOPWORDS_SET = \DRESearch\Indexer\StopwordsSync::SET_NAME;

    /**
     * Typesense 30 synonym set (data/synonyms.json, {@see \DRESearch\Indexer\SynonymsSync}):
     * the same place or language named in the archive's catalogue languages
     * ("Ivory Coast" finds "Côte d'Ivoire"). Referenced per query, so editing
     * it never needs a rebuild; SearchExecutor drops it if it is missing.
     */
    public const SYNONYM_SET = \DRESearch\Indexer\SynonymsSync::SET_NAME;

    /**
     * How many prefix/typo variations Typesense may expand per query token.
     *
     * Typesense defaults to 4, which a short query blows straight past: because
     * prefix search is on, "k" has to stand in for every token starting with k,
     * and the cap truncated the result set to a fraction of the real matches
     * (measured on research_items: `k` found 137 of 1299, `ke` 102 of 165). Worse,
     * the truncation interacts with `filter_by` — a narrower filter reaches deeper
     * into the same candidate space — so a filtered search could report MORE hits
     * than the unfiltered facet count claimed for that value.
     *
     * 512 is where the short-query tail closes on the dev corpus — `k` reaches the
     * same 1299 an exhaustive search finds, and `ke` and `ken` reach theirs at any
     * value ≥128 — while longer queries stay bit-for-bit unchanged (`kenya`,
     * `africa`, `islam`, `music` return exactly the counts they did at the
     * default). It also runs FASTER, because the default's escalating retry passes
     * cost more than one wider pass: `k` 415ms → 92ms, `ken` 76ms → 1ms, `africa`
     * 37ms → 3ms. Raising it further changes nothing (1000 matches 512).
     *
     * Deliberately NOT `exhaustive_search`, which reaches the same recall but also
     * lifts the typo-pass early exit for every query — "islam" goes 34 → 71 hits,
     * all of them typo matches (with num_typos=0 both settings return 34). That is
     * a relevance decision, not a correctness fix.
     *
     * Worth re-measuring if a corpus grows by an order of magnitude: the cap
     * exists for large collections, and the biggest here is ~4k documents.
     */
    public const MAX_CANDIDATES = 512;

    /** Server-side time budget for one search, in milliseconds. */
    public const SEARCH_CUTOFF_MS = 2000;

    /**
     * @param string|null $serverFilter saved block scope (validated by {@see FilterExpression})
     * @param list<int> $excludeIds documents with queued, not-yet-applied changes
     *                              ({@see ReadinessGate}); hidden until reconciled
     */
    public function __construct(
        private readonly SearchProfile $profile,
        private readonly ?string $serverFilter = null,
        private readonly array $excludeIds = [],
    ) {
    }

    /**
     * The live search: the {@see matching()} core plus everything a results
     * page shows — one page of hits, the sidebar's facet counts, the documents
     * without their search-only fields, match highlights, and analytics when
     * the client asked for this query to count.
     *
     * Every other mode starts from the same core and adds only what it uses,
     * rather than copying these params and unsetting the rest: a parameter
     * added here can no longer reach counts, exports, maps or recounts by
     * accident, and one added to the core reaches all of them by design.
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public function search(array $req): array
    {
        $params = array_merge(
            $this->matching($req),
            [
                'page'     => max(1, min(self::MAX_PAGE, (int) ($req['page'] ?? 1))),
                'per_page' => max(1, min(self::PER_PAGE_MAX, (int) ($req['per_page'] ?? self::PER_PAGE_DEFAULT))),
            ],
            $this->facetCounting($this->buildFacetBy($req)),
            $this->hiddenFields(),
            $this->highlighting($req),
        );
        if (!self::isBrowse($req) && !empty($req['record_query']) && !empty($req['analytics_id'])) {
            $params['enable_analytics'] = true;
            $params['x-typesense-user-id'] = $req['analytics_id'];
        }
        return $params;
    }

    /**
     * What decides WHICH documents match and in what order, shared by every
     * mode built from a visitor's request: the query and its fields, the
     * filter (visibility guard, pending-change exclusions, block scope, facet
     * selections, year window), the sort, recall and time bounds, and the
     * stopword and synonym sets. Counts, exports, maps and facet recounts are
     * honest only while they match exactly what the live search matches.
     * Analytics are off; only {@see search()} may turn them on.
     *
     * @param array<string,mixed> $req
     * @param string|null $except facet field whose own selections are lifted
     *                            ({@see facetCountsFor()})
     * @return array<string,mixed>
     */
    private function matching(array $req, ?string $except = null): array
    {
        $isBrowse = self::isBrowse($req);
        $params = [
            'enable_analytics' => false,
            'q'         => $isBrowse ? '*' : trim((string) $req['q']),
            'query_by'  => $this->profile->queryBy(),
            'filter_by' => $this->buildFilter($req, $except),
            'sort_by'   => $this->buildSort((string) ($req['sort'] ?? 'relevance'), $isBrowse),
            // Every mode built on this core inherits the same recall.
            'max_candidates' => self::MAX_CANDIDATES,
            // A pathological query (hundreds of tokens, typo and drop-token
            // passes) returns its best partial answer instead of holding a
            // PHP worker for the full transport timeout.
            'search_cutoff_ms' => self::SEARCH_CUTOFF_MS,
        ];
        if (!$isBrowse) {
            // Strip common English function words (data/stopwords.json) so "the",
            // "of", "and" don't dilute relevance. Only on a real query — browse
            // (q=*) ignores it, and SearchExecutor drops it and retries if the
            // set isn't on the server yet.
            $params['stopwords'] = self::STOPWORDS_SET;
            $params['synonym_sets'] = self::SYNONYM_SET;
        }
        return $params;
    }

    /** @param array<string,mixed> $req */
    private static function isBrowse(array $req): bool
    {
        return trim((string) ($req['q'] ?? '')) === '';
    }

    /**
     * Facet counts for a comma-separated field list. Facets are optional — a
     * corpus may have none (e.g. genres, languages), in which case facet_by is
     * omitted entirely rather than sent empty.
     *
     * @return array<string,mixed>
     */
    private function facetCounting(string $facetBy): array
    {
        return $facetBy === '' ? [] : ['facet_by' => $facetBy, 'max_facet_values' => 100];
    }

    /**
     * Fields never shipped with a returned document. Search-only fields (e.g. a
     * podcast transcript) are indexed for query_by but dropped from the returned
     * documents so a large value never bloats every hit; Typesense still returns
     * their highlighted snippet, so a transcript match still surfaces in the
     * card's "Matched in" line. is_public is the visibility guard, always true in
     * a public result; shipping it with every hit is noise.
     *
     * @return array{exclude_fields:string}
     */
    private function hiddenFields(): array
    {
        return ['exclude_fields' => implode(',', array_values(array_unique(array_merge(
            $this->profile->searchOnlyFields(),
            ['is_public'],
        ))))];
    }

    /**
     * Match marks, so each card can show *where* a result matched. Short fields
     * (title, linked-value facets, names) are highlighted in full — so a card can
     * highlight a whole chip/byline value — while the long free-text fields
     * (abstract, description, transcript) return a windowed snippet centred on
     * the match (the full text would scroll the match out of the clamped card, or
     * dump a whole transcript into a line). Nothing to mark on a browse.
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    private function highlighting(array $req): array
    {
        if (self::isBrowse($req)) {
            return [];
        }
        $longTextFields = ['abstract', 'description', 'transcript', 'fulltext'];
        $queryFields = array_values(array_filter(array_map(
            'trim',
            explode(',', $this->profile->queryBy())
        )));
        $fullFields = array_values(array_filter(
            $queryFields,
            static fn(string $f): bool => !in_array($f, $longTextFields, true)
        ));
        $params = [
            'highlight_fields' => implode(',', $queryFields),
            'enable_highlight_v1' => false,
            'highlight_start_tag' => self::HL_START,
            'highlight_end_tag' => self::HL_END,
            'highlight_affix_num_tokens' => 8,
        ];
        if ($fullFields !== []) {
            $params['highlight_full_fields'] = implode(',', $fullFields);
        }
        return $params;
    }

    /**
     * For a mode that renders no hit text (counts, recounts, exports, maps):
     * Typesense would otherwise highlight the query fields by default.
     *
     * @return array{highlight_fields:string}
     */
    private static function noHighlights(): array
    {
        return ['highlight_fields' => 'none'];
    }

    public function suggest(string $q): array
    {
        // Request only fields the suggestion subtitle might use (id/title + the
        // date field(s) + facets + display fields), so the payload stays small.
        $dateFields = $this->profile->hasDate()
            ? ($this->profile->isRangeDate() ? ['year_start', 'year_end'] : ['year'])
            : [];
        // Display fields, minus the search-only ones (e.g. a transcript) — a
        // suggestion row never needs the heavy text.
        $searchOnly = array_flip($this->profile->searchOnlyFields());
        $displayFields = array_values(array_filter(
            array_keys($this->profile->displayFields()),
            static fn(string $f): bool => !isset($searchOnly[$f])
        ));
        $include = array_merge(
            ['id', 'title'],
            $dateFields,
            $this->profile->fieldNames(),
            $displayFields,
        );
        return [
            'enable_analytics' => false,
            'highlight_fields' => 'none',
            'q'              => $q,
            'query_by'       => 'title',
            'prefix'         => true,
            // Autocomplete is the most prefix-sensitive path there is — it fires
            // on the first keystroke, exactly where the default cap truncates.
            'max_candidates' => self::MAX_CANDIDATES,
            'filter_by'      => $this->buildFilter([]),
            'sort_by'        => '_text_match:desc',
            'page'           => 1,
            'per_page'       => 6,
            'include_fields' => implode(',', array_values(array_unique($include))),
        ];
    }

    /**
     * One entry of a federated `multi_search` autocomplete: the per-profile
     * {@see suggest()} params plus this profile's `collection` (each multi_search
     * search targets its own collection). A smaller default per_page than the
     * single-corpus suggest keeps the combined dropdown bounded across all corpora.
     *
     * @return array<string,mixed>
     */
    public function suggestSearch(string $q, int $perPage = 5): array
    {
        $params = $this->suggest($q);
        $params['collection'] = $this->profile->collection();
        $params['per_page'] = max(1, $perPage);
        return $params;
    }

    /**
     * One entry of a federated `multi_search` whose only job is the match COUNT
     * for a corpus (the type tabs on the federated results page): the
     * {@see matching()} core, scoped to this profile's `collection`, with no
     * facets, highlights or documents — `found` is the total regardless of
     * per_page, and Typesense 30 accepts 0, so nothing is ranked or shipped just
     * to read a count.
     *
     * @param array<string,mixed> $req shared query bits: q, year_from, year_to
     * @return array<string,mixed>
     */
    public function countOnly(array $req): array
    {
        return array_merge(
            $this->matching($req),
            ['collection' => $this->profile->collection(), 'per_page' => 0, 'include_fields' => 'id'],
            self::noHighlights(),
        );
    }

    /**
     * One sub-search for a Typesense v30 union request. Pagination is supplied
     * globally to /multi_search, so the entry carries none; every collection
     * sorts by the same sortable string field on browse and the same relevance
     * field on a real query, which the corpus's own sort could not guarantee.
     * Facets are intentionally omitted: union responses do not expose them. Its
     * hits are rendered as cards, so it keeps the hidden fields and highlights.
     *
     * @return array<string,mixed>
     */
    public function union(string $q): array
    {
        $req = ['q' => $q];
        $params = array_merge(
            $this->matching($req),
            ['collection' => $this->profile->collection()],
            $this->hiddenFields(),
            $this->highlighting($req),
        );
        $params['sort_by'] = self::isBrowse($req) ? 'title:asc' : '_text_match:desc,title:asc';
        return $params;
    }

    /**
     * One page of a location-map pull: the {@see matching()} core (text, facet,
     * locked-filter and year scope) narrowed to documents carrying a valid
     * mapper-produced geopoint, with the payload trimmed to popup fields.
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public function map(array $req, int $page): array
    {
        $params = array_merge(
            $this->matching($req),
            $this->bulkPage($page),
            ['include_fields' => 'id,title,type_s,roles_ss,item_count,geo,_profile,_kind'],
            $this->hiddenFields(),
            self::noHighlights(),
        );
        $params['filter_by'] .= ' && has_coords:=true';
        return $params;
    }

    /**
     * Params for one page of a result-export pull: the {@see matching()} core —
     * the same query, filters, sort, year window and stopwords as the live
     * search, so the file holds what the visitor sees — tuned for a bulk citation
     * dump: a big page size, no facet counts, no highlight markup, and the hidden
     * fields (`is_public`, search-only texts such as transcripts) dropped. Paged
     * up to {@see EXPORT_MAX_HITS}.
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public function export(array $req, int $page): array
    {
        return array_merge(
            $this->matching($req),
            $this->bulkPage($page),
            $this->hiddenFields(),
            self::noHighlights(),
        );
    }

    /** @return array{page:int,per_page:int} one page of a bulk (export or map) pull */
    private function bulkPage(int $page): array
    {
        return ['page' => max(1, $page), 'per_page' => self::EXPORT_PER_PAGE];
    }

    /**
     * Facet fields that need a separate count pass so they stay MULTI-SELECT.
     *
     * A facet's own selection is part of `filter_by`, so Typesense can only ever
     * count the value(s) already chosen: pick one Type and every other Type
     * vanishes from the sidebar, which makes a checkbox list behave like a radio
     * group. The fix is the standard disjunctive-facet pass — recount that one
     * field with its own clause lifted, every OTHER field's filter still applied
     * (so the numbers remain honest: they are what you'd get by also ticking that
     * value). A field with no selection needs no correction; its counts already
     * sit under the full filter set.
     *
     * Only fields actually being faceted qualify — a filter on a non-sidebar
     * display field (e.g. an author chip from a card) has no list to keep open.
     *
     * @param array<string,mixed> $req
     * @return list<string>
     */
    public function refinedFacetFields(array $req): array
    {
        $filters = $req['filters'] ?? [];
        if (!is_array($filters) || $filters === []) {
            return [];
        }
        $refined = [];
        foreach (array_filter(explode(',', $this->buildFacetBy($req))) as $field) {
            $values = $filters[$field] ?? null;
            if (is_array($values) && $values !== []) {
                $refined[] = $field;
            }
        }
        return $refined;
    }

    /**
     * One `multi_search` entry that recounts a single facet field with its own
     * selections lifted from `filter_by` — see {@see refinedFacetFields()}. It
     * shares the {@see matching()} core (query, sort, stopwords, every other
     * filter) so the counts describe the same result set the user is looking at;
     * only the facet payload is wanted, so it ships no document and no
     * highlight.
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public function facetCountsFor(array $req, string $field): array
    {
        return array_merge(
            $this->matching($req, $field),
            ['collection' => $this->profile->collection(), 'page' => 1, 'per_page' => 0, 'include_fields' => 'id'],
            $this->facetCounting($field),
            self::noHighlights(),
        );
    }

    public function facetSearch(array $req, string $field, string $query): array
    {
        $params = $this->facetCountsFor($req, $field);
        if ($query !== '') {
            $params['facet_query'] = $field . ':' . $query;
        }
        return $params;
    }

    /**
     * Minimal query for the year facet stats (slider bounds). facet_stats
     * (min/max) are computed over all matches regardless of per_page, so we ask
     * for a single hit (per_page 1 is always valid) and read the stats.
     */
    public function yearStats(): array
    {
        return [
            'enable_analytics' => false,
            'highlight_fields' => 'none',
            'q'          => '*',
            'query_by'   => 'title',
            'filter_by'  => $this->buildFilter([]),
            'facet_by'   => implode(',', $this->profile->yearStatFields()),
            'page'       => 1,
            'per_page'   => 1,
            'sort_by'    => $this->profile->sortYearField() . ':asc',
        ];
    }

    /**
     * @param array<string,mixed> $req
     * @param string|null $except field whose own selections are left out, for the
     *                            multi-select facet recount ({@see facetCountsFor()}).
     *                            Never affects `is_public` or the block scope.
     */
    private function buildFilter(array $req, ?string $except = null): string
    {
        // Security invariant — always first, never client-controlled.
        $clauses = ['is_public:=true'];

        // Queued changes: the indexed copy may be stale (possibly still showing
        // metadata just made private), so hide it until the worker applies it.
        if ($this->excludeIds !== []) {
            $clauses[] = 'id:!=[' . implode(',', array_map('intval', $this->excludeIds)) . ']';
        }

        $locked = trim((string) ($this->serverFilter ?? ''));
        if ($locked !== '') {
            // && and || share one precedence level in Typesense, so the group
            // is only safe because FilterExpression guarantees it balances.
            $problem = FilterExpression::problem($locked);
            if ($problem !== null) {
                throw new Exception\RequestValidationException('invalid_block_scope', $problem);
            }
            $clauses[] = '(' . $locked . ')';
        }

        $filters = $req['filters'] ?? [];
        if (is_array($filters)) {
            foreach ($this->profile->filterableFields() as $field) {
                if ($field === $except) {
                    continue;
                }
                $values = $filters[$field] ?? null;
                if (!is_array($values) || $values === []) {
                    continue;
                }
                // Backtick-quote each value (handles spaces); strip stray
                // backticks so a value can't break out of the quoting.
                $quoted = array_map(
                    static fn($v): string => '`' . str_replace('`', '', (string) $v) . '`',
                    $values,
                );
                // Multiple values within a field are OR'd; fields are AND'd.
                $clauses[] = $field . ':=[' . implode(',', $quoted) . ']';
            }
        }

        $this->appendYearFilter($clauses, $req);

        return implode(' && ', $clauses);
    }

    /**
     * Year bounds. For a single-date profile these test the `year` field
     * directly; for a range profile they test overlap of [year_start, year_end]
     * with the selected window (a project matches if it started on/before `to`
     * and ended on/after `from`).
     *
     * @param list<string> $clauses
     * @param array<string,mixed> $req
     */
    private function appendYearFilter(array &$clauses, array $req): void
    {
        if (!$this->profile->hasDate()) {
            return; // no year field to filter on
        }
        $hasFrom = isset($req['year_from']) && is_numeric($req['year_from']);
        $hasTo = isset($req['year_to']) && is_numeric($req['year_to']);
        if (!$hasFrom && !$hasTo) {
            return;
        }
        $from = $hasFrom ? (int) $req['year_from'] : null;
        $to = $hasTo ? (int) $req['year_to'] : null;

        if ($this->profile->isRangeDate()) {
            if ($from !== null) {
                $clauses[] = 'year_end:>=' . $from;
            }
            if ($to !== null) {
                $clauses[] = 'year_start:<=' . $to;
            }
        } else {
            if ($from !== null) {
                $clauses[] = 'year:>=' . $from;
            }
            if ($to !== null) {
                $clauses[] = 'year:<=' . $to;
            }
        }
    }

    /** @param array<string,mixed> $req */
    private function buildFacetBy(array $req): string
    {
        $allowed = $this->profile->fieldNames();
        $requested = $req['facets'] ?? null;
        if (is_array($requested)) {
            $fields = array_values(array_intersect($allowed, array_map('strval', $requested)));
            return implode(',', $fields);
        }
        return implode(',', $allowed);
    }

    private function buildSort(string $sort, bool $isBrowse): string
    {
        // Config-defined numeric sort (e.g. podcasts by episode number) — works
        // regardless of date, even on a query. Tie-break by title for stability.
        $spec = $this->profile->sortFieldSpec($sort);
        if ($spec !== null) {
            return $spec['field'] . ':' . $spec['dir'] . ',title:asc';
        }
        // Count sort (e.g. "most research items") — works regardless of date.
        // Tie-break by title so equal-count rows have a stable order.
        $countField = $this->profile->sortCountField();
        if ($sort === 'count' && $countField !== null) {
            return $countField . ':desc,title:asc';
        }
        // Date-less corpora (e.g. people) can't sort by year — fall back to name.
        if (!$this->profile->hasDate()) {
            return $sort === 'title' || $isBrowse ? 'title:asc' : '_text_match:desc';
        }
        $yearField = $this->profile->sortYearField();
        return match ($sort) {
            'newest' => $yearField . ':desc',
            'oldest' => $yearField . ':asc',
            'title'  => 'title:asc',
            // Relevance is meaningless on a wildcard browse, so fall back to
            // newest there.
            default  => $isBrowse ? $yearField . ':desc' : '_text_match:desc',
        };
    }
}
