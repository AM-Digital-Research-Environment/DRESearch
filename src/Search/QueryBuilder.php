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
 *   locked_filter string  admin-authored raw filter from the block (echoed by
 *                         the client; only ever narrows, never widens)
 */
final class QueryBuilder
{
    private const PER_PAGE_DEFAULT = 20;
    private const PER_PAGE_MAX = 50;

    /**
     * Export paging. The result-export menu pulls the CURRENT result set as a
     * bulk citation dump — {@see SearchProxy::export()} pages at EXPORT_PER_PAGE
     * (Typesense's per-request maximum) up to EXPORT_MAX_HITS total. Exports
     * follow the live sort, so a truncated export keeps the most relevant / newest
     * rows. Mirror EXPORT_MAX_HITS in the client (lib/export.ts) for the cap hint.
     */
    public const EXPORT_PER_PAGE = 250;
    public const EXPORT_MAX_HITS = 1000;

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

    public function __construct(private readonly SearchProfile $profile)
    {
    }

    /** @param array<string,mixed> $req */
    public function search(array $req): array
    {
        $q = trim((string) ($req['q'] ?? ''));
        $isBrowse = $q === '';

        $perPage = (int) ($req['per_page'] ?? self::PER_PAGE_DEFAULT);
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));

        $params = [
            'q'         => $isBrowse ? '*' : $q,
            'query_by'  => $this->profile->queryBy(),
            'filter_by' => $this->buildFilter($req),
            'page'      => max(1, (int) ($req['page'] ?? 1)),
            'per_page'  => $perPage,
            'sort_by'   => $this->buildSort((string) ($req['sort'] ?? 'relevance'), $isBrowse),
        ];
        // Facets are optional — a corpus may have none (e.g. genres, languages),
        // in which case we omit facet_by entirely rather than send an empty one.
        $facetBy = $this->buildFacetBy($req);
        if ($facetBy !== '') {
            $params['facet_by'] = $facetBy;
            $params['max_facet_values'] = 100;
        }
        // Search-only fields (e.g. a podcast transcript) are indexed for query_by but
        // dropped from the returned documents so a large value never bloats every hit.
        // Typesense still returns their highlighted snippet, so a transcript match
        // still surfaces in the card's "Matched in" line.
        $searchOnly = $this->profile->searchOnlyFields();
        if ($searchOnly !== []) {
            $params['exclude_fields'] = implode(',', $searchOnly);
        }
        if (!$isBrowse) {
            // Strip common FR/EN/DE function words so "le"/"the"/"der" etc. don't
            // dilute relevance. Only on a real query — browse (q=*) ignores it, and
            // SearchProxy drops it and retries if the set isn't on the server yet.
            $params['stopwords'] = self::STOPWORDS_SET;
            // Mark matched terms so each card can show *where* a result matched.
            // Short fields (title, linked-value facets, names) are highlighted in
            // full — so a card can highlight a whole chip/byline value — while the
            // long free-text fields (abstract, description, transcript) return a
            // windowed snippet centred on the match (the full text would scroll the
            // match out of the clamped card, or dump a whole transcript into a line).
            $longTextFields = ['abstract', 'description', 'transcript'];
            $queryFields = array_values(array_filter(array_map(
                'trim',
                explode(',', $this->profile->queryBy())
            )));
            $fullFields = array_values(array_filter(
                $queryFields,
                static fn(string $f): bool => !in_array($f, $longTextFields, true)
            ));
            $params['highlight_start_tag'] = self::HL_START;
            $params['highlight_end_tag'] = self::HL_END;
            $params['highlight_affix_num_tokens'] = 8;
            if ($fullFields !== []) {
                $params['highlight_full_fields'] = implode(',', $fullFields);
            }
        }
        return $params;
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
            'q'              => $q,
            'query_by'       => 'title',
            'prefix'         => true,
            'filter_by'      => 'is_public:=true',
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
     * for a corpus (the type tabs on the federated results page): the normal
     * {@see search()} filter/sort/year logic, scoped to this profile's
     * `collection`, but returning no facets/highlights and a single hit (we read
     * `found`, not the documents — `include_fields:id` keeps the payload tiny).
     *
     * @param array<string,mixed> $req shared query bits: q, year_from, year_to
     * @return array<string,mixed>
     */
    public function countOnly(array $req): array
    {
        $params = $this->search($req);
        $params['collection'] = $this->profile->collection();
        // per_page 1 (not 0) is accepted by every Typesense build; `found` is the
        // total regardless, and include_fields:id avoids shipping document bodies.
        $params['per_page'] = 1;
        $params['include_fields'] = 'id';
        // Drop stopwords from the federated tab counts (like the highlight/facet
        // params): a badge is an approximate count, and not referencing the set
        // keeps the multi_search resilient if it isn't provisioned yet.
        unset(
            $params['facet_by'],
            $params['max_facet_values'],
            $params['exclude_fields'],
            $params['stopwords'],
            $params['highlight_full_fields'],
            $params['highlight_start_tag'],
            $params['highlight_end_tag'],
            $params['highlight_affix_num_tokens'],
        );
        return $params;
    }

    /**
     * Params for one page of a result-export pull: the same query / filter /
     * sort / year window as a live {@see search()}, but tuned for a bulk citation
     * dump — a big page size, no facet counts, no highlight markup, and the
     * internal `is_public` flag dropped from the returned documents (alongside any
     * search-only fields such as transcripts). Stopwords are kept so the export
     * matches what the user sees; {@see SearchProxy::export()} drops them and
     * retries if the set isn't provisioned. Paged up to {@see EXPORT_MAX_HITS}.
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public function export(array $req, int $page): array
    {
        $params = $this->search($req);
        $params['page'] = max(1, $page);
        $params['per_page'] = self::EXPORT_PER_PAGE;
        // Citation/display fields only — no facet counts, no highlight noise.
        unset(
            $params['facet_by'],
            $params['max_facet_values'],
            $params['highlight_full_fields'],
            $params['highlight_start_tag'],
            $params['highlight_end_tag'],
            $params['highlight_affix_num_tokens'],
        );
        $exclude = array_values(array_unique(array_merge(
            $this->profile->searchOnlyFields(),
            ['is_public'],
        )));
        $params['exclude_fields'] = implode(',', $exclude);
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
            'q'          => '*',
            'query_by'   => 'title',
            'filter_by'  => 'is_public:=true',
            'facet_by'   => implode(',', $this->profile->yearStatFields()),
            'page'       => 1,
            'per_page'   => 1,
            'sort_by'    => $this->profile->sortYearField() . ':asc',
        ];
    }

    /** @param array<string,mixed> $req */
    private function buildFilter(array $req): string
    {
        // Security invariant — always first, never client-controlled.
        $clauses = ['is_public:=true'];

        $locked = trim((string) ($req['locked_filter'] ?? ''));
        if ($locked !== '') {
            $clauses[] = '(' . $locked . ')';
        }

        $filters = $req['filters'] ?? [];
        if (is_array($filters)) {
            foreach ($this->profile->filterableFields() as $field) {
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
        if (is_array($requested) && $requested !== []) {
            $fields = array_values(array_intersect($allowed, array_map('strval', $requested)));
            if ($fields !== []) {
                return implode(',', $fields);
            }
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
