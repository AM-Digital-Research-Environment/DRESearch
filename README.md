# DRE Search

Typesense-backed faceted search for the Africa Multiple **DRE** Omeka S instance
(the one populated by [MongoDB2OmekaS]). It indexes content directly from the
Omeka dashboard via MySQL — there is no external ingestion pipeline.

Two search corpora ship out of the box, each as its own page block:

- **Research items search** — the digitised research items (resource template 10).
- **Research projects search** — the cluster's research projects (template 5).

Both are **search profiles**: independent Typesense collections + facet/index
mappings, all config-driven. Adding a third corpus (e.g. publications) is a
config block plus a mapper — not a rewrite.

**Typesense is optional.** With no connection configured the module installs
cleanly and the site runs normally; the search blocks just show a quiet
"search unavailable" notice. This keeps the module reusable on installs that
don't want a search backend.

## What you get

- A **Research items search** page block: full-text search with autocomplete,
  faceted filtering, sorting, pagination, and result cards (title, type,
  project, origin year, thumbnail).
  - Facets: a **Year** range slider, plus **Type, Project, Country, Language,
    Subject, Tag, Target audience, Digitisation method**.
- A **Research projects search** page block: cards show the project name, year
  range, research section(s), principal investigator(s), funding institution(s),
  and the number of associated research items.
  - Facets: a **Year** range slider, **Institution**, **Research section**, and
    **Has research items** (yes/no).
- A dashboard **Reindex** action per corpus (Admin → DRE Search) that rebuilds
  an index as a background job, reading the Omeka database and pushing to
  Typesense in batches.

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
`dre_projects_current`) to it atomically, so live searches never hit a half-built
index.

## The page blocks

Edit a site page → add the **Research items search** or **Research projects
search** block. Both share the same options:

- **Filters to show** — which facets appear in the sidebar, including whether the
  **Year** range slider shows.
- **Default sort** — Relevance / Newest / Oldest / Title.
- **Results per page**.
- **Locked filter** — an optional raw Typesense `filter_by` to scope the block,
  e.g. pin items to one project (`project_s`) or projects to one research section
  (`section_ss`).

Put each block on its own page and link both in the site navigation.

## Data model

All instance-specific mapping lives in the `dre_search.profiles` block of
[`config/module.config.php`](config/module.config.php) and is overridable, key by
key, via Omeka's `config/local.config.php` (no module source edit). Each profile
sets its Typesense collection alias, source template/item set, `query_by`, date
mode, and facets. The Typesense field names (`type_s`, `institution_ss`,
`year_start`, …) are the stable interface; the Typesense schema, the SQL term
list, and each block's facet picker all derive from the profile config.

### Research items (`research_items`) — resource template 10

| Facet               | Omeka property     | Authority set                                 |
| ------------------- | ------------------ | --------------------------------------------- |
| Type                | `dcterms:type`     | 1                                             |
| Project             | `dcterms:isPartOf` | 20                                            |
| Country             | `dcterms:spatial`  | 1851 (country direct, or via city `isPartOf`) |
| Language            | `dcterms:language` | 19                                            |
| Subject             | `dcterms:subject`  | 1852 (target type = `lcsh`)                   |
| Tag                 | `dcterms:subject`  | 1852 (target type = `tag`)                    |
| Target audience     | `dcterms:audience` | 3169                                          |
| Digitisation method | `dcterms:format`   | 7438 (genres in set 21 excluded)              |
| Year (range slider) | `dcterms:issued`   | single `year` (fallback `created` → `date`)   |

### Research projects (`research_projects`) — resource template 5, item set 20

| Field                      | Omeka property     | Notes                                                                         |
| -------------------------- | ------------------ | ----------------------------------------------------------------------------- |
| Institution (facet)        | `frapo:isFundedBy` | linked institution titles (set 110)                                           |
| Research section (facet)   | `dcterms:isPartOf` | linked research-section titles (set 17)                                       |
| Year (range slider)        | `dcterms:temporal` | `numeric:interval` → `year_start` / `year_end`                                |
| Has research items (facet) | — derived —        | from the count below                                                          |
| PI(s) (card)               | `dcterms:creator`  | linked person titles                                                          |
| Associated items (card)    | — derived —        | research items (template 10) linking back via `dcterms:isPartOf`, public only |

> **Before the first production reindex, verify these IDs against your
> instance** — the project IDs (template 5, item set 20, authority sets 17/110,
> `dcterms:type` target 3346) and the item authority sets / `dcterms:type`
> targets. They come from the MongoDB2OmekaS config; on a different Omeka
> instance, override the `dre_search` config in `config/local.config.php`.

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
  parameterised by a profile; a profile's `kind` (`item` | `project`) selects its
  indexer mapper and its result card.
- **Search is server-side.** The browser calls the module's own JSON endpoints
  (`/dre-search/api/search`, `/dre-search/api/suggest`) with a `profile`; PHP
  forwards to Typesense with the server-held key and enforces `is_public:=true`.
  The key never reaches the browser, so there are no scoped keys or nginx changes
  — and "optional" is trivial (no key → no search).
- **PHP**: `src/Search` (proxy, query builder, lazy client provider),
  `src/Indexer` (schema, authority resolver, item/project mappers, paged
  reindexer), `src/Job` (background reindex), `src/Site/BlockLayout` (the blocks),
  `src/Controller` (public proxy + admin maintenance), `src/Settings`
  (`SearchProfile`, `ProfileRegistry`).
- **JS**: `src/svelte` — Svelte 5, one IIFE bundle, styled with DRE-theme
  design tokens (so it inherits the theme's light/dark palette automatically).
- **Keyword search only** (no embedding model) to keep Typesense lean on a
  modest host. The reindexer is paged + batched, so memory stays flat.

## Licence

GPL-3.0-or-later.

[MongoDB2OmekaS]: https://github.com/AM-Digital-Research-Environment/MongoDB2OmekaS
