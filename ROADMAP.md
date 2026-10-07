# Roadmap

What is planned for DRE Search, what is under way, and what was decided
against. Most items come from the module review of 2026-10-07. Shipped work
moves to the [changelog](CHANGELOG.md); update an item's status here when work
on it starts or lands.

Status: **done** (merged, not yet released) · **in progress** · **planned** ·
**idea** (needs a decision first).

## Next release

Nothing is in progress. Pick from **Planned** below and record the item here
when work starts.

## Planned

| Item                                                                                                                                                                                    | Notes                                                                   |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| **PHPUnit 12.** 12.5.38 (2026-10-07) needs PHP 8.3, which the new floor allows; 13.x needs PHP 8.4.1.                                                                                   | Next.                                                                   |
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

- **PHP.** 8.3 is the minimum since 1.25.0; CI runs 8.3–8.5, and production
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
