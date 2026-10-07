# Contributing

Use a focused branch and include tests for behavioral changes. Do not commit API
keys, local Omeka configuration, Typesense data, or user content.

## Local checks

```sh
npm ci
npm run lint
npm run check
npm test
npm run build

composer install
composer lint
composer test
composer analyse
```

`asset/dist/` is a build product and is not tracked: the release workflow builds
it into DRESearch.zip together with `vendor/` (from the committed `composer.lock`,
resolved for PHP 8.3 by `config.platform`). Run `npm run build` before copying the
module into a dev container. PHP 8.3 is the minimum; CI runs 8.3–8.5 and
production runs 8.5. After changing user-visible strings run `npm run i18n`. Omeka supplies Laminas and PSR interfaces
at runtime, so the module must never bundle them in Composer.

For profile changes, ensure every `query_by` field is indexed, every custom sort
targets a sortable field, and collection aliases stay unique. Test the affected
corpus against a disposable Typesense instance before requesting review.

## Integration tests

PHP analysis and the Omeka integration suite load core dependencies before the
module autoloader. Set `OMEKA_VENDOR` to the released Omeka S 4.2.1
`vendor/autoload.php`; the default is `/var/www/html/vendor/autoload.php`.
Do not add Laminas or PSR packages to this module to make standalone tools work.
Monolog stays on its supported 2.x line because core can already have PSR Log 1
loaded; the module autoloader appends to core's loader. CI runs
`php scripts/check-psr-log-origin.php`, which loads Omeka and the module the way
production does and fails unless `Psr\Log\LoggerInterface` comes from core.

Use isolated services, never a production database or search cluster:

```sh
export OMEKA_VENDOR=/tmp/omeka-s/vendor/autoload.php
export DRE_TEST_MYSQL_HOST=127.0.0.1
export DRE_TEST_MYSQL_USER=root
export DRE_TEST_MYSQL_PASSWORD=your-disposable-test-password
export TYPESENSE_HOST=127.0.0.1
export TYPESENSE_PORT=8108
export TYPESENSE_API_KEY=your-disposable-test-key
composer test
composer analyse
```

The MySQL user needs permission to create and drop disposable databases named
`dre_test_<random>`. Each case builds its database from Omeka's own install
schema (`application/data/install/schema.sql` in the tree `OMEKA_VENDOR` points
into), foreign keys and cascades included, and cleans up its own random
Typesense collections. CI runs this against MySQL 8.4, Typesense
30.2 and the released Omeka runtime on PHP 8.3–8.5. Missing service environment
variables skip the integration cases; they must be present for a release check.

Coverage includes real entity event payloads, old/new dependencies, public value,
linked-resource/media/title visibility, revision-safe queue acknowledgements,
rebuild cutover races, cancellation, advisory locks, ambiguous alias writes,
cache invalidation, long-tail facets and excluded-field highlights. Frontend tests
exercise out-of-order tab and facet responses. PHPStan (level 8) covers all
module classes and the tests; small stubs describe framework plugins and correct
inaccurate upstream PHPDoc. ESLint type-checks the client for floating and
misused promises: mark a deliberate fire-and-forget call with `void`.

On PHP 8.5, Omeka 4.2.1's bundled `Laminas\Stdlib\SplPriorityQueue` emits two
serialization return-type deprecations. These originate in core's vendor tree;
do not add a second Laminas copy to the module to suppress them.

`composer lint` enforces PSR-12 required formatting. Long SQL, translated strings
and array-shape annotations are permitted; the Omeka entry point is exempt from
the side-effect warning because it must load Composer during first installation.
