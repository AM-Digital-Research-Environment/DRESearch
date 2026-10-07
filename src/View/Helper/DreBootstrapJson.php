<?php

declare(strict_types=1);

namespace DRESearch\View\Helper;

use Laminas\View\Helper\AbstractHelper;

/**
 * Serialises the per-block bootstrap blob for the inline
 * <script type="application/json"> the Svelte client reads on mount.
 *
 * Security-relevant flag set (mirrors IwacSearch's IwacBootstrapJson —
 * defence in depth, even though the bootstrap is server-built):
 *   - JSON_UNESCAPED_SLASHES    keep `/path/segments/` legible
 *   - JSON_UNESCAPED_UNICODE    keep diacritics readable on the wire
 *   - JSON_HEX_TAG              a stray "</script>" can't break out of the tag
 *   - JSON_HEX_AMP              `&` → `&`
 *   - JSON_HEX_APOS / HEX_QUOT  quote characters can't bust out either
 *   - JSON_INVALID_UTF8_SUBSTITUTE  one bad byte (e.g. a crafted `?q=%FF`)
 *                                  degrades to U+FFFD instead of emptying the
 *                                  whole bootstrap and breaking the page
 */
class DreBootstrapJson extends AbstractHelper
{
    private const FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __invoke(array $bootstrap): string
    {
        $json = json_encode($bootstrap, self::FLAGS);
        return $json !== false ? $json : '{}';
    }
}
