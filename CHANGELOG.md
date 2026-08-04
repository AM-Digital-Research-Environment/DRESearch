# Changelog

All notable changes to DRE Search are documented here. The project follows
[Semantic Versioning](https://semver.org/).

## [1.19.1] - 2026-08-04

### Fixed

- Short queries under-reported their matches by up to 10×. Prefix search is on,
  so a one- or two-character query has to stand in for every token starting with
  it, and Typesense's default cap of four prefix expansions per token truncated
  the result set: on the research items corpus `k` found 137 of 1299 matches and
  `ke` 102 of 165. The truncation also interacted with `filter_by` — a narrower
  filter reaches deeper into the same candidate space — so a filtered search could
  report more hits than the unfiltered facet count claimed for that value. The
  pool is now 512 (`QueryBuilder::MAX_CANDIDATES`), applied to search,
  autocomplete, and every query derived from them (federated tab counts, export,
  map, union, facet recounts) — enough for `k` to reach all 1299. Longer queries
  are unaffected: `kenya`, `africa`, `islam` and `music` return exactly the counts
  they did before. It is also faster, because the default's escalating retry
  passes cost more than one wider pass — `k` went 415ms → 92ms and `africa` 37ms →
  3ms on the dev corpus. No reindex.

## [1.19.0] - 2026-08-04

### Fixed

- Facets are multi-select again. Picking one Type emptied the Type list of every
  other option, so a checkbox group behaved like a radio group: a facet's own
  selection is part of the filter, leaving Typesense nothing else to count. Each
  refined facet is now recounted alongside the main search with its own clause
  lifted — every other filter still applies, so the numbers stay honest — and a
  selected value that no longer matches stays listed at zero rather than
  disappearing from the list it was ticked in. This lives in the shared query
  layer, so it holds for every corpus, every page block, and the federated page,
  with no configuration and no reindex. Unfiltered searches are unchanged: the
  extra pass rides along in the existing round-trip only when a facet is refined,
  and falls back to the plain search if it fails.

### Changed

- The federated page's corpus tabs wrap instead of scrolling sideways. Thirteen
  tabs measure ~1885px against a ~1236px column, so five of them — Genres,
  Languages, Locations, Subjects & tags and half of Organisations — sat behind a
  horizontal scrollbar. They now wrap to two rows on a desktop column (five on a
  phone, where the chips tighten), and read as pills so the active one is legible
  on any row. The count badge on the active pill lost its fill: on the filled pill
  any tint pushed the number under WCAG AA (4.0:1 dark, 3.3:1 light); outlined, it
  keeps the label's own 6.5:1 / 5.2:1.

## [1.18.2] - 2026-08-03

### Fixed

- Reindexing the Locations corpus failed outright: Typesense builds its geo
  index from the sort index, so it rejects a `geopoint` field declared with
  `sort: false` — the default for every config-declared display field. The
  generated schema now forces geopoint fields sortable, which is a property of
  Typesense rather than of any one profile's configuration.
- A failing corpus no longer hides its cause. The reindex-all summary reported
  only which corpora failed, leaving the actual Typesense message reachable only
  by digging through the per-corpus log lines; it now carries each reason.

### Added

- An integration test that creates every shipped profile's schema against a live
  Typesense, so server-side field-combination rules are caught in CI instead of
  mid-reindex, and a profile-schema guard rule for unsortable geopoints.

## [1.18.1] - 2026-08-01

### Changed

- Updated the supported Svelte 5, Vite 8, ESLint 10, TypeScript ESLint,
  Svelte Check, Prettier, and browser-global development toolchain releases.
- Regenerated the committed production bundle with Svelte 5.56.8 and Vite
  8.2.0.

### Security

- Refreshed transitive build dependencies to remove the reported
  `brace-expansion` denial-of-service and PostCSS source-map path-traversal
  advisories; `npm audit` now reports no known vulnerabilities.

## [1.18.0] - 2026-08-01

### Added

- Publication full-text search and a compact availability filter without sending
  full texts to browsers.
- Shareable list/gallery preferences, larger derivative-aware thumbnails, and a
  lazy clustered map for geocoded locations.
- A persistent result summary with shared removable scope chips, layout-matched
  reduced-motion skeletons, compact association sparklines, recent searches,
  slash-to-focus, copy-link, and zero-result suggestions.
- A server-side Typesense 30 union endpoint and federated All tab with mixed-card
  corpus handoff; the server-held API key remains private.
- Optional per-profile popular/no-hit analytics provisioning and an admin digest.
- A standalone profile/schema/client drift guard wired into lint, plus map, union,
  mapper, URL-state, thumbnail, and chip-model regression tests.

### Changed

- One shared reindex orchestrator now owns stopword provisioning, one/all profile
  rebuild wiring, and the non-fatal analytics follow-up.
- CI now runs PHP syntax, PHPUnit and PHPStan on PHP 8.2–8.5, plus frontend
  lint/type/tests/build, a committed-bundle check, and a live Typesense 30 union
  integration test.

### Deployment

- Reindex all corpora to populate union source markers plus the new publication
  full-text and location coordinate fields. Analytics additionally requires
  Typesense search analytics and a persistent analytics directory.

## [1.17.2] - 2026-07-30

### Changed

- Match highlights follow the DRE theme's renamed highlight token. The theme
  renamed `--dre-hl-bg` to `--highlight-bg` in v2.22.0 — it was the only token
  carrying a product prefix and an abbreviation, on the one token whose whole
  purpose is to be shared across theme, search and visualizations.
  `Highlight.svelte` now reads
  `var(--highlight-bg, var(--dre-hl-bg, <literal>))`, so a matched term keeps its
  wash against both the new theme and any instance still on 2.21.x. The
  `--dre-hl-bg` step can be dropped once every deployment is on ≥ 2.23, when the
  theme retires its deprecated alias.

## [1.17.1] - 2026-07-26

### Fixed

- Both reindex jobs crashed immediately with an undefined-method fatal on
  `getJob()`. Omeka's `AbstractJob` exposes the Job entity as the protected
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
