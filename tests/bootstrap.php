<?php

declare(strict_types=1);

// Omeka owns Laminas, Doctrine and PSR. Never install duplicate framework packages here.
$core = getenv('OMEKA_VENDOR') ?: '/var/www/html/vendor/autoload.php';
if (is_readable($core)) {
    require_once $core;
}
require_once dirname(__DIR__) . '/vendor/autoload.php';
