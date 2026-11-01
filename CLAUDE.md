# CLAUDE.md

Guidance for coding agents working in this repository. People start at the
[README](README.md) and [CONTRIBUTING.md](CONTRIBUTING.md); the map of the code
is [docs/code-map.md](docs/code-map.md), the lifecycles are in
[docs/architecture.md](docs/architecture.md), and the AMIRA templates, item sets
and properties behind each corpus are in [docs/data-model.md](docs/data-model.md).

## What this is

An Omeka S 4.2 module (`DRESearch\`) that adds Typesense-backed faceted search to
the Africa Multiple cluster's AMIRA site: twelve corpora ("search profiles"),
one page block each, a site-wide header bar with federated autocomplete, and a
federated results page. Browser → PHP proxy → Typesense 30.2. The client is
Svelte 5 + TypeScript, built by Vite. Its sibling, IwacSearch, solves the same
problem the other way round (browser → Typesense with a scoped key); the two
borrow from each other, but the boundaries below are this module's own.

## Invariants

- **The browser never talks to Typesense.** It calls `/dre-search/api/*`; PHP
  holds the keys. Public searches run with a search-only key
  (`documents:search` on the profiles' aliases;
  `Search\TypesenseSearchKeyProvider`: configured, else minted with the admin
  key, else the admin key), and the admin key serves indexing, health,
  analytics and maintenance. `is_public:=true` is hard-coded in
  `Search\QueryBuilder`, never taken from a request. A block's locked filter and
  the Project records scope are rebuilt on the server from `block_id` /
  `scope_item` (`BlockScopeResolver`, `ResourceScopeResolver`); a browser cannot
  send a raw filter, and `FilterExpression` refuses an editor's filter that
  could escape its group.
- **The indexer reads only what an anonymous visitor may see** — public items,
  values, link targets and media (`Indexer\OmekaSourceRepository`). Omeka's cached
  titles can come from private values, so titles are read from the first public
  value of the title property. The single, opt-in exception is a profile's
  `restricted_fulltext` (off by default): a private full text of public records
  in named item sets, stored as `restricted_text`, which every query excludes
  from payloads and `ResponseNormalizer` returns only as one capped excerpt.
  Never widen the shared public-value filter for anything else.
- **Profiles are the contract.** `dre_search.profiles` in
  `config/module.config.php` (overridable key by key from `local.config.php`)
  drives the Typesense schema, the mappers, request validation, the block
  options and the client bootstrap. The Typesense field names (`type_s`,
  `people_ss`, `year`, …) are the stable interface. `npm run lint:schema` fails
  when profiles, schema and client contracts drift. A new corpus is a profile
  and a block layout (a row in `Settings\BlockProfiles::LAYOUTS`); a new kind
  adds a mapper (one line in `Indexer\MapperRegistry`) and a card
  (docs/profile-configuration.md).
- **Typesense is optional.** No host or key: the module installs, the site runs,
  the blocks say search is unavailable (`TypesenseClientProvider`). Every
  integration degrades rather than fails.
- **A rebuild never serves a half-built index.** Each run builds a fresh,
  timestamped collection, verifies it, reconciles it with the profile's public
  records in SQL (`source_count_mismatch` otherwise) and swaps the alias; every
  collection, document and alias call of the indexer goes through
  `Indexer\CollectionOps`, which reads an ambiguous alias write back. A generation holding less than
  `min_retained_ratio` (half) of the live one is refused unless the operator
  allows the shrink; the previous generation is kept for rollback.
- **Saves never wait on Typesense.** Omeka write events queue SQL work for the
  profiles whose scope holds the item (`ScopeMatcher`, the same `SourcePredicate`
  the indexer uses); one coalesced worker (`WorkerLease`) drains it.
  `ReadinessGate` hides queued ids from public results, or pauses a profile, so
  a record just made private is never served from a stale copy.
- **Never bundle `laminas/*` or `psr/*`.** Omeka's vendor provides them; a second
  copy fatals the site. Monolog stays on 2.x (core's PSR Log 1), and CI checks
  PSR Log loads from core (`scripts/check-psr-log-origin.php`).
- **PHP 8.4 is the floor, but the code must parse on 8.2**, so an under-version
  server degrades instead of failing: no syntax newer than 8.2 (typed class
  constants, for one).

## Module lifecycle

- `Module::install()` / `upgrade()` create and migrate the `dre_search_*` tables;
  `uninstall()` drops them, revokes the search-only keys the module minted, and
  leaves Typesense collections in place (it logs them).
- **`upgrade()` runs while the module is inactive**: its own factories are not
  registered, so it can use only Omeka's services and plain DBAL. The 1.22.0
  upgrade broke on exactly this.
- **A new public controller action must join `Module::PUBLIC_ACTIONS`** (the ACL
  allow-list in `onBootstrap()`). Without it an anonymous request is a 500
  (`PermissionDeniedException`) before the action runs — v1.12.1.
- The write listeners are resolved on the first write event, not on every
  request: an anonymous page view never builds the indexer graph.
- Blocks store an explicit facet list, so **a facet added to a profile stays
  hidden on saved blocks** until an editor ticks it. Say so in the changelog.
- Stored block layout names, block data, URL parameters and Typesense schemas of
  existing installs must keep working; a change that needs a reindex says so in
  the changelog's **Upgrade:** line.

## Conventions

- PHP: strict types, PSR-12 (`composer lint`), PHPStan level 8
  (`composer analyse`). Rich "why" docblocks and comments; British English in
  prose.
  `npm run lint:docblocks` fails on two stacked docblocks (only the last one
  attaches to the symbol).
- Client: Svelte 5 runes, type-aware ESLint (mark a deliberate fire-and-forget
  promise with `void`), Prettier. Style with DRE-theme's tokens:
  `npm run lint:tokens` runs the theme's rules, vendored into `scripts/lib/` and
  hash-checked — change them in DRE-theme, never here.
- The DRE theme no longer paints bare `<button>`s (the module dropped its
  `!important` resets in 1.15.2), so the components' single-class rules hold
  without them; the shared outline button is `styles/buttons.css`. Host-theme
  cascade is still invisible in source: measure CSS on the rendered page before
  and after a fix.
- Strings: after changing a user-visible string run `npm run i18n` and commit
  `language/template.pot` and `data/client-strings.json`.
- Every `QueryBuilder` mode (counts, export, map, facet recounts, union) is
  built from one matching core, `matching()`, plus only what it renders: a
  parameter that changes what matches goes in the core, never in one mode.
- The Typesense server rejects field combinations the schema builder cannot see
  (a `geopoint` must be sortable, for one): validate a schema change against a
  live Typesense, not only the unit tests.

## Checks

```sh
npm run lint && npm run check && npm run build && npm test
npm run test:browser      # after markup, CSS or client behaviour changes (stubbed, no services)
composer lint
vendor/bin/phpunit        # integration cases skip without the services below
composer analyse
```

`npm run build` ends with `npm run check:size`: gzipped budgets for the entry,
its shared runtime and the App / FederatedApp chunks, which must stay lazy.
Raise a budget only deliberately, in the commit that needs it.

The full PHP suite needs the released Omeka S 4.2.1 tree, MySQL 8.4 and
Typesense 30.2 (CONTRIBUTING.md has the variables: `OMEKA_VENDOR`,
`DRE_TEST_MYSQL_*`, `TYPESENSE_*`); CI runs it with `--fail-on-skipped`. Things
that have bitten before:

- **PHPUnit runs in random order** and fails risky tests. It prints its seed;
  `vendor/bin/phpunit --random-order-seed <seed>` repeats an order.
- **A test Typesense keeps server-level state between runs**: the `dre_default`
  stopword set and the `dre_synonyms` synonym set survive, and hide bugs a fresh
  volume (CI's) shows. Delete both before a release run or after touching
  anything that references them.
- PHPStan caches results across environments: run
  `vendor/bin/phpstan clear-result-cache` after `OMEKA_VENDOR` or the
  dependencies change.

CI also runs `npm run check:audit` (documented exceptions only),
`lighthouse.yml` (budgets for a block page and the federated page; the baseline
comes from the CI runner, never a laptop) and, weekly, `maplibre-pins.yml` (the
map's CDN fallback must pin what DRE Visualizations vendors).

## Versions and releases

- **Never edit a version by hand.** `npm run bump -- <patch|minor|major|X.Y.Z>`
  writes module.ini, package.json, both lockfile copies and CITATION.cff (with
  `date-released`), and turns the CHANGELOG's `## [Unreleased]` notes into the
  dated section; `npm run lint:version` is its guard. The sites are data in
  `scripts/shared/versions.mjs`.
- Changelog entries go under `## [Unreleased]` as the work lands; the release
  notes are sliced from that section, and the bump refuses an empty one.
- A release is a `vX.Y.Z` tag on `main`. `release.yml` runs the whole CI suite,
  builds and verifies `DRESearch.zip` with a read-only token, then attests and
  attaches it. Sites install that zip (it carries `vendor/` and `asset/dist`);
  pushing to `main` deploys nothing.

## The local dev stack

The AM `omeka-s-docker` dev stack serves AMIRA at http://127.0.0.1:8080/s/amira
(containers `omeka-dre-dev-{php,web,db,typesense}-1`).

- Modules there are **copies, not bind mounts**: deploy with `docker cp`
  (`src/`, `config/`, `view/`, `data/`, `asset/dist/.`). In Git Bash prefix
  container paths with `MSYS_NO_PATHCONV=1`.
- A copied `module.ini` newer than the database's module version **disables the
  module** until it is upgraded. Run Omeka's real upgrade, not a hand-edited
  version row, or `upgrade()` never runs.
- php-fpm runs with `opcache.validate_timestamps=0`: after copying PHP, restart
  the php container (nginx answers 502 until it is back). The CLI sees new code
  at once.
- Errors: uncaught exceptions go to `docker logs omeka-dre-dev-php-1`; job and
  indexing messages to `/var/www/html/logs/application.log`.
- A redeploy at the same version keeps serving the browser's cached
  `dre-search.js?v=…`, which then loads old chunk names: reload each asset with
  `cache: 'reload'` before judging a change in the browser.

## Linked repositories

- [DRE-theme](https://github.com/AM-Digital-Research-Environment/DRE-theme) — the theme; owns the design tokens and the lint rules vendored here
- [DRE-Visualizations](https://github.com/AM-Digital-Research-Environment/DRE-Visualizations) — dashboards; vendors the MapLibre this module prefers
- [DRE-SEO](https://github.com/AM-Digital-Research-Environment/DRE-SEO) and [DRE-LinkedData](https://github.com/AM-Digital-Research-Environment/DRE-LinkedData) — sibling Omeka modules
- [MongoDB2OmekaS](https://github.com/AM-Digital-Research-Environment/MongoDB2OmekaS) — the pipeline that fills the Omeka instance (private)
- [omeka-s-docker](https://github.com/AM-Digital-Research-Environment/omeka-s-docker) — the deployment stack; pins the release zip
- [IwacSearch](https://github.com/fmadore/IwacSearch) — the sibling search module this one is reviewed against
