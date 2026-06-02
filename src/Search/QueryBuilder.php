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
            'q'                => $isBrowse ? '*' : $q,
            'query_by'         => $this->profile->queryBy(),
            'filter_by'        => $this->buildFilter($req),
            'facet_by'         => $this->buildFacetBy($req),
            'max_facet_values' => 100,
            'page'             => max(1, (int) ($req['page'] ?? 1)),
            'per_page'         => $perPage,
            'sort_by'          => $this->buildSort((string) ($req['sort'] ?? 'relevance'), $isBrowse),
        ];
        if (!$isBrowse) {
            $params['highlight_full_fields'] = 'title';
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
        $include = array_merge(
            ['id', 'title'],
            $dateFields,
            $this->profile->fieldNames(),
            array_keys($this->profile->displayFields()),
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
            foreach ($this->profile->fieldNames() as $field) {
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
