<?php

declare(strict_types=1);

namespace DRESearch\Search;

use DRESearch\Indexer\AnalyticsSync;

/**
 * Reads one corpus' popular-query and no-hit analytics ({@see AnalyticsSync})
 * for {@see PopularSearches}: the public list and the maintenance page's
 * moderation queue see the same candidates.
 *
 * Every recorded query is read, not just the top few: anyone can push queries
 * up the list, and an approved query must not drop out of sight because
 * others were repeated more often.
 */
final class PopularAnalytics
{
    /** Typesense's per_page ceiling. */
    private const PAGE = 250;

    /**
     * @param object $client a Typesense\Client (duck-typed for tests)
     * @return array{available:bool, popular:list<array{q:string,count:int}>, nohits:list<string>}
     *         `available` is false when the popular-query collection does not
     *         exist (analytics never provisioned)
     */
    public static function fetch(object $client, string $profile, int $minCount): array
    {
        $search = static fn(string $suffix, int $page): array => [
            'collection' => AnalyticsSync::collectionName($profile, $suffix),
            'q' => '*',
            'query_by' => 'q',
            'sort_by' => 'count:desc',
            'per_page' => self::PAGE,
            'page' => $page,
            'highlight_fields' => 'none',
            'enable_analytics' => false,
        ] + ($suffix === 'popular' ? ['filter_by' => 'count:>=' . $minCount] : []);

        $suffixes = ['popular', 'nohits'];
        $first = SearchExecutor::multi($client, ['searches' => [$search('popular', 1), $search('nohits', 1)]]);
        $results = [$first['results'][0] ?? [], $first['results'][1] ?? []];

        // Missing collections answer per search with an error and no hits.
        $more = [];
        $maxPages = intdiv(AnalyticsSync::LIMIT + self::PAGE - 1, self::PAGE);
        foreach ($suffixes as $i => $suffix) {
            $pages = min($maxPages, (int) ceil(((int) ($results[$i]['found'] ?? 0)) / self::PAGE));
            for ($page = 2; $page <= $pages; $page++) {
                $more[] = [$i, $search($suffix, $page)];
            }
        }
        if ($more !== []) {
            $response = SearchExecutor::multi($client, ['searches' => array_column($more, 1)]);
            foreach ($more as $j => [$i]) {
                $results[$i]['hits'] = array_merge($results[$i]['hits'] ?? [], $response['results'][$j]['hits'] ?? []);
            }
        }

        $popular = [];
        foreach ($results[0]['hits'] ?? [] as $hit) {
            $popular[] = ['q' => (string) ($hit['document']['q'] ?? ''), 'count' => (int) ($hit['document']['count'] ?? 0)];
        }
        $noHits = [];
        foreach ($results[1]['hits'] ?? [] as $hit) {
            $noHits[] = (string) ($hit['document']['q'] ?? '');
        }
        return ['available' => !isset($results[0]['error']), 'popular' => $popular, 'nohits' => $noHits];
    }
}
