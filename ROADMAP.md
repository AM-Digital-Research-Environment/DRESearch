# Roadmap

What is planned for DRE Search, what is under way, and what was decided
against. Most items come from the module review of 2026-10-07. Shipped work
moves to the [changelog](CHANGELOG.md); update an item's status here when work
on it starts or lands.

Status: **done** (merged, not yet released) · **in progress** · **planned** ·
**idea** (needs a decision first).

## 1.24.0 (in preparation)

| Item                                                                                                                                                                                                                                                       | Status      |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------- |
| **PHP 8.3 minimum.** 8.2's security support ends 2026-12-31. Below 8.3 the module degrades (no install; search unavailable) instead of failing the site.                                                                                                   | done        |
| **Popular-search moderation.** Anyone can inflate the analytics counts (`record_query` with fresh ids), so editors approve each popular search before visitors see it. Also documents the inflation and states that `is_public:=true` is defense in depth. | done        |
| **Preloading.** Emit `modulepreload` and a style preload from a Vite manifest; the bundle currently loads in three sequential steps.                                                                                                                       | in progress |
| **Shared card shell.** About 17.8 KB of the App CSS repeats the same card rules across ten card components.                                                                                                                                                | in progress |
| **Thumbnails.** `srcset`/`sizes` on Omeka derivatives; every card resolves images through `thumbnailFor`.                                                                                                                                                  | in progress |
| **One pager.** `Pagination.svelte` and the pager inside `ResultsList.svelte` do the same job.                                                                                                                                                              | in progress |
| **SRI** on the MapLibre CDN fallback.                                                                                                                                                                                                                      | in progress |
| **Indexer unit tests:** research-item dates, authority lookups, section phase, podcast episode numbers.                                                                                                                                                    | done        |
| **Delete-path tests:** a media delete queues its item; an item-set delete queues its members.                                                                                                                                                              | done        |
| **Real Omeka schema** (`application/data/install/schema.sql`) in the integration tests instead of a hand-written one.                                                                                                                                      | done        |
| **Stricter checks:** PHPStan on `tests/`, type-aware ESLint (`no-floating-promises`), and a CI check that `Psr\Log\LoggerInterface` loads from Omeka core.                                                                                                 | done        |
| **Bare-year dates.** Items dated "1950" or "1890s" were indexed with today's timestamp in `date` (`strtotime()` reads a bare year as a clock time); found by the new indexer tests. Rebuild research items after upgrading.                                | done        |

## Planned

| Item                                                                                                                                                                                    | Notes                                                                   |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| **PHPUnit 12.** 12.5.38 (2026-10-07) needs PHP 8.3, which the new floor allows; 13.x needs PHP 8.4.1.                                                                                   | After the 1.24.0 test work merges.                                      |
| **Autocomplete on the federated page's search box**, reusing `/dre-search/api/suggest-all`.                                                                                             |                                                                         |
| **Fewer round trips:** the federated search (counts + active corpus) in one `multi_search`; export and map pages in one `multi_search`; Typesense `use_cache` for tab counts.           | Low priority.                                                           |
| **Smaller server classes:** split `SearchProxy` (result normalising, paged collection) and build `QueryBuilder` modes from a shared core instead of `unset()` on the search parameters. | Prevents a new parameter leaking into counts, export, map and recounts. |
| **A search controller module** extracted from `App.svelte` (800+ lines), with direct tests.                                                                                             |                                                                         |
| **Scoped search-only Typesense key** for the public proxy (least privilege); the admin key stays for indexing.                                                                          |                                                                         |
| **Optional purge on uninstall** of the module's Typesense collections, aliases, stopword and synonym sets, and analytics collections. Today uninstall logs them and leaves them.        | Opt-in.                                                                 |

## Ideas

| Item                                                                                                                                                    | Decision needed                             |
| ------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| **Pinned results** via Typesense curation sets (as in IwacSearch), with a worklist of searches that found nothing on the maintenance page.              | Who curates, and for which searches.        |
| **Multi-site:** index each item's `site_ids`, filter by the current site, and build item links with Omeka's URL helper instead of string concatenation. | Whether partner installs run several sites. |

## Policies

- **PHP.** 8.3 is the minimum since 1.24.0; CI runs 8.3–8.5, and production
  AMIRA runs 8.5. The code must still parse on PHP 8.2 so that an
  under-version server degrades instead of failing, so syntax newer than 8.2
  (typed class constants, for instance) waits. Raise the minimum to 8.4 before
  8.3's security support ends on 2027-12-31, then adopt 8.3 syntax.
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
- **Repository:** Dependabot security updates, secret scanning and push
  protection on (2026-10-07); issue #22 closed; the AMIRA deployment pins
  v1.23.0.
