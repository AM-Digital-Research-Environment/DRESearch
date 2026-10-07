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

A rebuild waits up to 60 seconds for a drain to release the profile lock, and up
to two minutes for a starting Typesense to finish loading. Promotion is refused
when the new generation holds less than `min_retained_ratio` (half) of the live
one, unless the operator allows a smaller corpus.

`GenerationPublisher` verifies the target after an ambiguous alias PUT. Cleanup
checks every alias before deleting an owned collection and preserves collections
when that check fails. The previous generation is retained for rollback; older, unaliased,
module-owned generations are deleted at the next promotion (or after
`retention_days`).

## Incremental lifecycle

Omeka item and media post events contain entities before representations are
built. The listener accepts both forms, captures dependencies before updates or
deletes, and refreshes the union of former and current relationships. Incoming
two-hop links cover authority hierarchies; media changes refresh their parents
and dependants. Item-set deletion and resource-template changes queue the
affected items. Omeka's batch operations fire the per-resource events for every
id, so batch events are not observed separately.

Work is queued only for the profiles whose source scope holds each item
(`ScopeMatcher`, the same `SourcePredicate` the indexer uses, one `UNION ALL`
query). Scope is captured before a destructive write as well as after it, so a
deleted, re-templated or descoped item still reaches the profile that indexed it.

`dre_search_change` deduplicates work by profile/item with random revision tokens.
Omeka writes only SQL work. A single coalesced worker drains it, coordinated by
one `dre_search_worker` lease row: a producer records the request and dispatches
a `DrainSearchChanges` job only if no live worker holds the lease; the worker
loops until no request arrived during its pass. Long CLI processes (imports)
wake it every 30 seconds rather than only at exit. A failed pass holds the lease
for one stale window (2 minutes) before another attempt, so an outage costs one
retry per window rather than one job per save. Workers import at most 100
documents per batch, remove out-of-scope/deleted/private records and acknowledge
only the revision they read. A document Typesense rejects is removed from the
live index (fail closed), acknowledged and listed on the admin page, so it cannot
hold the queue. Incremental batches resolve only the authorities they link to.

## Public availability

`ReadinessGate` decides per profile, in one SQL round trip per public call. Ids
with queued changes are excluded from every query (`id:!=[…]`), so a stale copy
— possibly showing metadata that was just made private — is never served while
the rest of the corpus keeps answering. Above `pending_exclusion_limit` (250) or
with a dirty marker (unknown impact, e.g. a failed dependency lookup) the profile
pauses; federated endpoints then leave that corpus out instead of failing. A
queue whose oldest row is older than two minutes wakes a worker through the same
lease, so a failed dispatch heals without operator action.

## Search execution and caching

`SearchExecutor` applies the same missing-stopword fallback to normal, facet,
count, map, export and union searches. Full-text snippets use explicit
`highlight_fields` even when full document bodies are excluded. Facet search is
a bounded server query that removes only its own user filter; public visibility,
saved block scope, text and other filters remain enforced.

All searches are sent as `multi_search` POST requests: the collection search
endpoint is a GET, and Typesense rejects query strings over 4,000 bytes, which a
long non-Latin query or an exclusion list exceeds. Queries recorded for analytics
keep the GET endpoint while they fit.

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
selects Map. It prefers a copy already on the page, then the one
DRE-Visualizations vendors same-origin (`window.RV_LIBS`), and only then
jsDelivr. MapLibre 6 is ES modules only, so each copy is imported; the CDN's
three modules are fetched with Subresource Integrity hashes and linked through
`blob:` URLs, because an `import()` cannot carry a hash itself. A host whose
Content Security Policy forbids `blob:` scripts should serve the vendored copy.

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
