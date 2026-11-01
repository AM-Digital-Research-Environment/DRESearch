# Data model

How DRE Search reads the AMIRA Omeka S instance — the one populated by
[MongoDB2OmekaS](https://github.com/AM-Digital-Research-Environment/MongoDB2OmekaS)
— into its twelve corpora: which resource template and item set each one
indexes, which Omeka property feeds each facet and card field, and how the
derived (reverse) counts and roles are computed. How to override a profile, or
add a corpus, is in [profile configuration](profile-configuration.md).

All instance-specific mapping lives in the `dre_search.profiles` block of
[`config/module.config.php`](../config/module.config.php) and is overridable, key by
key, via Omeka's `config/local.config.php` (no module source edit). Each profile
sets its Typesense collection alias, source template/item set, `query_by`, date
mode, and facets. The Typesense field names (`type_s`, `institution_ss`,
`year_start`, …) are the stable interface; the Typesense schema, the SQL term
list, and each block's facet picker all derive from the profile config.

Every facet and card label is read the same way: a linked resource's title,
else the literal value, trimmed, with duplicates dropped in source order. A
value that is only whitespace is no label (an index built before this rule may
still hold such values until its corpus is reindexed).

## The corpora at a glance

Each corpus is one profile: its own Typesense collection, published under a
stable alias that every reindex swaps atomically to a fresh, timestamped
collection.

| Corpus            | Profile                  | Kind           | Source scope                                                             | Alias                       |
| ----------------- | ------------------------ | -------------- | ------------------------------------------------------------------------ | --------------------------- |
| Research items    | `research_items`         | `item`         | resource template 10 (any item set)                                      | `dre_research_current`      |
| Research projects | `research_projects`      | `project`      | template 5, item set 20                                                  | `dre_projects_current`      |
| Publications      | `research_publications`  | `publication`  | item set 29918 (any template)                                            | `dre_publications_current`  |
| Podcasts          | `research_podcasts`      | `podcast`      | template 21, item set 39095                                              | `dre_podcasts_current`      |
| YouTube videos    | `research_videos`        | `video`        | template 22, item set 39192                                              | `dre_videos_current`        |
| People            | `research_people`        | `person`       | template 4, item set 18                                                  | `dre_people_current`        |
| Research sections | `research_sections`      | `section`      | template 7, item set 17                                                  | `dre_sections_current`      |
| Organisations     | `research_organisations` | `organisation` | template 2, item set 110 (institutions and groups)                       | `dre_organisations_current` |
| Genres            | `research_genres`        | `term`         | item set 21                                                              | `dre_genres_current`        |
| Languages         | `research_languages`     | `term`         | item set 19                                                              | `dre_languages_current`     |
| Locations         | `research_locations`     | `term`         | item set 1851, plus the geocoded institutions (template 2, item set 110) | `dre_locations_current`     |
| Subjects & tags   | `research_subjects`      | `term`         | item set 1852                                                            | `dre_subjects_current`      |

A profile's `kind` selects its indexer mapper and its result card; the four
authority-term corpora share the generic `term` kind.

## Research items (`research_items`) — resource template 10

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

Search covers the title, abstract, description, subjects, tags and creators.
Optionally, last, a **private** `bibo:content` of public records in the item
sets named by `restricted_fulltext` (none by default; ILAM, set 27724, holds
Mistral OCR text under copyright): it is searched but only ever quoted as one
short excerpt
([restricted full text](profile-configuration.md#restricted-full-text)).

## Research projects (`research_projects`) — resource template 5, item set 20

| Field                      | Omeka property     | Notes                                                                            |
| -------------------------- | ------------------ | -------------------------------------------------------------------------------- |
| Institution (facet)        | `frapo:isFundedBy` | linked institution titles (set 110)                                              |
| Research section (facet)   | `dcterms:isPartOf` | linked research-section titles (set 17)                                          |
| Year (range slider)        | `dcterms:temporal` | `numeric:interval` → `year_start` / `year_end`                                   |
| Has research items (facet) | — derived —        | from the count below                                                             |
| PI(s) (card)               | `dcterms:creator`  | linked person titles; clickable → adds an Associated-people (`people_ss`) filter |
| Associated items (card)    | — derived —        | research items (template 10) linking back via `dcterms:isPartOf`, public only    |

## Publications (`research_publications`) — item set 29918

Publications span 19 type-specific resource templates (article, book, chapter,
thesis, preprint, …) on a **non-contiguous** id range (11–20, 24–32) that grows
whenever a new upstream EP3 type appears. They share one item set, so this
profile scopes by **`item_set_id` (29918)** with **`template_id: null`** — the
only profile with no template filter, and the reason new publication types need
no change here.

| Field                     | Omeka property                                          | Notes                                                        |
| ------------------------- | ------------------------------------------------------- | ------------------------------------------------------------ |
| Type (facet)              | `dcterms:type`                                          | linked publication-type title (set 30613)                    |
| Author (facet + card)     | `bibo:authorList`                                       | linked person titles (set 18); `author_ids` link the card    |
| Journal / Book (facet)    | `dcterms:isPartOf`                                      | literal venue + series                                       |
| Publisher (facet)         | `dcterms:publisher`                                     | literal                                                      |
| Keyword (facet)           | `dcterms:subject`                                       | linked subject titles (set 1852), literal fallback           |
| Language (facet)          | `dcterms:language`                                      | linked language (set 19), literal fallback                   |
| Year (range slider)       | `dcterms:date`                                          | `numeric:timestamp` → single `year`                          |
| Reference bits (card)     | `bibo:editorList`, `bibo:volume`, `bibo:issue`, pages\* | editors, volume/issue, recombined page string                |
| DOI (card)                | `bibo:doi`                                              | the URI value's `@id` (full `https://doi.org/…` link)        |
| Full text (toggle + card) | `bibo:uri`                                              | the EPub Bayreuth permalink, via `fulltext_source` (below)   |
| Abstract (card)           | `bibo:abstract`                                         | publications use `bibo:abstract`, **not** `dcterms:abstract` |

\* Pages come from `bibo:pages` / `bibo:pageStart` / `bibo:pageEnd` /
`bibo:numPages` (the pipeline splits them by publication kind) and are recombined
into one display string — `141–165`, a lone start, or `121 pp.`.

**Full text available** means EPub Bayreuth, the cluster's open-access
repository, holds the record; ERef is metadata-only. The profile's
`fulltext_source` (`property` + `url_prefix`) names where to look: the first
`bibo:uri` under `https://epub.uni-bayreuth.de/id/eprint/` sets `has_fulltext`
(the toggle) and becomes `fulltext_url_s`, the card's **Full text** link. Text
extracted from the PDF into `bibo:content` is searched either way, but does not
decide availability. A profile without `fulltext_source` flags any record that
has extracted text.

## Podcasts (`research_podcasts`) — resource template 21, item set 39095

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
media (`thumbnail_property` → `OmekaSourceRepository::loadThumbnailsVia()`). Sorts: **Episode
number** (default) is a config-defined numeric sort (`sort_fields` — the generic
form of the term corpora's count sort) over the sortable `episode` field; the date
sorts use `year`. There is **no year slider** (the corpus is small and ordered by
episode). The People facet unions hosts and guests, while the card keeps them
separate so each is labelled by role.

> Data note: the template carries Host, Sound engineer, Language and Transcript,
> but most episodes today fill only guest, series, episode number, date and
> abstract — the facets and fields are built for the whole template and fill in as
> the corpus grows.

## YouTube videos (`research_videos`) — resource template 22, item set 39192

The cluster's recorded talks, interviews, panels and screenings published on
YouTube. A video's own first media is its poster-frame thumbnail, so the card
uses the item's own thumbnail, with no `thumbnail_property` hop (unlike
podcasts, whose own media is the audio file).

| Field               | Omeka property     | Notes                                                                                                  |
| ------------------- | ------------------ | ------------------------------------------------------------------------------------------------------ |
| Playlist (facet)    | `dcterms:isPartOf` | linked playlist title (single-valued); `playlist_id` links the chip                                    |
| Speaker (facet)     | `marcrel:spk`      | linked person titles, also searched (`query_by`); `speaker_ids` link the card to each person           |
| Language (facet)    | `dcterms:language` | linked language title                                                                                  |
| Date (card)         | `dcterms:date`     | `date_s` shown verbatim                                                                                |
| Year (range slider) | `dcterms:date`     | the 4-digit `year` from it; drives Newest / Oldest (newest first by default)                           |
| Watch link (card)   | `fabio:hasURL`     | `url_s`, the external YouTube link                                                                     |
| Abstract (card)     | `dcterms:abstract` | read through `read_properties`                                                                         |
| Transcript (search) | `bibo:content`     | full-text search payload, `search_only` (never shipped to the card); `has_transcript` drives the badge |

## People (`research_people`) — resource template 4, item set 18

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

## Research sections (`research_sections`) — resource template 7, item set 17

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

## Organisations (`research_organisations`) — resource template 2, item set 110

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

## Authority terms — genres, languages, locations, subjects & tags (`term` kind)

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

## Collection count contract

The optional CorpusCounts service uses the same source predicate as indexing,
including configured extra sources. It counts public items once and, when a site
id is supplied, restricts counts to that public site's assigned items. The theme
and visualization module consume these counts when available; installations
without the service retain their existing fallback definitions. Search indexes,
cached theme counts, and generated snapshots have different refresh times, so
matching membership rules do not guarantee simultaneous totals. Multi-site public
counts intentionally differ from installation-wide Search totals.
