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
composer test
composer analyse
```

The compiled `asset/dist/` bundle ships with the module; commit it when frontend
source changes. PHP 8.2 is the minimum. Omeka supplies Laminas and PSR interfaces
at runtime, so the module must never bundle them in Composer.

For profile changes, ensure every `query_by` field is indexed, every custom sort
targets a sortable field, and collection aliases stay unique. Test the affected
corpus against a disposable Typesense instance before requesting review.
