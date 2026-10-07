<?php

declare(strict_types=1);

namespace DRESearch\Search;

/**
 * Structural checks for an editor-saved Typesense `filter_by` fragment (a
 * block's "locked filter").
 *
 * Typesense gives `&&` and `||` EQUAL precedence, left-associative
 * (filter.cpp in 30.2), so a fragment with an unbalanced `)` can close the
 * group the proxy wraps it in and attach an `|| …` branch that escapes every
 * other clause — including `is_public:=true`. Parentheses must therefore
 * balance outside backtick-quoted values, backticks must pair, and the
 * fragment must not mention the visibility field at all.
 */
final class FilterExpression
{
    public const MAX_LENGTH = 1000;

    /** Why the fragment is unusable, or null when it is structurally safe. */
    public static function problem(string $filter): ?string
    {
        if (mb_strlen($filter) > self::MAX_LENGTH) {
            return sprintf('The locked filter is longer than %d characters.', self::MAX_LENGTH);
        }
        if (!mb_check_encoding($filter, 'UTF-8')) {
            return 'The locked filter is not valid UTF-8.';
        }
        $depth = 0;
        $quoted = false;
        $outside = '';
        $length = strlen($filter);
        for ($i = 0; $i < $length; $i++) {
            $char = $filter[$i];
            if ($char === '`') {
                $quoted = !$quoted;
                continue;
            }
            if ($quoted) {
                continue;
            }
            $outside .= $char;
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    return 'The locked filter closes a parenthesis it never opened.';
                }
            }
        }
        if ($quoted) {
            return 'The locked filter has an unterminated backtick-quoted value.';
        }
        if ($depth !== 0) {
            return 'The locked filter has unbalanced parentheses.';
        }
        if (preg_match('/\bis_public\b/', $outside)) {
            return 'The locked filter may not refer to is_public; visibility is enforced by the server.';
        }
        return null;
    }
}
