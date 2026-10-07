<?php

declare(strict_types=1);

namespace DRESearch\Search;

/**
 * Which recorded queries may be shown to visitors as "Popular searches".
 *
 * The analytics collections hold whatever anyone typed, so this is the privacy
 * gate: only queries typed at least `min_count` times, never one that found
 * nothing, and nothing shaped like personal data (an e-mail address, a URL, a
 * long run of digits such as a phone or ID number, control characters).
 *
 * Passing the gate only makes a query a candidate. Anyone can repeat a query
 * until it counts, so only candidates an editor approved are served
 * ({@see PopularModeration}, {@see approved()}).
 */
final class PopularSearches
{
    public const MAX_LENGTH = 60;

    /**
     * @param list<array{q:string,count:int}> $popular most-typed first
     * @param list<string>                    $noHits  queries that found nothing
     * @return list<string>
     */
    public static function select(array $popular, array $noHits, int $minCount, int $limit): array
    {
        return array_column(self::candidates($popular, $noHits, $minCount, $limit), 'q');
    }

    /**
     * {@see select()} with each query's count, for the moderation list.
     *
     * @param list<array{q:string,count:int}> $popular most-typed first
     * @param list<string>                    $noHits  queries that found nothing
     * @return list<array{q:string,count:int}>
     */
    public static function candidates(array $popular, array $noHits, int $minCount, ?int $limit = null): array
    {
        $empty = [];
        foreach ($noHits as $q) {
            $empty[self::key($q)] = true;
        }
        $seen = [];
        $selected = [];
        foreach ($popular as $row) {
            $q = self::clean($row['q']);
            $key = mb_strtolower($q);
            if ($row['count'] < $minCount || isset($empty[$key]) || isset($seen[$key]) || !self::presentable($q)) {
                continue;
            }
            $seen[$key] = true;
            $selected[] = ['q' => $q, 'count' => $row['count']];
            if ($limit !== null && count($selected) >= $limit) {
                break;
            }
        }
        return $selected;
    }

    /**
     * The candidates an editor approved, most-typed first, in the spelling the
     * editor approved.
     *
     * @param list<array{q:string,count:int}> $candidates from {@see candidates()}
     * @param array<string,string>            $approved   approved query keyed by {@see key()}
     * @return list<string>
     */
    public static function approved(array $candidates, array $approved, int $limit): array
    {
        $served = [];
        foreach ($candidates as $row) {
            $query = $approved[self::key($row['q'])] ?? null;
            if ($query === null) {
                continue;
            }
            $served[] = $query;
            if (count($served) >= $limit) {
                break;
            }
        }
        return $served;
    }

    /** The form queries are compared in: whitespace collapsed, lower case. */
    public static function key(string $q): string
    {
        return mb_strtolower(self::clean($q));
    }

    /** Whether a query may be shown at all, whatever its count. */
    public static function presentable(string $q): bool
    {
        $length = mb_strlen($q);
        return $length >= 2
            && $length <= self::MAX_LENGTH
            && !str_contains($q, '@')
            && preg_match('#://|\bwww\.#i', $q) === 0
            && preg_match('/\d{5,}/', $q) === 0
            && preg_match('/\p{C}/u', $q) === 0;
    }

    public static function clean(string $q): string
    {
        // Invalid UTF-8 makes preg_replace() return null: '' is then refused.
        return trim((string) preg_replace('/\s+/u', ' ', $q));
    }
}
