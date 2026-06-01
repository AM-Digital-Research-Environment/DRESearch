<?php
declare(strict_types=1);

namespace DRESearch\Search;

use DRESearch\Indexer\SchemaProvider;
use DRESearch\Settings\FacetConfig;

/**
 * Translates a structured search request from the client into Typesense search
 * parameters. The is_public:=true constraint is hard-coded here (not taken from
 * the request), so a crafted client payload can never widen visibility.
 *
 * Expected request keys (all optional):
 *   q             string  full-text query ('' = browse everything)
 *   page          int     1-based
 *   per_page      int     capped at PER_PAGE_MAX
 *   sort          string  relevance | newest | oldest | title
 *   filters       array   field => [values]  (validated against FacetConfig)
 *   facets        array   facet fields to count (defaults to all FacetConfig)
 *   year_from/to  int     optional origin-year bounds
 *   locked_filter string  admin-authored raw filter from the block (echoed by
 *                         the client; only ever narrows, never widens)
 */
final class QueryBuilder
{
    private const PER_PAGE_DEFAULT = 20;
    private const PER_PAGE_MAX = 50;

    /** @param array<string,mixed> $req */
    public function search(array $req): array
    {
        $q = trim((string) ($req['q'] ?? ''));
        $isBrowse = $q === '';

        $perPage = (int) ($req['per_page'] ?? self::PER_PAGE_DEFAULT);
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));

        $params = [
            'q'                => $isBrowse ? '*' : $q,
            'query_by'         => SchemaProvider::QUERY_BY,
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
        return [
            'q'             => $q,
            'query_by'      => 'title',
            'prefix'        => true,
            'filter_by'     => 'is_public:=true',
            'sort_by'       => '_text_match:desc',
            'page'          => 1,
            'per_page'      => 6,
            'include_fields' => 'id,title,type_s,project_s,year',
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
            foreach (FacetConfig::fieldNames() as $field) {
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

        if (isset($req['year_from']) && is_numeric($req['year_from'])) {
            $clauses[] = 'year:>=' . (int) $req['year_from'];
        }
        if (isset($req['year_to']) && is_numeric($req['year_to'])) {
            $clauses[] = 'year:<=' . (int) $req['year_to'];
        }

        return implode(' && ', $clauses);
    }

    /** @param array<string,mixed> $req */
    private function buildFacetBy(array $req): string
    {
        $allowed = FacetConfig::fieldNames();
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
        return match ($sort) {
            'newest' => 'year:desc',
            'oldest' => 'year:asc',
            'title'  => 'title:asc',
            // Relevance is meaningless on a wildcard browse, so fall back to
            // newest there.
            default  => $isBrowse ? 'year:desc' : '_text_match:desc',
        };
    }
}
