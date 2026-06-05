# DRE Search

Typesense-backed faceted search for the Africa Multiple **DRE** Omeka S instance
(the one populated by [MongoDB2OmekaS]). It indexes content directly from the
Omeka dashboard via MySQL — there is no external ingestion pipeline.

Eleven search corpora ship out of the box, each as its own page block:

- **Research items search** — the digitised research items (resource template 10).
- **Research projects search** — the cluster's research projects (template 5).
- **Publications search** — the cluster bibliography (journal articles, books,
  chapters, theses, … — one item set across several templates).
- **Podcasts search** — the cluster's podcast episodes (template 21, item set
  39095), filterable by series, the people in each episode (hosts + guests), and
  language; sortable by episode number or date. Cards show the series logo as the
  thumbnail, the abstract, and a "Listen" link.
- **People search** — researchers and contributors (template 4), filterable by
  affiliation and role, with per-person research-item and publication counts.
- **Research sections search** — the cluster's thematic sections (template 7),
  filterable by phase and associated person, with per-section project and member
  counts.
- **Organisations search** — institutions _and_ groups (template 2, one item set),
  split by a Type facet and filterable by the role each plays, with per-organisation
  project, research-item, and affiliated-people counts.
- **Genres search** — the genre/form authority terms (item set 21), with a
  per-genre research-item count.
- **Languages search** — the language authority terms (item set 19), with
  per-language research-item and publication counts.
- **Locations search** — the place authority terms (item set 1851), split by a
  Type facet (Country / geographic location), with a per-place research-item count.
- **Subjects & tags search** — the subject authority terms (item set 1852), both
  LCSH headings and tags in one corpus split by a Type facet, with per-term
  research-item and publication counts.

All are **search profiles**: independent Typesense collections + facet/index
mappings, all config-driven. Adding a further corpus is a config block plus a
mapper — not a rewrite. The last four share the generic **`term`** kind (one
`TermMapper` + one `TermCard`): an authority item set whose substance is the
reverse count of the records that reference each term.

**Typesense is optional.** With no connection configured the module installs
cleanly and the site runs normally; the search blocks just show a quiet
"search unavailable" notice. This keeps the module reusable on installs that
don't want a search backend.

## What you get

- A **Research items search** page block: full-text search with autocomplete,
  faceted filtering, sorting, pagination, and result cards (title, type,
  project, origin year, **place of origin**, **current location**, thumbnail).
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
  book + series), volume/issue, pages, publisher, year, abstract, and a DOI link.
  - Facets: a **Year** range slider, **Type, Author, Journal / Book, Publisher,
    Keyword, Language**.
- A **Podcasts search** page block: cards show the podcast **series logo** as the
  thumbnail (every episode of a series shares it), the episode number and date, the
  title, a series chip, the hosts, guests and sound engineer (linked to their person
  pages), the language, the abstract, a **Transcript** badge when the episode is
  full-text searchable, and a **Listen** link to the audio / episode page.
  - Facets: **Series**, **People** (a union of hosts and guests), and **Language**.
  - Sort: **Episode number** (default — newest episode first) or **Newest / Oldest**
    by date, plus Relevance / Title.
  - Search covers the title, abstract, transcript, and the people in each episode.
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
  results **grouped by type**, one **tab per corpus** with its hit count.
  Selecting a tab reveals **that corpus's own facets, cards, sorting and
  paging** — it reuses the same per-corpus search UI as the blocks.

