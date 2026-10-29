<?php

declare(strict_types=1);

namespace DRESearch\Search;

/**
 * Keeps refined facets multi-select. A facet's own selection is part of the
 * main search's `filter_by`, so its counts list only what is already chosen;
 * each refined field is recounted with its own clause lifted
 * ({@see QueryBuilder::facetCountsFor()}) in the same `multi_search` as the
 * main search, and those counts replace the narrowed ones in the main result.
 *
 * Pure: the {@see SearchProxy} sends the searches, and owns the fallback to a
 * plain search, the breaker and the logging.
 */
final class FacetRecount
{
    /**
     * The `multi_search` entries: the main search first, then one recount per
     * refined field, in order.
     *
     * @param array<string,mixed> $params main search params
     * @param array<string,mixed> $req    validated request
     * @param list<string> $refined       facet fields to recount
     * @return list<array<string,mixed>>
     */
    public static function searches(QueryBuilder $builder, array $params, array $req, array $refined, string $collection): array
    {
        $searches = [$params + ['collection' => $collection]];
        foreach ($refined as $field) {
            $searches[] = $builder->facetCountsFor($req, $field);
        }
        return $searches;
    }

    /**
     * The main Typesense result with each refined field's counts substituted,
     * or null when the main entry failed — `multi_search` reports a failure in
     * the entry rather than throwing, so a missing stopword set surfaces here,
     * and the caller falls back to a plain search that retries without it. A
     * failed recount entry keeps that field's (narrowed) counts.
     *
     * @param array<mixed> $results `multi_search` results, in {@see searches()} order
     * @param array<string,mixed> $req validated request
     * @param list<string> $refined
     * @return array<string,mixed>|null
     */
    public static function merge(array $results, array $req, array $refined): ?array
    {
        $main = $results[0] ?? null;
        if (!is_array($main) || isset($main['error']) || !isset($main['hits'])) {
            return null;
        }
        /** @var array<string,mixed> $main */
        $filters = is_array($req['filters'] ?? null) ? $req['filters'] : [];
        foreach ($refined as $i => $field) {
            $counts = self::countsOf($results[$i + 1] ?? null, $field);
            if ($counts === null) {
                continue;
            }
            /** @var list<string> $selected */
            $selected = array_map('strval', $filters[$field] ?? []);
            $main['facet_counts'] = self::withFacetCounts(
                is_array($main['facet_counts'] ?? null) ? array_values($main['facet_counts']) : [],
                $field,
                self::keepSelectedListed($counts, $selected),
            );
        }
        return $main;
    }

    /**
     * The counts a facet-only search returned for one field, or null if that
     * entry errored or carried no facet payload.
     *
     * @return list<array<string,mixed>>|null
     */
    public static function countsOf(mixed $result, string $field): ?array
    {
        if (!is_array($result) || isset($result['error']) || !is_array($result['facet_counts'] ?? null)) {
            return null;
        }
        foreach ($result['facet_counts'] as $facet) {
            if (is_array($facet) && ($facet['field_name'] ?? null) === $field && is_array($facet['counts'] ?? null)) {
                return array_values($facet['counts']);
            }
        }
        return null;
    }

    /**
     * A selected value that no longer matches under the OTHER filters is absent
     * from the recount, which would hide its (ticked) checkbox and leave the user
     * no way back except the active-filter chip. Keep it listed — with an
     * unknown (null) count: outside the top values the recount returned, it has
     * results (it is selected), so zero would be false.
     *
     * @param list<array<string,mixed>> $counts
     * @param list<string> $selected
     * @return list<array<string,mixed>>
     */
    public static function keepSelectedListed(array $counts, array $selected): array
    {
        $present = [];
        foreach ($counts as $count) {
            if (is_array($count)) {
                $present[(string) ($count['value'] ?? '')] = true;
            }
        }
        foreach ($selected as $value) {
            if (!isset($present[$value])) {
                $counts[] = ['value' => $value, 'count' => null];
            }
        }
        return array_values($counts);
    }

    /**
     * Substitute one field's counts in a Typesense `facet_counts` block, appending
     * the entry when the main search returned none for it (a filter combination
     * with no matches still needs its facet listed — that list is the way out).
     *
     * @param list<mixed> $facetCounts
     * @param list<array<string,mixed>> $counts
     * @return list<mixed>
     */
    private static function withFacetCounts(array $facetCounts, string $field, array $counts): array
    {
        foreach ($facetCounts as $i => $facet) {
            if (is_array($facet) && ($facet['field_name'] ?? null) === $field) {
                $facet['counts'] = $counts;
                $facetCounts[$i] = $facet;
                return array_values($facetCounts);
            }
        }
        $facetCounts[] = ['field_name' => $field, 'counts' => $counts];
        return array_values($facetCounts);
    }
}
