<?php
declare(strict_types=1);

namespace DRESearch\View\Helper;

use Laminas\View\Helper\AbstractHelper;

/**
 * Serialises the per-block bootstrap blob for the inline
 * <script type="application/json"> the Svelte client reads on mount.
 *
 * JSON_HEX_TAG neutralises any "</script>" sequence inside the (server-built,
 * but defensively encoded) values so the blob can't break out of the element.
 */
class DreBootstrapJson extends AbstractHelper
{
    public function __invoke(array $bootstrap): string
    {
        $json = json_encode(
            $bootstrap,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );
        return $json !== false ? $json : '{}';
    }
}
