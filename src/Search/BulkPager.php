<?php

declare(strict_types=1);

namespace DRESearch\Search;

use DRESearch\Search\Exception\IncompletePullException;

/**
 * Pages through a result set server-side, for the two endpoints that need more
 * than one page of it: the citation export and the location map. Both pull at
 * Typesense's per-request maximum ({@see QueryBuilder::EXPORT_PER_PAGE}) up to
 * a fixed cap, and both must be complete — a file or a map that silently lacks
 * records looks finished and is not — so the pull fails closed.
 */
final class BulkPager
{
    public function __construct(
        private readonly int $maxHits,
        private readonly int $perPage = QueryBuilder::EXPORT_PER_PAGE,
    ) {
    }

    /**
     * Fetch pages until every match (up to the cap) is in hand. Documents are
     * keyed by id: if a drain re-ranks between pages, a document can move across
     * the page boundary, and it is never returned twice.
     *
     * @param callable(int):array<string,mixed> $fetchPage one Typesense result
     *        page (1-based); throws when the request fails
     * @return array{found:int, docs:list<array<string,mixed>>}
     * @throws IncompletePullException when the pages held fewer documents than matched
     */
    public function pull(callable $fetchPage): array
    {
        $pages = max(1, (int) ceil($this->maxHits / max(1, $this->perPage)));
        $docs = [];
        $found = 0;
        for ($page = 1; $page <= $pages; $page++) {
            $result = $fetchPage($page);
            $found = (int) ($result['found'] ?? 0);
            foreach (is_array($result['hits'] ?? null) ? $result['hits'] : [] as $hit) {
                if (is_array($hit) && is_array($hit['document'] ?? null)) {
                    /** @var array<string,mixed> $doc */
                    $doc = $hit['document'];
                    $docs[(string) ($doc['id'] ?? count($docs))] = $doc;
                }
            }
            if (count($docs) >= $found || count($docs) >= $this->maxHits) {
                break;
            }
        }
        $docs = array_slice(array_values($docs), 0, $this->maxHits);
        $expected = min($found, $this->maxHits);
        if (count($docs) !== $expected) {
            throw new IncompletePullException(count($docs), $expected);
        }
        return ['found' => $found, 'docs' => $docs];
    }

    /** Whether more matched than one pull may return. */
    public function capped(int $found): bool
    {
        return $found > $this->maxHits;
    }
}
