# DRE Search

A Typesense-backed faceted search module for the Africa Multiple **DRE** Omeka S
instance (the one populated by [MongoDB2OmekaS]). It indexes **research items**
directly from the Omeka dashboard via MySQL — there is no external ingestion
pipeline.

**Typesense is optional.** With no connection configured the module installs
cleanly and the site runs normally; the search block just shows a quiet
"search unavailable" notice. This keeps the module reusable on installs that
don't want a search backend.

## What you get

- A **DRE Search** page block: full-text search with autocomplete, faceted
  filtering, sorting, pagination, and result cards (title, type, project,
  origin year, thumbnail).
- Facets: **Type, Project, Country, Language, Subject, Tag, Target audience,
  Digitisation method**.
- A dashboard **Reindex** action (Admin → DRE Search) that rebuilds the index
  as a background job, reading the Omeka database and pushing to Typesense in
  batches.

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

Admin → Modules → **DRE Search** → *Configure*: host, port, protocol, API key,
collection alias. Resolution order is **settings → environment variables →
config defaults**, so the admin form overrides everything.

## Building / refreshing the index

Admin → **DRE Search** → **Reindex now**. Progress is logged to Admin → Jobs.
Re-run after significant content changes (or wire the job to a schedule via the
Cron module). The reindexer builds a fresh, timestamped Typesense collection and
swaps the `dre_research_current` alias to it atomically, so live searches never
hit a half-built index.

## The page block

Edit a site page → add the **DRE Search** block. Options:

- **Filters to show** — which of the eight facets appear in the sidebar.
- **Default sort** — Relevance / Newest / Oldest / Title.
- **Results per page**.
- **Locked filter** — an optional raw Typesense `filter_by` to scope the block,
  e.g. `project_s:=\`Remoboko\`` to pin it to one project.

## Data model

Research items are resource template **10**. The eight facets are resolved from
linked authority items; the item-set IDs and `dcterms:type` discriminators live
in a single file: [`src/Settings/FacetConfig.php`](src/Settings/FacetConfig.php).

| Facet | Omeka property | Authority set |
|---|---|---|
| Type | `dcterms:type` | 1 |
| Project | `dcterms:isPartOf` | 20 |
| Country | `dcterms:spatial` | 1851 (country direct, or via city `isPartOf`) |
| Language | `dcterms:language` | 19 |
| Subject | `dcterms:subject` | 1852 (target type = `lcsh`) |
| Tag | `dcterms:subject` | 1852 (target type = `tag`) |
| Target audience | `dcterms:audience` | 3169 |
| Digitisation method | `dcterms:format` | 7438 (genres in set 21 excluded) |

> **Before the first production reindex, verify these IDs against your
> instance** (item-set IDs and the `dcterms:type` target items used to split
> subject/tag and country/city). Porting to a different Omeka instance is a
> matter of editing `FacetConfig.php`.

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

- **Search is server-side.** The browser calls the module's own JSON endpoints
  (`/dre-search/api/search`, `/dre-search/api/suggest`); PHP forwards to
  Typesense with the server-held key and enforces `is_public:=true`. The key
  never reaches the browser, so there are no scoped keys or nginx changes — and
  "optional" is trivial (no key → no search).
- **PHP**: `src/Search` (proxy, query builder, lazy client provider),
  `src/Indexer` (schema, authority resolver, mapper, paged reindexer),
  `src/Job` (background reindex), `src/Site/BlockLayout` (the block),
  `src/Controller` (public proxy + admin maintenance).
- **JS**: `src/svelte` — Svelte 5, one IIFE bundle, styled with DRE-theme
  design tokens (so it inherits the theme's light/dark palette automatically).
- **Keyword search only** (no embedding model) to keep Typesense lean on a
  modest host. The reindexer is paged + batched, so memory stays flat.

## Licence

GPL-3.0-or-later.

[MongoDB2OmekaS]: https://github.com/AM-Digital-Research-Environment/MongoDB2OmekaS
