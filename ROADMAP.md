# Roadmap

What is planned for DRE Search, what is under way, and what was decided
against. Most items come from the module review of 2026-10-07. Shipped work
moves to the [changelog](CHANGELOG.md); update an item's status here when work
on it starts or lands.

Status: **done** (merged, not yet released) · **in progress** · **planned** ·
**idea** (needs a decision first).

## Next release

**Done: the coherence review of the DRE theme and modules (2026-11-10).**
Merged, not yet released; no reindex needed.

| Area    | Item                                                                                                                                                         | Status   |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------- |
| Client  | The result views (list, grid, map) come from the profile in the bootstrap, not from the profile's name (#55).                                                | **done** |
| Client  | The header bar's styling surface: documented custom properties on `.dre-search-bar` (#59). The theme still overrides the internal classes until it switches. | **done** |
| Indexer | The mappers share one document skeleton; no vocabulary fallbacks in mapper code; a snapshot of every profile's documents; one list of kinds, checked (#56).  | **done** |
| Server  | The health endpoint reports when public search has fallen back to the admin key; one table definition for install and uninstall (#57).                       | **done** |

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
- **1.33.0–1.35.0:** the cross-module review with IwacSearch (2026-10-28):
  fetching moved out of `App` and `FederatedApp` into tested modules, one
  combobox, pure card derivations; settled-only announcements, plural rules,
  labelled filter toggles, a skip link, request deadlines; `SearchProxy` split
  and every `QueryBuilder` mode from one matching core; cached first pages and
  one bootstrap builder; one data-driven block layout class; a scoped
  search-only Typesense key; the mapper registry and `CollectionOps`; exact
  source counts before promotion; `npm run bump`, bundle and Lighthouse
  budgets. Then a restricted full-text policy (1.34.0) and a stable project
  key (1.35.0).
- **Repository:** Dependabot security updates, secret scanning and push
  protection on (2026-10-07); issue #22 closed; the AMIRA deployment pins
  v1.25.0.
