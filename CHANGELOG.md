# Changelog

All notable changes to DRE Search are documented here. The project follows
[Semantic Versioning](https://semver.org/).

## [1.17.1] - 2026-07-26

### Fixed

- Both reindex jobs crashed immediately with `Call to undefined method
  getJob()`. Omeka's `AbstractJob` exposes the Job entity as the protected
  `$job` property and has no `getJob()` accessor, so "Reindex all corpora" and
  the per-corpus reindex both aborted before indexing anything.

## [1.17.0] - 2026-07-17

### Added

- Transactional rebuild state, per-profile advisory locks, unique staging
  collections, rollback generation tracking, safe retention, and cancellation.
- Strict per-document import gates and final count verification before alias
  promotion.
- Complete-or-fail exports with explicit cap metadata.
- Validated public request objects, stable error codes/request IDs, rate limits,
  caches, and server-owned block scopes.
- Incremental scope-exit deletion, dependency refreshes, batch/media/item-set
  events, and dirty-index reporting for bounded sync failures.
- Typed profile definitions, shared mapper normalization, strict URL/DOI
  handling, and operator-visible index status.
- Frontend cancellation, bounded paging, lossless URL state, accessible tabs and
  combobox IDs, runtime locale overrides, and safer downloads.
- PHPUnit, Vitest, accessibility, Typesense integration, CI, Dependabot, and
  reproducible release-package workflows.

### Changed

- Blank API-key submissions preserve the secret; clearing requires an explicit
  checkbox.
- Editor-authored introduction HTML is sanitized.
- Unknown profile names are rejected instead of falling back to the default.

### Security

- Browsers can no longer submit raw locked filters.
- Public endpoints enforce bounded schemas and never expose backend exceptions.
- Indexed external links are restricted to HTTP(S), with client-side defense in
  depth for legacy index documents.

## [1.16.0] - 2026-02-01

- Previous production release.
