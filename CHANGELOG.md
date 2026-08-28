# Changelog

All notable changes to DRE Search are documented here. The project follows
[Semantic Versioning](https://semver.org/).

## [1.20.1] - 2026-08-28

### Changed

- High-frequency search controls now meet the theme's 44px touch-target contract:
  both search fields and their clear buttons, the collapsed header search toggle,
  sort and view controls, export actions, copy-link action, federated-search clear
  action, and both pagination implementations.
- Compact corpus tabs remain deliberately exempt because expanding thirteen tabs
  would make the mobile selector substantially harder to scan.

### Internal

- Added a source-level regression test for the shared `--size-control-lg` contract.
- Corrected the package-lock root version, which had remained on 1.18.1.

## [1.20.0] - 2026-08-14

### Fixed

- **Dark mode is the theme's, not the operating system's.** Three components —
  `ResultItem`, `Sparkline` and `MapView` — branched on
  `@media (prefers-color-scheme: dark)` and `matchMedia` instead of the
  `[data-theme]` attribute the theme writes to `<html>` and `<body>` before first
  paint. A visitor on a system-dark machine who chose light got a light page
  carrying a dark-matter basemap, dark-tuned thumbnail filters and dark
  opacities; choosing dark on a system-light machine inverted the same three.
  They now follow the same switch as the rest of the client.
- **The map is part of the site again.** `MapView` painted clusters `#007a50`,
  points `#d57912` and every stroke and label `#fff` — raw literals no theme
  token could reach. Three consequences, all fixed: changing the brand colour in
  theme settings re-tinted the whole site except this map; the point colour was
  the raw Braun pigment rather than `--accent` (`#ca7210`); and the white stroke
  was the exact literal DRE-theme retired `--white` to prevent. Colour now
  resolves through a token bridge at paint time and re-resolves when the theme
  toggles, so the map follows both the toggle and the admin's brand colour.
- `FederatedApp` read `var(--danger, …)`, a token the theme has never defined, so
  its error border always painted the hard-coded `#b42318`. It reads `--error`.
- `Sparkline` read `var(--type-entity-term, …)`, which nothing anywhere defined.
  DRE-theme now publishes the entity-type colour family and this resolves.

### Changed

- **One rhythm.** Result text was set at `1.5` where the theme's `--leading-normal`
  is `1.6`, so a result list and a browse list on the same page, in the same
  family at the same size, had visibly different rhythm. All 34 hand-set
  line-heights now come off the `--leading-*` scale. (`line-height: 1` on the
  clear-button glyph stays — that is a reset, not rhythm.)
- **540 `var(--token, literal)` fallbacks now carry the theme's own values.**
  They had been written by hand and drifted into a second design system: `--muted`
  appeared as four different greys (none of them `#716a66`), `--text-xs` as four
  sizes all smaller than the 13px the token carries, and `--ink` resolved to two
  different inks depending on whether it was nested. None of it was visible in
  production — the token always wins when the theme is loaded — which is exactly
  why it drifted. The values are generated from DRE-theme's OKLCH source now, and
  the lint checks every one of them.
- **MapLibre comes from the vendored copy.** The client injected
  `cdn.jsdelivr.net` script and stylesheet tags at runtime and loaded Carto
  basemap tiles — two third-party origins on an EU-hosted site whose theme
  self-hosts its fonts specifically to avoid them, and a duplicate renderer
  whenever DRE Visualizations rendered on the same page. The loader now prefers a
  copy already on the page, then the same-origin copy DRE Visualizations vendors,
  and reaches the CDN only as a floor.
- Off-scale type is on the scale: `font-size: 1.25rem` in the search box (and six
  others) now read from `--text-*`. The two `em` sizes stay — those are
  deliberately relative to their parent, a different mechanism from the rem scale.
- `999px` pill radii read `--radius-full`; the export menu's raw `z-index: 30`
  reads `--z-dropdown`; its hand-set `80ms` transition reads `--transition-fast`.

### Internal

- `scripts/check-design-tokens.mjs` is now a thin config over
  `scripts/lib/token-rules.mjs`, vendored verbatim from DRE-theme so all three
  repositories run one rule set. The old script had four rules and no rem check,
  which is why `font-size: 1.25rem` sat in the search box, and it stripped
  `var(--x, …)` before applying any rule, which is why no fallback was ever
  checked. Four rules are new: fallback literals, `--leading-*`, the z-index
  scale, and `@media` widths against the shared breakpoint ladder.
- `scripts/design-token-allowlist.txt` records what is not yet converted, per
  rule per file. It is a backlog, not a set of exemptions: lines should only ever
  be removed. 50 lines today — 24 off-scale spacing, 16 px geometry, 8 `@media`
  widths off the ladder, 2 radii.
- New `src/svelte/lib/tokenBridge.ts`: prefers DRE-theme's `window.DRETokens` and
  falls back to an equivalent local probe, so the client still resolves tokens
  when mounted in a host without the theme.

## [1.19.2] - 2026-08-07

### Fixed

- Result cards broke apart on narrow screens wherever a click-to-filter value was
  long enough to wrap — worst on a publication's reference line, where the venue
  title was centred against the left-aligned text around it and stranded
  ` (eds.),` and `, pp. 25–48.` on lines of their own. The cause was the element,
  not the CSS: those values were `<button>`s, and a button is an atomic
  inline-block, so it cannot break across the line boxes of the sentence it sits
  in — a wide one claims the full column width, and the UA's `text-align: center`
  for buttons centres whatever it wraps internally. They are now `FilterLink`
  spans (`role="button"`, `tabindex="0"`, Enter/Space), which fragment like the
  text around them. A 375px-wide reference went from seven ragged lines to five
  flush ones. One shared component replaces six identical copies of the style, so
  every corpus is covered: research items (authors, place of origin, current
  location, language), publications (authors, editors, venue, publisher),
  projects (PIs), research sections (leaders), podcasts and videos (language).
  Frontend only — no reindex, no config.

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

### Packaging

- First tagged release, so the module is now installable from a release asset
  (`DRESearch.zip` + its SHA-256) instead of a `git clone` plus a
  `composer install`. The release workflow additionally asserts the tag matches
  `config/module.ini`, and that the archive contains `Module.php`,
  `config/module.ini`, the built bundle and `vendor/autoload.php` while
  containing no dev tooling.
- Development files no longer leak into the archives. `.gitattributes` gained
  `export-ignore` rules — which is what governs GitHub's auto-generated "Source
  code" tarballs — and the release workflow's own exclude list was widened to
  match: `tests/`, `scripts/`, `.github/`, and the Node/PHPUnit/PHPStan/ESLint/
  Prettier/TypeScript config files are all out. `docs/` and `src/svelte` (the
  GPL sources for the compiled bundle) deliberately stay in.
- `LICENSE` now carries the verbatim GPL-3.0 text rather than a short notice, so
  the licence is machine-detectable; the copyright notice moved to the README.
- Added `CITATION.cff` (ORCID, affiliation, SPDX licence), wired into the
  existing CI version-consistency check so it cannot drift from
  `config/module.ini`.

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
