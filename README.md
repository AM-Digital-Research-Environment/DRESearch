# DRE Search

Typesense-backed faceted search for the Africa Multiple **DRE** Omeka S instance
(the one populated by [MongoDB2OmekaS]). It indexes content directly from the
Omeka dashboard via MySQL — there is no external ingestion pipeline.

Twelve search corpora ship out of the box, each as its own page block (the
template, item set and Omeka properties behind each are in
[docs/data-model.md](docs/data-model.md)):

- **Research items search** — the digitised research items.
- **Research projects search** — the cluster's research projects.
- **Publications search** — the cluster bibliography (journal articles, books,
  chapters, theses, … — one item set across several templates).
- **Podcasts search** — the cluster's podcast episodes, filterable by series, the
  people in each episode (hosts + guests), and language; sortable by episode
  number or date. Cards show the series logo as the thumbnail, the abstract, and
  a "Listen" link.
- **YouTube videos search** — the cluster's recorded talks, interviews, panels and
  screenings on YouTube, filterable by playlist, speaker, and language, with a
  year slider. Cards show the video thumbnail, the abstract, the speakers, a
  "Transcript" badge, and a "Watch" link; search covers the transcript.
- **People search** — researchers and contributors, filterable by affiliation
  and role, with per-person research-item and publication counts.
- **Research sections search** — the cluster's thematic sections, filterable by
  phase and associated person, with per-section project and member counts.
- **Organisations search** — institutions _and_ groups (one item set), split by
  a Type facet and filterable by the role each plays, with per-organisation
  project, research-item, and affiliated-people counts.
- **Genres search** — the genre/form authority terms, with a per-genre
  research-item count.
- **Languages search** — the language authority terms, with per-language
  research-item and publication counts.
- **Locations search** — the place authority terms, split by a Type facet
  (Country / geographic location), with a per-place research-item count.
- **Subjects & tags search** — the subject authority terms, both LCSH headings
  and tags in one corpus split by a Type facet, with per-term research-item and
  publication counts.

All are **search profiles**: independent Typesense collections + facet/index
mappings, all config-driven. Adding a further corpus is a config block plus a
mapper — not a rewrite. The last four share the generic **`term`** kind (one
`TermMapper` + one `TermCard`): an authority item set whose substance is the
reverse count of the records that reference each term.

**Typesense is optional.** With no connection configured the module installs
cleanly and the site runs normally; the search blocks just show a quiet
"search unavailable" notice. This keeps the module reusable on installs that
don't want a search backend.

> **1.22.0 upgrade:** run the Omeka module upgrade and **Reindex all corpora**.
> This rebuild applies corrected privacy rules. Search pauses while profiles are
> dirty or have pending changes; **Retry pending changes** recovers interrupted
> background indexing. See [the operations runbook](docs/operations.md).

## What you get

- A **Research items search** page block: full-text search with autocomplete,
  faceted filtering, sorting, pagination, and result cards (title, type,
  project, origin year, **place of origin**, **current location**, thumbnail).
  An item with no image shows its type's icon instead (a film for Moving image,
  a waveform for Audio, a scroll for Manuscript…).
  - Facets: a **Year** range slider, plus **Type, Project, Place of origin,
    Country, Current location, Language, Subject, Tag, Target audience,
    Digitisation method**.
    (**Place of origin** is the exact place recorded; **Country** is that rolled
    up to the country for broad browsing; **Current location** is where the item
    is held now — a specific place or repository institution.)
- A **Research projects search** page block: cards show the project name, year
  range, research section(s), principal investigator(s), funding institution(s),
  and the number of associated research items.
  - Facets: a **Year** range slider, **Institution**, **Research section**, and
    **Has research items** (yes/no).
  - On a card, the **research-section**, **institution**, and **PI** are
    clickable — each adds that value as a filter (a PI filters by **Associated
    people**, so you can pivot to every project that person is involved in).
- A **Publications search** page block: cards show a formatted bibliographic
  reference — title, authors (linked to their person pages), venue (journal or
  book + series), volume/issue, pages, publisher, year, abstract, a **Full text**
  link to the open-access copy on EPub Bayreuth, a DOI link and **Cite**.
  - Facets: a **Year** range slider, a **Full text available** toggle, **Type,
    Author, Journal / Book, Publisher, Keyword, Language**.