Two server endpoints back it (top-level, anonymous, `is_public:=true` enforced):
`/dre-search/api/suggest-all` (grouped autocomplete) and
`/dre-search/api/search-all` (per-corpus counts + the focused corpus's results).

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

## Requirements

- Omeka S `^4.2`
- A Typesense server (optional)
- Node 20+ — only to rebuild the Svelte bundle during development (the compiled
  bundle ships in `asset/dist/`, so production needs no Node toolchain).

## Install

1. Put the module at `modules/DRESearch`. With the AM `omeka-s-docker` stack it
   is already referenced in `_docker/default-modules.txt`
   (`gh:AM-Digital-Research-Environment/DRESearch`); otherwise use
   `bash scripts/install-module.sh` or copy it in manually.
2. Install PHP dependencies (the Typesense client):
   ```bash
   composer install --no-dev
   ```
3. Activate **DRE Search** in Admin → Modules.

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

Admin → Modules → **DRE Search** → _Configure_: host, port, protocol, API key.
Resolution order is **settings → environment variables → config defaults**, so
the admin form overrides everything. (Collection aliases are per-profile config,
not set here — see below.)

## Building / refreshing the index

Admin → **DRE Search** → **Reindex**: one button per corpus. Progress is logged
to Admin → Jobs. Re-run after significant content changes (or wire the job to a
schedule via the Cron module). Each reindex builds a fresh, timestamped Typesense
collection and swaps that profile's alias (`dre_research_current` /
`dre_projects_current` / `dre_publications_current` / `dre_podcasts_current` /
`dre_people_current` / `dre_sections_current` / `dre_organisations_current` /
`dre_genres_current` / `dre_languages_current` / `dre_locations_current` /
`dre_subjects_current`) to it atomically, so live searches never hit a half-built
index.

## The page blocks

Edit a site page → add the **Research items search**, **Research projects
search**, **Publications search**, **Podcasts search**, **People search**,
**Research sections search**, **Organisations search**, **Genres search**,
**Languages search**, **Locations search**, or **Subjects & tags search** block.
All share the same options:

- **Filters to show** — which facets appear in the sidebar, including whether the
  **Year** range slider shows.
- **Default sort** — the choices depend on the corpus: Relevance and Title
  always; **Newest / Oldest** only for corpora with a date; **Most research
  items** (count) for the authority-term corpora; **Episode number** for podcasts.
  (Date-less corpora — people, sections, organisations, and the four term corpora —
  no longer offer the meaningless Newest/Oldest.)
- **Results per page**.
- **Locked filter** — an optional raw Typesense `filter_by` to scope the block,
  e.g. pin items to one project (`project_s`) or projects to one research section
  (`section_ss`).

Put each block on its own page and link them in the site navigation.

Facet behaviour is the same across every block: a facet with many values shows a
**type-to-filter** box (scroll or type to find a value — no "show N more"), and on
narrow screens the whole filter sidebar collapses behind a **Filters** toggle
(with an active-filter count) that opens and closes it.

## Data model

All instance-specific mapping lives in the `dre_search.profiles` block of
[`config/module.config.php`](config/module.config.php) and is overridable, key by
key, via Omeka's `config/local.config.php` (no module source edit). Each profile
sets its Typesense collection alias, source template/item set, `query_by`, date
mode, and facets. The Typesense field names (`type_s`, `institution_ss`,
`year_start`, …) are the stable interface; the Typesense schema, the SQL term
list, and each block's facet picker all derive from the profile config.

### Research items (`research_items`) — resource template 10

| Facet               | Omeka property       | Authority set                                              |
| ------------------- | -------------------- | ---------------------------------------------------------- |
| Type                | `dcterms:type`       | 1                                                          |
| Project             | `dcterms:isPartOf`   | 20                                                         |
| Place of origin     | `dcterms:spatial`    | 1851 (the linked place verbatim — city / region / country) |
| Country             | `dcterms:spatial`    | 1851 (rolled up to country, direct or via city `isPartOf`) |
| Current location    | `dcterms:provenance` | 1851 places + 110 institutions (held-at; not rolled up)    |
| Language            | `dcterms:language`   | 19                                                         |
| Subject             | `dcterms:subject`    | 1852 (target type = `lcsh`)                                |
| Tag                 | `dcterms:subject`    | 1852 (target type = `tag`)                                 |
| Target audience     | `dcterms:audience`   | 3169                                                       |
| Digitisation method | `dcterms:format`     | 7438 (genres in set 21 excluded)                           |
| Year (range slider) | `dcterms:issued`     | single `year` (fallback `created` → `date`)                |

### Research projects (`research_projects`) — resource template 5, item set 20

