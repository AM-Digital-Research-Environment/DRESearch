<?php

declare(strict_types=1);

// Omeka owns Laminas, Doctrine and PSR. Never install duplicate framework packages here.
//
// Prefer Omeka's own bootstrap.php over its bare vendor/autoload.php: since
// Omeka S 4.2.1 that file prepends an autoloader swapping in PHP 8.5-patched
// copies of Laminas\Escaper\Escaper, Laminas\Stdlib\SplPriorityQueue and
// Doctrine's proxy factories. Loading only the vendor autoloader would run the
// stock classes, i.e. NOT what production executes. Omeka's bootstrap also
// chdir()s to the Omeka root, so every module path is resolved from __DIR__.
$moduleAutoload = dirname(__DIR__) . '/vendor/autoload.php';
$core = getenv('OMEKA_VENDOR') ?: '/var/www/html/vendor/autoload.php';
$omekaBootstrap = dirname($core, 2) . '/bootstrap.php';
if (is_readable($omekaBootstrap) && is_readable($core)) {
    require_once $omekaBootstrap;
} elseif (is_readable($core)) {
    require_once $core;
}
require_once $moduleAutoload;
