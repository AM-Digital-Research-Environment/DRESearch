# Roadmap

What is planned for DRE Search, what is under way, and what was decided
against. Most items come from the module review of 2026-10-07. Shipped work
moves to the [changelog](CHANGELOG.md); update an item's status here when work
on it starts or lands.

Status: **done** (merged, not yet released) · **in progress** · **planned** ·
**idea** (needs a decision first).

## Next release

**Done: the cross-module review with IwacSearch (2026-10-28).** The two
modules were compared file by file, and what IwacSearch did better is now
merged here. The rows below are merged, not yet released; they replace the
**Planned** rows for smaller server classes, a search controller module and a
scoped search-only key. The release needs a reindex of people,
organisations, research items and the four term corpora (trimmed labels;
[operations](docs/operations.md#upgrading-from-132)).

| Area    | Item                                                                                                                                                                                                                                                                                                                                                     | Status                                                                                                                                                                                                                                                                                                                          |
| ------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Client  | Search, map and federated fetching move out of `App.svelte` and `FederatedApp.svelte` into tested `.svelte.ts` modules (`searchResults`, `federatedResults`); one combobox (`typeahead`) replaces the duplicated one of `SearchBox`/`SearchBar`; card field derivations become pure, tested functions (`resultCard.ts`).                                 | **done**. `App` still owns the search state and the URL sync (its script is about 580 lines): the fetching and its rules moved, not the state.                                                                                                                                                                                  |
| Client  | Live region announces settled results only, after a pause; result counts use `Intl.PluralRules`; a typing burst is one history entry; card filter values are labelled toggles with `aria-pressed`; a skip link to the results; every request has a deadline; tests run in a west-of-Greenwich timezone; touch targets measured in the browser.           | **done**. The chips are real `<button>`s (`FilterButton`); the values inline in a citation stay `role="button"` spans, because a `<button>` cannot wrap across the paragraph's lines (measured; `FilterLink.svelte`'s docblock). Keyboard and screen-reader semantics come from role, tabindex, Enter/Space and `aria-pressed`. |
| Server  | `SearchProxy` split (`FacetRecount`, one `BulkPager` for export and map, `ResponseNormalizer`, `HealthReport`); `QueryBuilder` modes from one matching core; per-kind suggestion subtitles in profile config (`suggest_subtitle`); the federated search validated and scoped once.                                                                       | **done**. `SearchProxy` keeps the orchestration every endpoint shares (validation, gate, breaker, cache, logging).                                                                                                                                                                                                              |
| Server  | Block render: first page cached, skipped when the URL carries the block's state (`SearchStateQuery`); federated year bounds in one request; the header bar no longer builds the proxy; one bootstrap builder (`SurfaceBootstrap`) for blocks, Project records and federated tabs; a noscript link to item browse.                                        | **done**                                                                                                                                                                                                                                                                                                                        |
| Server  | One data-driven block layout class (`SearchBlock` + `BlockProfiles::LAYOUTS`) instead of twelve subclasses (stored layout names unchanged); a search-only Typesense key for the public proxy, configured or minted, the admin key kept for indexing; locked filters refuse backslashes.                                                                  | **done**                                                                                                                                                                                                                                                                                                                        |
| Indexer | One kind → mapper registry (`MapperRegistry`); `PersonMapper`/`OrganisationMapper` merged into `ReverseLinkMapper` on `ValueBag::labels` (trimmed); mapper and assembler unit tests; Typesense plumbing in one injectable class (`CollectionOps`, `ChangeApplier`); exact source counts before promotion; property ids resolved once; dead code removed. | **done**. Needs the reindex above.                                                                                                                                                                                                                                                                                              |
| Tooling | `npm run bump` as the only version writer; gzip bundle budgets; `npm audit` with documented exceptions; Lighthouse budgets in CI; MapLibre pin checked against DRE Visualizations; release jobs split by privilege; stacked-docblock check; PHPUnit in random order.                                                                                     | **done**. The Lighthouse gate runs in bootstrap mode until `tests/lighthouse/baseline.json` is committed from a CI run (CONTRIBUTING.md).                                                                                                                                                                                       |
| Docs    | `CLAUDE.md` and a code map; the AMIRA data model moves out of the README; tests named by module rather than by release; the docs and comments brought up to date with the merged code.                                                                                                                                                                   | **done**                                                                                                                                                                                                                                                                                                                        |

## Planned

| Item                                                                                                                                                                             | Notes                                                         |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| **PHPUnit 13.** Every 13.x release needs PHP 8.4.1, and the committed lock is resolved for exactly the declared floor (8.4.0); take it with the next floor raise.                | With the next PHP floor.                                      |
| **Autocomplete on the federated page's search box**, reusing `/dre-search/api/suggest-all`.                                                                                      | The shared combobox (`lib/typeahead.svelte.ts`) can serve it. |
| **Fewer round trips:** the federated search (counts + active corpus) in one `multi_search`; export and map pages in one `multi_search`; Typesense `use_cache` for tab counts.    | Low priority.                                                 |
| **Optional purge on uninstall** of the module's Typesense collections, aliases, stopword and synonym sets, and analytics collections. Today uninstall logs them and leaves them. | Opt-in.                                                       |

## Ideas

| Item                                                                                                                                                    | Decision needed                             |
| ------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| **Pinned results** via Typesense curation sets (as in IwacSearch), with a worklist of searches that found nothing on the maintenance page.              | Who curates, and for which searches.        |
| **Multi-site:** index each item's `site_ids`, filter by the current site, and build item links with Omeka's URL helper instead of string concatenation. | Whether partner installs run several sites. |

## Policies

- **PHP.** 8.4 is the minimum since 1.32.0 (8.3 from 1.25.0); CI
  runs 8.4–8.5, and production AMIRA runs 8.5. The code must still parse on
  PHP 8.2 so that an under-version server degrades instead of failing, so
  syntax newer than 8.2 (typed class constants, for instance) waits for a
  later release.
- **Platform.** Omeka S `^4.2`, Typesense 30.x. Never bundle `laminas/*` or
  `psr/*` packages.

## Decided against

- **Semantic search / embeddings** (a "Similar items" link): decided against on
  2026-10-07. Search stays keyword-only and lean.

## Done since the 2026-10-07 review

- **1.22.1:** the 1.21.x → 1.22.0 upgrade fix (#33), the guard for a missing
  `vendor/`, tests run through Omeka's PHP 8.5 bootstrap, highlights for
  matches in list fields.
- **1.23.0:**
  - scoped change queue, hiding only the pending records, and a single
    background worker that heals a stranded queue;
  - rejected-document tolerance and the shrink guard;
  - request limits, time budgets and a circuit breaker;
  - accessibility and history fixes, translation support, block validation
    and block templates;
  - health endpoint and CLI, Guzzle 8 from a committed lock, CI-gated releases,
    PHPStan level 8;
  - synonyms, citations, record links, typed years, page numbers in the
    federated URL, opt-in popular searches.
- **1.24.0:** the bundle preloads from a Vite manifest (one round trip
  instead of three), the cards share one stylesheet, one pager, every
  thumbnail through one helper with `srcset`, and the MapLibre CDN fallback
  fixed and pinned with Subresource Integrity.
- **1.25.0:** PHP 8.3 minimum (an older PHP degrades instead of failing the
  site); popular searches shown only after an editor approves them; research
  items dated with a bare year or decade no longer stored with today's date;
  PHPStan on `tests/`, type-aware ESLint, a CI check on where PSR Log loads
  from, Omeka's real schema in the integration tests, and new indexer and
  delete-path tests.
- **Repository:** Dependabot security updates, secret scanning and push
  protection on (2026-10-07); issue #22 closed; the AMIRA deployment pins
  v1.25.0.