| Field                      | Omeka property     | Notes                                                                            |
| -------------------------- | ------------------ | -------------------------------------------------------------------------------- |
| Institution (facet)        | `frapo:isFundedBy` | linked institution titles (set 110)                                              |
| Research section (facet)   | `dcterms:isPartOf` | linked research-section titles (set 17)                                          |
| Year (range slider)        | `dcterms:temporal` | `numeric:interval` → `year_start` / `year_end`                                   |
| Has research items (facet) | — derived —        | from the count below                                                             |
| PI(s) (card)               | `dcterms:creator`  | linked person titles; clickable → adds an Associated-people (`people_ss`) filter |
| Associated items (card)    | — derived —        | research items (template 10) linking back via `dcterms:isPartOf`, public only    |

### Publications (`research_publications`) — item set 29918

Publications span ~10 type-specific resource templates (article, book, chapter,
thesis, …) but share one item set, so this profile scopes by **`item_set_id`
(29918)** with **`template_id: null`** — the only profile with no template filter.

| Field                  | Omeka property                                          | Notes                                                        |
| ---------------------- | ------------------------------------------------------- | ------------------------------------------------------------ |
| Type (facet)           | `dcterms:type`                                          | linked publication-type title (set 30613)                    |
| Author (facet + card)  | `bibo:authorList`                                       | linked person titles (set 18); `author_ids` link the card    |
| Journal / Book (facet) | `dcterms:isPartOf`                                      | literal venue + series                                       |
| Publisher (facet)      | `dcterms:publisher`                                     | literal                                                      |
| Keyword (facet)        | `dcterms:subject`                                       | linked subject titles (set 1852), literal fallback           |
| Language (facet)       | `dcterms:language`                                      | linked language (set 19), literal fallback                   |
| Year (range slider)    | `dcterms:date`                                          | `numeric:timestamp` → single `year`                          |
| Reference bits (card)  | `bibo:editorList`, `bibo:volume`, `bibo:issue`, pages\* | editors, volume/issue, recombined page string                |
| DOI (card)             | `bibo:doi`                                              | the URI value's `@id` (full `https://doi.org/…` link)        |
| Abstract (card)        | `bibo:abstract`                                         | publications use `bibo:abstract`, **not** `dcterms:abstract` |

\* Pages come from `bibo:pages` / `bibo:pageStart` / `bibo:pageEnd` /
`bibo:numPages` (the pipeline splits them by publication kind) and are recombined
into one display string — `141–165`, a lone start, or `121 pp.`.

### Podcasts (`research_podcasts`) — resource template 21, item set 39095

The cluster's podcast episodes, **hand-curated in Omeka** (not from the
MongoDB2OmekaS pipeline), so they get a dedicated corpus rather than being folded
into publications — their links differ (`marcrel:hst`/`spk` contributors,
`dcterms:abstract`, a series link, an episode number, a transcript).

