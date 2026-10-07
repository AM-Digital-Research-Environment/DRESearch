# Security policy

## Supported versions

Security fixes are provided for the latest minor release. Operators should run
the newest tagged release and keep Omeka S, PHP, Composer dependencies,
Typesense, and the web server patched.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub's private
vulnerability-reporting feature for this repository, or contact the University
of Bayreuth Africa Multiple DRE maintainers. Include the affected version,
reproduction steps, impact, and any suggested mitigation.

We will acknowledge a report, validate it, coordinate a fix and release, then
credit the reporter unless anonymity is requested.

## Security boundaries

- The Typesense API key remains server-side and is never included in bootstrap
  JSON or JavaScript.
- Only public records are indexed. The indexer reads public items only, and
  within them only public values, linked resources and media, so every
  document in Typesense is a public one. A record whose change is still queued
  (made private, deleted or moved out of scope) is left out of every response
  until the background worker applies the change. A corpus with more pending
  changes than `pending_exclusion_limit`, or one that needs a full rebuild,
  stops answering. Indexing only public records, plus this pending-change
  readiness gate, is what keeps private data out of search.
- Public queries also add `is_public:=true`. This is defense in depth, not the
  guarantee: every indexed document is public, so the filter matches them all.
  It would only matter if a private document ever reached the index.
- Page-block scopes are reloaded from persisted block data by ID; client raw
  filter expressions are rejected.
- Editor-authored block HTML is sanitized. Highlights render as text nodes.
- Search analytics are not authenticated. A search request with
  `record_query: true` is counted in the popular-query and no-hit analytics.
  Any client can send it as often as the rate limit allows, with a fresh
  `analytics_id` each time, so anyone can inflate those counts. Read them as
  hints, not measurements.
- Popular searches are moderated. Because the counts can be forged, the
  automatic checks (`min_count`, no no-hit queries, nothing shaped like personal
  data) only pick candidates. Visitors see a query only after an editor
  approves it on the maintenance page, so injected text never reaches them.
