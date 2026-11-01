<?php

declare(strict_types=1);

namespace DRESearch\Search;

/**
 * Whether a request URL carries a search surface's own state — in which case
 * a server-rendered first page is wasted work: the client adopts the server's
 * page only for the pristine state (no query, the default sort, page 1, no
 * filters, no year window; src/svelte/lib/initialState.ts) and otherwise
 * fetches what the URL asks for. The keys are those of src/svelte/lib/urlState.ts; `view` (list,
 * gallery, map) is left out on purpose, because the client keeps the page for
 * any view. Ported from IwacSearch's SearchStateQuery.
 *
 * Works on the RAW query string on purpose. PHP rewrites dots in parameter
 * names to underscores when it builds $_GET, so `?b42.f.type_s=Text` would
 * reach a parsed array as `b42_f_type_s`, and the check would miss every
 * filter and year link.
 */
final class SearchStateQuery
{
    /** Scalar keys whose non-empty value means non-default state. */
    private const STATE_KEYS = ['q', 'sort', 'date.from', 'date.to'];

    /**
     * @param string $rawQuery the URL's query string, undecoded (no leading `?`)
     * @param string $prefix   the surface's key prefix: `b{blockId}.` for a page block
     */
    public static function carriesState(string $rawQuery, string $prefix): bool
    {
        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($rawKey);
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            $name = substr($key, strlen($prefix));
            $value = urldecode($rawValue);
            if (str_starts_with($name, 'f.')) {
                return true;
            }
            if (in_array($name, self::STATE_KEYS, true) && trim($value) !== '') {
                return true;
            }
            if ($name === 'page' && (int) $value > 1) {
                return true;
            }
        }
        return false;
    }

    /** The URL key prefix of a page block's state (urlState.ts embeddedUrlPrefix). */
    public static function blockPrefix(int $blockId): string
    {
        return 'b' . $blockId . '.';
    }
}