| Field                 | Omeka property     | Notes                                                                                                                                                                                                                                                                      |
| --------------------- | ------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Series (facet)        | `dcterms:isPartOf` | linked series title (single-valued); `series_id` links the chip                                                                                                                                                                                                            |
| People (facet)        | —                  | derived union of hosts + guests (`people_ss`)                                                                                                                                                                                                                              |
| Language (facet)      | `dcterms:language` | linked language (set 19), literal fallback                                                                                                                                                                                                                                 |
| Host (card)           | `marcrel:hst`      | linked person titles (set 18); `host_ids` link the card                                                                                                                                                                                                                    |
| Guest (card)          | `marcrel:spk`      | linked person titles (set 18); `guest_ids` link the card                                                                                                                                                                                                                   |
| Sound engineer (card) | `marcrel:sde`      | optional credit line; `engineer_ids` link the card (display-only)                                                                                                                                                                                                          |
| Episode number (sort) | `bibo:number`      | `numeric:integer` → sortable `episode` (the default sort)                                                                                                                                                                                                                  |
| Date (sort + card)    | `dcterms:date`     | `date_s` shown verbatim; the 4-digit `year` drives Newest/Oldest                                                                                                                                                                                                           |
| Listen link (card)    | `fabio:hasURL`     | the URI value's `@id` — opens the audio / episode page                                                                                                                                                                                                                     |
| Abstract (card)       | `dcterms:abstract` | podcasts use `dcterms:abstract` (unlike publications' `bibo:abstract`)                                                                                                                                                                                                     |
| Transcript (search)   | `bibo:content`     | full-text search payload (often empty today); `search_only` — indexed for query_by but excluded from result payloads, so a long transcript never bloats a hit (matches still surface as a highlighted snippet). `has_transcript` drives a **Transcript** badge on the card |
| Thumbnail (card)      | `dcterms:isPartOf` | the **series item's** logo, via `thumbnail_property` (see below)                                                                                                                                                                                                           |

The episode's own media is the audio file, so the card thumbnail is resolved by
**hopping** `dcterms:isPartOf` to the series item and using _its_ first thumbnailed
media (`thumbnail_property` → `Reindexer::loadThumbnailsVia()`). Sorts: **Episode
number** (default) is a config-defined numeric sort (`sort_fields` — the generic
form of the term corpora's count sort) over the sortable `episode` field; the date
sorts use `year`. There is **no year slider** (the corpus is small and ordered by
episode). The People facet unions hosts and guests, while the card keeps them
separate so each is labelled by role.

> Data note: the template carries Host, Sound engineer, Language and Transcript,
> but most episodes today fill only guest, series, episode number, date and
> abstract — the facets and fields are built for the whole template and fill in as
> the corpus grows.

### People (`research_people`) — resource template 4, item set 18

A person record carries only a name and affiliation; the roles and counts come
from the **reverse** direction — the records that point _at_ the person. The
reindexer computes these per person from the profile's `reverse_links` config
(one grouped query per count bucket / role rule per page). This corpus has **no
date** — it sorts by name.

| Field                 | Source                                                            | Notes                                                 |
| --------------------- | ----------------------------------------------------------------- | ----------------------------------------------------- |
| Affiliation (facet)   | `dcterms:isPartOf` on the person                                  | linked Institution titles (set 110), literal fallback |
| Role (facet)          | — derived (reverse) —                                             | see the role rules below                              |
| Research items (card) | research items (template 10) referencing the person, public only  | `item_count`                                          |
| Publications (card)   | publications (item set 29918) referencing the person, public only | `publication_count`                                   |

**Role rules** (a person earns a role if a public record references them so):

| Role                      | Source corpus           | Property                                         |
| ------------------------- | ----------------------- | ------------------------------------------------ |
| Principal investigator    | project (template 5)    | `dcterms:creator`                                |
| Project member            | project (template 5)    | `foaf:member`                                    |
| Author                    | publication (set 29918) | `bibo:authorList`                                |
| Editor                    | publication (set 29918) | `bibo:editorList`                                |
| _the specific MARC roles_ | research item (tmpl 10) | each `marcrel:*` relator in use (`per_property`) |

The last rule uses `per_property`: rather than collapsing every research-item
credit into a single "Research contributor" value, it emits **one role per
`marcrel:*` relator** the person actually holds (Author, Photographer,
Interviewee, Translator, Collector, …), labelling each from that property's
template-10 alternate label. Only relators present in the data appear, so the
facet lists exactly the roles in use. Labels that coincide with a publication
role (Author, Editor) merge into one facet value.

### Research sections (`research_sections`) — resource template 7, item set 17

The cluster's 13 thematic sections, including the synthetic **External** section
(item 25135 — it exists in the data, so it appears as a card with its project
count). **Phase is not stored** — it's derived from which leadership property the
section carries. No date — sorts by name.

| Field                     | Source                    | Notes                                                                       |
| ------------------------- | ------------------------- | --------------------------------------------------------------------------- |
| Phase (facet)             | — derived —               | PIs present → **Phase 1**; spokesperson → **Phase 2**; External has neither |
| Associated person (facet) | — derived —               | union of PIs + spokesperson + members                                       |
| PIs (card)                | `dcterms:creator`         | linked person titles (Phase 1 sections)                                     |
| Spokesperson (card)       | `marcrel:spk`             | linked person title (Phase 2 sections)                                      |
| Members (card)            | `foaf:member`             | counted → `member_count`                                                    |
| Projects (card)           | — derived (`item_link`) — | public projects (template 5) linking via `dcterms:isPartOf`                 |
| Abstract (card)           | `dcterms:abstract`        |                                                                             |

### Organisations (`research_organisations`) — resource template 2, item set 110

One corpus for **both** institutions and groups: the pipeline stores them as the
same Organisation item (template 2, item set 110) and tells them apart with
`dcterms:type` (→ "Institution" / "Group"), which becomes the **Type** facet. Like
people, an organisation's substance is in the **reverse** direction, so the same
`reverse_links` machinery counts the records pointing _at_ it and derives the roles
it plays. No date — sorts by name.

| Field                 | Source                                                          | Notes                                            |
| --------------------- | --------------------------------------------------------------- | ------------------------------------------------ |
| Type (facet)          | `dcterms:type` on the organisation                              | linked type item title — "Institution" / "Group" |
| Role (facet)          | — derived (reverse) —                                           | see the role rules below                         |
| Projects (card)       | projects (template 5) funding it via `frapo:isFundedBy`, public | `project_count`                                  |
| Research items (card) | research items (template 10) crediting it (any role), public    | `item_count`                                     |
| People (card)         | people (template 4) affiliated via `dcterms:isPartOf`, public   | `people_count`                                   |

**Role rules** (an organisation earns a role if a public record references it so):

| Role             | Source corpus           | Property           |
| ---------------- | ----------------------- | ------------------ |
| Funder           | project (template 5)    | `frapo:isFundedBy` |
| Contributor      | research item (tmpl 10) | any reference      |
| Host institution | person (template 4)     | `dcterms:isPartOf` |

### Authority terms — genres, languages, locations, subjects & tags (`term` kind)

Four corpora that index a curated authority item set each. Like people and
organisations, a term record carries only a name (and, for some, a `dcterms:type`
sub-kind); its substance is the **reverse** count of the public records that
reference it, computed by the reindexer from `reverse_links`. They are scoped by
**item set alone** (`template_id: null`), have **no date**, default to the **Most
research items** sort (count desc, name tie-break), and use the same linking
properties the working research-items / publications facets use.

| Corpus              | Profile              | Item set       | Facets             | Reverse counts                                                            |
| ------------------- | -------------------- | -------------- | ------------------ | ------------------------------------------------------------------------- |
| **Genres**          | `research_genres`    | 21             | —                  | research items (tmpl 10) via `dcterms:format`                             |
| **Languages**       | `research_languages` | 19             | —                  | research items via `dcterms:language` + publications (set 29918) via same |
| **Locations**       | `research_locations` | 1851 + tmpl 2† | Type, Relationship | research items via `dcterms:spatial` **and** `dcterms:provenance`         |
| **Subjects & tags** | `research_subjects`  | 1852           | Type               | research items via `dcterms:subject` + publications (set 29918) via same  |

Notes:

- The **Type** facet is the term's _own_ `dcterms:type` linked-item title — the same
  discriminator items the research-items mapper uses to split Subject vs Tag
  (`3167` / `22199`) and Country vs geographic location (`3168` / `22431`).
- The Locations **Relationship** facet is derived (reverse) from _how_ a record
  references the place: **Place of origin** (`dcterms:spatial`, from
  `location.origin`) vs **Current location** (`dcterms:provenance`, from
  `location.current`). Its `item_count` counts research items referencing the place
  either way (deduped).
- **†** `dcterms:provenance` also targets repository **institutions**. The
  geocoded ones (resource template **2** in set 110 that carry `geo:lat`/`geo:long`
  — the repositories holding research items) are **folded into this corpus** via the
  profile's `extra_sources`, so they appear alongside places as a new **Type
  "Institution"** (their own `dcterms:type`) with a **Current location**
  relationship and a held-items count. `extra_sources` is the generic multi-source
  mechanism (an OR-group in the reindex source query); other corpora omit it. The
  inclusion filter is "has coordinates", so un-geocoded institutions stay out.
- The Locations corpus indexes every place term directly and **does not** roll
  cities up to their country (unlike the research-items _Country_ facet), so both
  "Nigeria" and "Lagos" appear with their own direct-mention counts.
- All counts are **public records only**; the term itself appears only if it is
  public.

> **Before the first production reindex, verify these IDs against your
> instance** — the project IDs (template 5, item set 20, authority sets 17/110,
> `dcterms:type` target 3346), the publication item set (29918) and type set
> (30613), the people template (4) / item set (18) and their reverse person-link
> properties (`dcterms:creator`, `foaf:member`, `bibo:authorList`,
> `bibo:editorList`), the research-sections template (7) / item set (17) and their
> leadership properties (`dcterms:creator` = PIs, `marcrel:spk` = spokesperson,
> `foaf:member` = members), the organisations template (2) / item set (110) and
> their reverse-link properties (`frapo:isFundedBy`, `dcterms:isPartOf`) plus the
> `dcterms:type` targets that split Institution vs Group, the authority-term item
> sets (genres 21, languages 19, locations 1851, subjects 1852) and their linking
> properties (`dcterms:format`, `dcterms:language`, `dcterms:spatial` = place of
> origin + `dcterms:provenance` = current location, `dcterms:subject`), and the
> item authority sets / `dcterms:type` targets. They
> come from the MongoDB2OmekaS config; on a different Omeka instance, override the
> `dre_search` config in `config/local.config.php`.

## Development

```bash
npm install
npm run build      # compile src/svelte → asset/dist (commit the result)
npm run check      # svelte-check (types)
npm run lint       # eslint + prettier
npm run lint:fix   # auto-fix
```

Run `check` + `lint` + `build` before committing anything under `src/svelte/`.
Lint the PHP in your runtime image (`php -l`) since this repo ships no PHP
toolchain.

## Architecture

- **Multi-corpus by config.** A `ProfileRegistry` (built from
  `dre_search.profiles`) holds one `SearchProfile` per corpus. The schema
  builder, paged reindexer, query builder, search proxy, and page blocks are all
  parameterised by a profile; a profile's `kind` (`item` | `project` |
  `publication` | `podcast` | `person` | `section` | `organisation` | `term`)
  selects its indexer mapper and its result card.
- **Search is server-side.** The browser calls the module's own JSON endpoints
  (`/dre-search/api/search`, `/dre-search/api/suggest` per corpus;
  `/dre-search/api/suggest-all`, `/dre-search/api/search-all` federated) with a
  `profile`; PHP forwards to Typesense with the server-held key and enforces
  `is_public:=true`. The key never reaches the browser, so there are no scoped
  keys or nginx changes — and "optional" is trivial (no key → no search). The
  federated endpoints use one Typesense `multi_search` across all collections.
- **PHP**: `src/Search` (proxy, query builder, lazy client provider),
  `src/Indexer` (schema, authority resolver, item/project mappers, paged
  reindexer), `src/Job` (background reindex), `src/Site/BlockLayout` (the blocks),
  `src/Controller` (public proxy + federated results page + admin maintenance),
  `src/View/Helper` (the `dreSearchBar` / `dreFederatedSearch` / `dreSearchAssets`
  surfaces), `src/Settings` (`SearchProfile`, `ProfileRegistry`, `SortOptions`).
- **JS**: `src/svelte` — Svelte 5, one IIFE bundle that auto-mounts three
  surfaces (per-corpus `App`, header `SearchBar`, `FederatedApp`); the federated
  page reuses `App` per type-tab. Styled with DRE-theme design tokens (so it
  inherits the theme's light/dark palette automatically).
- **Keyword search only** (no embedding model) to keep Typesense lean on a
  modest host. The reindexer is paged + batched, so memory stays flat.

## Licence

GPL-3.0-or-later.

[MongoDB2OmekaS]: https://github.com/AM-Digital-Research-Environment/MongoDB2OmekaS