- A **Podcasts search** page block: cards show the podcast **series logo** as the
  thumbnail (every episode of a series shares it), the episode number and date, the
  title, a series chip, the hosts, guests and sound engineer (linked to their person
  pages), the language, the abstract, a **Transcript** badge when the episode is
  full-text searchable, and a **Listen** link to the audio / episode page.
  - Facets: **Series**, **People** (a union of hosts and guests), and **Language**.
  - Sort: **Episode number** (default — newest episode first) or **Newest / Oldest**
    by date, plus Relevance / Title.
  - Search covers the title, abstract, transcript, and the people in each episode.
- A **YouTube videos search** page block: cards show the video's own poster-frame
  thumbnail, the date, the title, a playlist chip, the speakers (linked to their
  person pages), the language, the abstract, a **Transcript** badge when the video
  is full-text searchable, and a **Watch** link to YouTube.
  - Facets: a **Year** range slider, **Playlist**, **Speaker**, and **Language**.
  - Sort: **Newest / Oldest** by date (newest first by default), plus Relevance /
    Title.
  - Search covers the title, abstract, transcript, and the speakers in each video.
- A **People search** page block: cards show the person's name, affiliation(s),
  role chips, and how many research items and publications they're associated
  with (laid out two-up on wide screens, since the cards are compact).
  - Facets: **Affiliation** and **Role** — Principal investigator, Project member,
    Author, Editor, plus the specific contributor role each person plays on a
    research item (Author, Photographer, Interviewee, Translator, Collector, … —
    one per `marcrel:*` relator actually in use).
- A **Research sections search** page block: cards show the section name, its
  phase, the project count, the leaders (PIs or spokesperson), the member count,
  and the abstract — including the **External** section. The phase and each
  leader are clickable filters.
  - Facets: **Phase** (1 / 2) and **Associated person** (PIs, spokesperson, or
    members).
- An **Organisations search** page block: one corpus for both institutions and
  groups (bands, choirs, archives, …). Cards show the name, a **Type** chip
  (Institution / Group), role chips, and how many projects, research items, and
  affiliated people the organisation is associated with (laid out two-up on wide
  screens, since the cards are compact). The Type and role chips are clickable
  filters.
  - Facets: **Type** (Institution / Group) and **Role** (Funder, Contributor,
    Host institution).
- Four **authority-term** page blocks — **Genres**, **Languages**, **Locations**,
  and **Subjects & tags** — each a searchable, paginated list of the terms applied
  across the collection, laid out two-up on wide screens. Cards show the term, an
  optional **Type** chip, and how many records use it; the term links to its Omeka
  page. They sort by **Most research items** by default (also Relevance / Title);
  being date-less, they offer no Newest/Oldest.
  - **Genres**: per-genre research-item count. No facets.
  - **Languages**: per-language research-item and publication counts. No facets.
  - **Locations**: a **Type** facet (Country / geographic location) and a
    **Relationship** facet — **Place of origin** (`dcterms:spatial`) vs **Current
    location** (`dcterms:provenance`); per-place research-item count.
  - **Subjects & tags**: a **Type** facet (LCSH subject / tag); per-term
    research-item and publication counts.
- A dashboard **Reindex** action per corpus (Admin → DRE Search) that rebuilds
  an index as a background job, reading the Omeka database and pushing to
  Typesense in batches.

## Global (federated) search — header bar + results page

Beyond the per-corpus blocks, the module exposes a **site-wide search** across
**all** corpora at once, designed to replace a theme's header search:

- A **header search bar** (a `dreSearchBar` view helper the theme drops into its
  header) with a **federated autocomplete**: one Typesense `multi_search` over
  every corpus, suggestions **grouped by type** (Research item / Person / Project
  / Location / …). Picking a suggestion jumps straight to that record's page;
  pressing Enter / "See all results" goes to the results page.
- A **federated results page** at **`/s/{site-slug}/dre-search`** (`?q=…`):
  an **All** tab uses Typesense 30's union search for one relevance-ranked stream,
  followed by one **tab per corpus** with its hit count.
  Selecting a tab reveals **that corpus's own facets, cards, sorting and
  paging** — it reuses the same per-corpus search UI as the blocks. The initial
  union scope is curated to content and named-entity corpora so generic authority
  terms do not swamp the ranking. Union responses do not carry facet counts;
  choose a corpus tab to filter them.

