<?php

/**
 * Standalone CI guard: Psr\Log\LoggerInterface must resolve from Omeka core's
 * vendor tree, never this module's.
 *
 * Core ships psr/log 1.1.4 and implements its untyped interface; the module's
 * vendor/ holds a newer copy because Monolog 2 (required by the Typesense SDK)
 * accepts psr/log 1–3. Core's copy wins only because the module's autoloader
 * is appended after core's (config.prepend-autoloader false). Monolog stays on
 * 2.x, the last line that supports psr/log 1.
 *
 * Loads exactly as production does: Omeka's bootstrap.php, then Module.php,
 * which requires the module's vendor/autoload.php.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$core = getenv('OMEKA_VENDOR') ?: '/var/www/html/vendor/autoload.php';
$omekaBootstrap = dirname($core, 2) . '/bootstrap.php';
if (!is_readable($omekaBootstrap) || !is_readable($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Set OMEKA_VENDOR to Omeka S's vendor/autoload.php and run composer install first.\n");
    exit(2);
}
require $omekaBootstrap;
require $root . '/Module.php';

// Building a client constructs the SDK's Monolog logger (no request is sent),
// which loads the interface through whichever autoloader comes first.
$client = (new DRESearch\Search\TypesenseClientProvider('127.0.0.1', 8108, 'http', 'origin-check'))->getClient();
if ($client === null) {
    fwrite(STDERR, "::error::The Typesense client could not be built.\n");
    exit(1);
}

$loaded = realpath((string) (new ReflectionClass(Psr\Log\LoggerInterface::class))->getFileName());
$expected = realpath(dirname($core) . '/psr/log');
if ($loaded === false || $expected === false || !str_starts_with($loaded, $expected . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, sprintf(
        "::error::Psr\\Log\\LoggerInterface loaded from %s, not from Omeka core's %s\n",
        $loaded ?: 'nowhere',
        $expected ?: dirname($core) . '/psr/log',
    ));
    exit(1);
}
echo "Psr\\Log\\LoggerInterface resolves from Omeka core: $loaded\n";
