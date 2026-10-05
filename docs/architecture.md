# Architecture

DRE Search is an Omeka S module with three boundaries:

1. The indexer reads Omeka records from MySQL, maps them into profile-specific
   Typesense documents, and publishes only a verified complete generation.
2. The PHP proxy validates anonymous requests, injects visibility and saved
   block scope, calls Typesense with the server-held key, and normalizes output.
3. The compiled Svelte client consumes only the module API. It never talks to
   Typesense directly.

Each corpus is a typed profile. Its source scope, collection alias, facets,
fields, date model, and sorts form the contract shared by schema generation,
mapping, validation, and UI bootstrap.

## Indexing and visibility

`OmekaSourceRepository` reads public resources, public values, public link targets,
and public media. It resolves property IDs before loading values. Cached Omeka
resource titles can originate from private values, so title reads choose the
first public value of the template title property (or dcterms:title).
`MapperFactory` and `DocumentAssembler` share mapping and batch loading between
full rebuilds and incremental work. Authority lookups are reused within a batch.

## Rebuild lifecycle

`building → verifying → live` is the successful path. MySQL advisory locks
serialize rebuilds and queue workers per collection alias. Every run owns a
unique staging collection recorded in `dre_search_generation`. Rejected imports
or a count mismatch prevent promotion. The queue is replayed into staging before
cutover, without acknowledging work; the new live generation then drains it.
Changes arriving during either pass remain queued until their exact revision is
successfully applied. A dirty marker raised after the build started is retained.

`GenerationPublisher` verifies the target after an ambiguous alias PUT. Cleanup
checks every alias before deleting an owned collection and preserves collections
when that check fails. The previous generation is retained for rollback; only
older, unaliased, module-owned generations are eligible for retention cleanup.

## Incremental lifecycle

Omeka item and media post events contain entities before representations are
built. The listener accepts both forms, captures dependencies before updates or
deletes, and refreshes the union of former and current relationships. Incoming
two-hop links cover authority hierarchies; media changes refresh their parents
and dependants. Batch and item-set operations use the same queue.

`dre_search_change` deduplicates work by profile/item with random revision tokens.
Omeka writes only SQL work; one job is dispatched when the request or import
process shuts down. Workers import at most 100 documents per batch, remove
out-of-scope/deleted/private records and acknowledge only the revision they read.
Failures leave work available to retry. Interrupted jobs do not erase changes.

The public proxy temporarily returns unavailable for profiles with pending work
or a dirty marker. This prevents stale private metadata being served while a
worker is delayed or Typesense is unavailable. Queue recovery is explicit in the
maintenance UI; no external scheduler is installed by the module.

## Search execution and caching

`SearchExecutor` applies the same missing-stopword fallback to normal, facet,
count, map, export and union searches. Full-text snippets use explicit
`highlight_fields` even when full document bodies are excluded. Facet search is
a bounded server query that removes only its own user filter; public visibility,
saved block scope, text and other filters remain enforced.

Count and year caches live in MySQL across PHP workers. Keys include connection
identity and profile configuration. Epoch changes on enqueue, replay and
promotion invalidate existing entries and prevent late fills from reviving stale
results. Cache failures degrade to uncached queries.

## Federated, map, and analytics extensions

The server-side proxy also owns two bounded read models. Typesense 30 union
search merges a curated set of collection aliases into the federated All tab;
source markers stored on each document select the safe client card and support
handoff to its corpus. Location maps page through at most 1,000 matching
documents carrying a validated `geopoint`; MapLibre loads only after the user
selects Map.

`ReindexOrchestrator` is the single construction path for one/all rebuild jobs:
it provisions stopwords, rebuilds profiles, and then attempts optional per-profile
analytics rules. Analytics destination collections are persistent and outside
the versioned alias-swap lifecycle.

Only deliberate main-query changes enable Typesense analytics. Internal counts,
facet requests, map pulls, exports and suggestions explicitly disable capture.
An ephemeral browser-page identifier separates proxy users without sending IPs,
account IDs or persistent cookies. Merged searches are not attributed to an
individual corpus's analytics rule.

## Typesense API references

The implementation and integration tests target Typesense 30.2. The October 2026
review checked the official [search API](https://typesense.org/docs/30.2/api/search.html)
for explicit excluded-field highlights, `enable_highlight_v1`, `facet_query` and
`enable_analytics`; the [analytics documentation](https://typesense.org/docs/30.2/api/analytics-query-suggestions.html)
for anonymous user separation through `x-typesense-user-id`; and
[collection aliases](https://typesense.org/docs/30.2/api/collections.html#using-an-alias)
for generation publication. Transport deadlines are configured on the injected
Guzzle client, rather than relying on unsupported SDK timeout options.