Three server endpoints back it (top-level, anonymous, `is_public:=true` enforced):
`/dre-search/api/suggest-all` (grouped autocomplete) and
`/dre-search/api/search-all` (per-corpus counts + the focused corpus's results),
plus `/dre-search/api/union` (the merged result stream). They search with the
server-held search-only key; no Typesense key reaches the browser.

**Theme integration** is one guarded helper call — the theme degrades to its own
search form when the module is absent:

```php
<?php // near the top of layout.phtml, before <head> is rendered: ?>
<?php if ($this->getHelperPluginManager()->has('dreSearchAssets')): ?>
    <?php echo $this->dreSearchAssets(); // inject the bundle into <head> ?>
<?php endif; ?>

<?php // in the header markup: ?>
<?php if ($this->getHelperPluginManager()->has('dreSearchBar')): ?>
    <?php echo $this->dreSearchBar('header-desktop', 'd-none d-xl-block'); // always-visible ?>
    <?php echo $this->dreSearchBar('header-mobile', 'd-block d-xl-none', true); // collapsible ?>
<?php endif; ?>
```

(The early `dreSearchAssets` call is needed because the header renders as layout
chrome — after `<head>` is already emitted — so the bundle must be injected
before then. Search blocks self-inject, so a page with a block needs only the
header calls; `headScript`/`headLink` dedupe by URL.)

### Styling the header search bar

The bar's class names below `.dre-search-bar` (`__input-wrap`, `__suggest`, …)
are internal and may change. A theme adjusts the bar through these custom
properties instead, set on `.dre-search-bar` or any ancestor; each default is
the bar's own look.

| Property                                      | Default              | Controls                                                                                                                                                          |
| --------------------------------------------- | -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `--dre-search-bar-position`                   | `relative`           | The bar root's `position`. `static` makes the expanded mobile field and the dropdown anchor to the nearest positioned ancestor (a header row) instead of the bar. |
| `--dre-search-bar-min-height`                 | `auto`               | Minimum height of the field's wrapper.                                                                                                                            |
| `--dre-search-bar-radius`                     | `var(--radius-full)` | Corner radius of the input (a pill).                                                                                                                              |
| `--dre-search-bar-suggest-offset`             | `var(--space-1)`     | Gap between the field and the suggestions dropdown.                                                                                                               |
| `--dre-search-bar-suggest-inset-inline-start` | `auto`               | Dropdown's start edge. `0` with the end edge at `0` and the width at `auto` spans the field.                                                                      |
| `--dre-search-bar-suggest-inset-inline-end`   | `0`                  | Dropdown's end edge (right-anchored by default).                                                                                                                  |
| `--dre-search-bar-suggest-width`              | `min(26rem, 92vw)`   | Dropdown width.                                                                                                                                                   |
| `--dre-search-bar-suggest-max-width`          | `none`               | Dropdown maximum width.                                                                                                                                           |
| `--dre-search-bar-overlay-inset-inline-start` | `auto`               | Collapsible bar, expanded: the floating field's start edge.                                                                                                       |
| `--dre-search-bar-overlay-inset-inline-end`   | `0`                  | Collapsible bar, expanded: the floating field's end edge.                                                                                                         |
| `--dre-search-bar-overlay-width`              | `min(20rem, 80vw)`   | Collapsible bar, expanded: the floating field's width.                                                                                                            |
| `--dre-search-bar-overlay-top`                | `50%`                | Collapsible bar, expanded: the floating field's top.                                                                                                              |
| `--dre-search-bar-overlay-transform`          | `translateY(-50%)`   | Collapsible bar, expanded: the floating field's offset from that top.                                                                                             |

For example, a full-width desktop field whose dropdown spans it, and a mobile
field dropped below the header row inside 12px gutters:

```scss
.main-header__search .dre-search-bar {
  --dre-search-bar-min-height: var(--size-control-lg);
  --dre-search-bar-suggest-inset-inline-start: 0;
  --dre-search-bar-suggest-width: auto;
}
.main-header__search-bar.d-xl-none {
  position: static;
  --dre-search-bar-position: static;
  --dre-search-bar-overlay-inset-inline-start: var(--space-3);
  --dre-search-bar-overlay-inset-inline-end: var(--space-3);
  --dre-search-bar-overlay-width: auto;
  --dre-search-bar-overlay-top: 100%;
  --dre-search-bar-overlay-transform: translateY(var(--space-2));
}
```

## Requirements

- Omeka S `^4.2.1` on PHP 8.4+ (production AMIRA runs PHP 8.5). Below 8.4 the
  module does not install, and an existing install reports search unavailable
  instead of failing the site.
- A Typesense 30 server (optional)
- For development only: Node `^20.19`, `^22.13` or `>=24`, and Composer. Sites
  install the release archive, which carries the compiled bundle and `vendor/`.

## Install

1. Download `DRESearch.zip` from the
   [latest release](https://github.com/AM-Digital-Research-Environment/DRESearch/releases/latest)
   and unzip it into Omeka's `modules/` directory (it unpacks to
   `modules/DRESearch`). With the AM `omeka-s-docker` stack the release URL is
   pinned in `deploy/amira/modules.txt`. GitHub's "Source code" archives lack
   `vendor/` and the compiled bundle: the module then refuses to install and
   explains why.
2. Activate **DRE Search** in Admin → Modules (or run **Upgrade** when replacing
   an older version), then **Reindex all corpora** if the release notes ask for it.

From a git checkout instead: `composer install --no-dev && npm ci && npm run build`.

## Enabling Typesense

### With the AM `omeka-s-docker` stack

1. Set a strong key in `.env`:
   ```
   TYPESENSE_API_KEY=<a long random string>
   ```
2. Start the optional search service:
   ```bash
   docker compose --profile search up -d
   ```
   The `php` service already forwards `TYPESENSE_HOST/PORT/PROTOCOL/API_KEY` to
   the module, and Typesense stays on the internal network (never exposed to the
   host).

### Or configure in the admin

Admin → Modules → **DRE Search** → _Configure_: host, port, protocol, API key,
and an optional **search-only key**. Resolution order is **settings →
environment variables → config defaults**, so the admin form overrides
everything. (Collection aliases are per-profile config, not set here — see
below.)

### The search-only key

The API key above is the admin key: indexing, rebuilds, health, analytics and
the maintenance page use it. Public searches run with a key Typesense lets do
only `documents:search` on the corpora's collection aliases, so a bug in the
search proxy cannot write, delete or read anything else. Neither key ever
reaches the browser.

Nothing has to be configured. On the first public search the module creates
that key with the admin key and keeps it in its settings; if it cannot, public
search runs with the admin key and tries again five minutes later. To use a key
of your own, save it in the config form's **Typesense search-only key** field or
set `TYPESENSE_SEARCH_API_KEY`. **Admin → DRE Search → Public search key**
shows which key public search uses and what Typesense lets it do. See
[the operations runbook](docs/operations.md#secrets).

## Building / refreshing the index

Admin → **DRE Search** → **Reindex**: one button per corpus. Progress is logged
to Admin → Jobs. Re-run after significant content changes (or wire the job to a
schedule via the Cron module). Each reindex builds a fresh, timestamped Typesense
collection and swaps that profile's alias (`dre_research_current`,
`dre_projects_current`, … — one per corpus, listed in
[docs/data-model.md](docs/data-model.md#the-corpora-at-a-glance)) to it
atomically, so live searches never hit a half-built index.

Run **Reindex all corpora** after deploying this release. Every union profile
needs its new source markers, Publications needs the full-text availability
fields, and Locations needs its coordinates. The Locations result toolbar then
offers an opt-in, lazy-loaded MapLibre map; list remains the default. Research
items offer list and image-forward gallery views, with the chosen view stored in
the shareable URL and as a local preference.

### Optional search analytics

To collect popular and zero-result queries, start Typesense with
`--enable-search-analytics=true` and a persistent `--analytics-dir`. Then choose
**Admin → DRE Search → Provision analytics**. The maintenance page shows the top
signals per corpus. Provisioning is deliberately non-fatal: reindex and public
search continue when analytics is disabled. The opt-in popular searches in the
empty search box list only queries an editor approved on that page; see
[docs/operations.md](docs/operations.md#synonyms-and-popular-searches).

## The page blocks

Edit a site page → add the **Research items search**, **Research projects
search**, **Publications search**, **Podcasts search**, **YouTube videos
search**, **People search**, **Research sections search**, **Organisations
search**, **Genres search**, **Languages search**, **Locations search**, or
**Subjects & tags search** block. All twelve are one block class, configured per
corpus, and share the same options:

- **Filters to show** — which facets appear in the sidebar, including whether the
  **Year** range slider shows.
- **Default sort** — the choices depend on the corpus: Relevance and Title
  always; **Newest / Oldest** only for corpora with a date; **Most research
  items** (count) for the authority-term corpora; **Episode number** for podcasts.
  (Date-less corpora — people, sections, organisations, and the four term corpora —
  no longer offer the meaningless Newest/Oldest.)
- **Results per page**.
- **Locked filter** — an optional Typesense `filter_by` saved with the block,
  e.g. pin items to one project (`project_s`) or projects to one research section
  (`section_ss`). The browser sends only the block ID; the server reloads the
  saved scope, so visitors cannot weaken or replace it.

Put each block on its own page and link them in the site navigation. Without
JavaScript a block, the Project records block and the results page show a link
to the site's item browse instead (the results page carries its query over).

Facet behaviour is the same across every block, with nothing to configure:

- **Facets are multi-select.** Values within one facet are OR'd (Type: Text _or_
  Image), separate facets are AND'd. Picking a value never empties its own list:
  each refined facet is recounted with its own selection lifted, so its remaining
  options stay visible with the counts you'd get by also ticking them. A selected
  value that no longer matches under the other filters stays listed at zero rather
  than vanishing — so a filter combination with no results still shows the way out.
- A facet with many values shows a **type-to-filter** box (scroll or type to find a
  value — no "show N more"). Typing searches the full scoped facet on the server,
  including values beyond the initial 100 shown.
- On narrow screens the whole filter sidebar collapses behind a **Filters** toggle
  (with an active-filter count) that opens and closes it.

## The Project records block (item pages)

A research project's item page can list the project's research records through
the research items search, instead of Omeka's plain **Linked resources** list:
the search box, the facets, sorting and paging all work, and the list stays
locked to that project. It is a **resource page block**, **Project records (DRE
Search)** (`dreSearchProjectRecords`), compatible with items, under a heading
"Records of this project" with the record count.

- It renders only on a project: an item of class `foaf:Project`, or one in the
  research items profile's `project` authority item set (20 on AMIRA). On any
  other item, on a project without research records, and while search is
  unavailable it renders nothing, so the page keeps its Linked resources.
- Its facets, default sort and page size are the Research items block's
  defaults, without the Project facet: the list is locked to one project.
- It sends only the project's item id (`scope_item`); the server rebuilds the
  `project_s` filter from the item the visitor can read, so the list cannot be
  widened. See [Scope](docs/public-api.md#scope).
- Its URL state is namespaced `r<item id>.` (for example `?r1797.q=interview`),
  clear of the item page's own parameters.
- Themes style it by its root class, `dre-search-project-records`.

**No admin work is needed.** On install and on the upgrade to 1.29.0, the
module adds the block to every site's saved Resource pages configuration that
lacks it, right before Linked resources (else after the last record block —
media, values, item sets, media list, viewer — else last). Nothing else is
moved or removed, a block already placed in any item region stays where it is,
and the migration runs once: to opt a site out, remove the block under its
Theme → **Configure resource pages**. A site that never saved that screen gets it through
the module's `resource_page_blocks_default`.

## Data model

The AMIRA data model — which resource template and item set each corpus
indexes, the Omeka property behind every facet and card field, the reverse
counts and role rules, and the IDs to verify before a first production reindex
on another instance — is in [docs/data-model.md](docs/data-model.md). All
instance-specific mapping lives in the `dre_search.profiles` block of
[`config/module.config.php`](config/module.config.php) and is overridable, key
by key, from Omeka's `config/local.config.php`
([profile configuration](docs/profile-configuration.md)).

## Documentation

- [Architecture](docs/architecture.md) and the [code map](docs/code-map.md)
- [Data model](docs/data-model.md): the AMIRA templates, item sets and properties
  behind each corpus
- [Operations and rebuild recovery](docs/operations.md)
- [Profile configuration](docs/profile-configuration.md)
- [Public API](docs/public-api.md)
- [Upgrading to 1.17](docs/upgrade-1.17.md)
- [Contributing](CONTRIBUTING.md): checks, budgets, versions and releases
- [Roadmap](ROADMAP.md) and [changelog](CHANGELOG.md)

## Development

```bash
npm ci
npm run build      # compile src/svelte → asset/dist (a build product, not tracked)
npm run check      # svelte-check (types)
npm run lint       # tokens, versions, profile schema, translations, eslint, prettier
npm test           # Vitest
npm run i18n       # regenerate language/template.pot after changing strings

composer install
composer lint      # PHP_CodeSniffer (PSR-12)
composer test      # PHPUnit (set OMEKA_VENDOR; MySQL/Typesense for integration)
composer analyse   # PHPStan level 8
```

`npm run lint` also runs `scripts/check-profile-schema.php`, which fails CI when
profile query/facet/search-only fields drift from the generated Typesense schema
or client card/i18n contracts. See [CONTRIBUTING.md](CONTRIBUTING.md) for the
integration-test services and the dev-container workflow, and
[docs/code-map.md](docs/code-map.md) for where everything lives.

### Deploying compiled search assets

Deploy the complete `asset/dist/` directory together with the PHP helpers. The
ESM entry loads page-specific JavaScript and CSS chunks on demand; copying only
`dre-search.js` and `dre-search.css` is insufficient. Keep the previous hashed
chunks available during a rolling deployment so already-open pages can finish
loading. Header-only pages load the small entry stylesheet and do not request
the faceted search chunks.

`asset/dist/manifest.json` is part of the build: the PHP helpers
(`View\BundleAssets`) read it to put `<link rel="preload" as="script">` hints for
the shared runtime chunk on every page, and for the search block's or federated
page's own chunk plus a stylesheet preload on those pages, so the browser
fetches them in parallel instead of one round trip after another. A missing
manifest only loses the hints. It is read on each request, so a new build needs
no PHP restart.

## Architecture

- **Multi-corpus by config.** A `ProfileRegistry` (built from
  `dre_search.profiles`) holds one `SearchProfile` per corpus. The schema
  builder, paged reindexer, query builder, search proxy, and page blocks are all
  parameterised by a profile; a profile's `kind` (`item` | `project` |
  `publication` | `podcast` | `video` | `person` | `section` | `organisation` |
  `term`)
  selects its indexer mapper and its result card.
- **Search is server-side.** The browser calls the module's own JSON endpoints
  (`/dre-search/api/search`, `/dre-search/api/suggest` per corpus;
  `/dre-search/api/suggest-all`, `/dre-search/api/search-all`,
  `/dre-search/api/union` federated) with a `profile`; PHP forwards to
  Typesense with a server-held search-only key and enforces `is_public:=true`.
  No key reaches the browser, so there are no browser-side scoped keys or nginx
  changes — and "optional" is trivial (no key → no search). The federated
  endpoints use Typesense `multi_search`; the All tab opts into the Typesense 30
  union mode for one merged ranking.
- **PHP**: `src/Search` (the proxy and its collaborators — query builder, facet
  recount, bulk pager, response normaliser, health — and the lazy client and
  search-key providers), `src/Indexer` (schema, authority resolver, the
  kind → mapper registry and its mappers, the Typesense collection operations,
  analytics sync, paged reindexer, change applier and shared reindex
  orchestrator), `src/Job` (background operations), `src/Site/BlockLayout` (one
  `SearchBlock` class for every corpus's block),
  `src/Site/ResourcePageBlockLayout` (the item-page Project records block),
  `src/Controller` (public proxy + federated results page + admin maintenance),
  `src/View` (`SurfaceBootstrap`, the bootstrap every surface hands the client,
  and the `dreSearchBar` / `dreFederatedSearch` / `dreSearchAssets` helpers),
  `src/Settings` (`SearchProfile`, `ProfileRegistry`, `SortOptions`,
  `BlockProfiles`).
- **JS**: `src/svelte` — Svelte 5, an ESM entry (header bar) that loads the
  per-corpus `App` and `FederatedApp` as page chunks on demand; the federated
  page reuses `App` per type-tab. What a surface fetches, and the search-box
  combobox, live in tested `.svelte.ts` modules under `lib/`. Styled with
  DRE-theme design tokens (so it inherits the theme's light/dark palette
  automatically).
- **Keyword search only** (no embedding model) to keep Typesense lean on a
  modest host. The reindexer is paged + batched, so memory stays flat.

## Citation

If you use DRE Search in research, cite it via the repository's
[`CITATION.cff`](CITATION.cff) — GitHub renders it under **Cite this
repository** and will export APA or BibTeX for you.

> Madore, F. (2026). _DRE Search: Typesense-backed faceted search for Omeka S_
> (Version 1.19.1) [Computer software].
> https://github.com/AM-Digital-Research-Environment/DRESearch

## Licence

DRE Search
Copyright (C) 2024-2026 Frédérick Madore and contributors

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version. See [LICENSE](LICENSE) for the full text.

SPDX-License-Identifier: `GPL-3.0-or-later`

[MongoDB2OmekaS]: https://github.com/AM-Digital-Research-Environment/MongoDB2OmekaS
