# Public API

All endpoints return JSON and `X-Request-ID`. Search, facet, export, map, union
and federated search accept POST `application/json`; suggestions accept bounded
GET or POST parameters.

- `POST /dre-search/api/search`: one profile's hits and facets.
- `POST /dre-search/api/facet`: values of one sidebar facet matching
  `facet_query`, under the current query and the other filters.
- `POST /dre-search/api/export`: complete citation fields up to 1,000 hits.
- `POST /dre-search/api/map`: up to 1,000 geocoded hits of a location profile.
- `GET|POST /dre-search/api/suggest`: one profile's title suggestions.
- `GET|POST /dre-search/api/suggest-all`: grouped suggestions across profiles.
- `GET /dre-search/api/popular?profile=…`: up to `popular_searches.limit`
  popular queries for one profile (`{"queries": [...]}`). It lists only
  queries an editor has approved (see [Search analytics](#search-analytics)),
  and is always empty unless `popular_searches.enabled`. Cacheable for five
  minutes.
- `POST /dre-search/api/search-all`: active results and optionally cached
  per-corpus counts.
- `POST /dre-search/api/union`: one merged result stream across the configured
  `federated.union_profiles`.

Requests are strict: unknown keys, profiles/fields, unsupported sorts, oversized
bodies/queries, invalid UTF-8 and out-of-range paging are rejected. Filter
values are matched literally, so a value Typesense would reinterpret (a trailing
`*` or `\`, or one wrapped in double quotes) is refused. Clients may send a saved
`block_id`; the server resolves its locked filter and checks that its layout
matches the profile. Raw `locked_filter` input is not accepted.

## Availability

Records whose changes are still queued are left out of every response until
the background worker applies them. A corpus with more queued changes than
`pending_exclusion_limit`, or awaiting a full rebuild, answers `503` with
`backend_unavailable`; federated endpoints leave such a corpus out instead
(`union` then reports `"partial": true`).

A facet value may carry `"count": null`: it is selected, but lies outside the
top values the server recounted, so its number is unknown (never zero).

## Search analytics

A `search` request with `"record_query": true` and a 32-hex `analytics_id` is
counted in the corpus's popular-query and no-hit analytics. The search client
sets it once per new query, with one random id per page load. Neither field is
authenticated. Any client can set the flag on every request, with a fresh
`analytics_id` each time, so anyone can inflate the counts within the rate
limit. A query that matches only one of its words still finds something, so it
is not kept out as a no-hit query either. The counts are hints, not
measurements.

That is why `popular` is moderated. The automatic checks (run at least
`min_count` times, found something, nothing shaped like personal data) only
make a query a candidate. It reaches visitors once an editor approves it on the
maintenance page. Revoking or hiding it takes effect on the server's next
request, though browsers and proxies may keep a copy for up to five minutes.

## Errors

```json
{
  "available": false,
  "error": {
    "code": "invalid_filter",
    "message": "A filter field is not available for this profile.",
    "request_id": "…"
  }
}
```

| Status | Meaning                                                                              |
| ------ | ------------------------------------------------------------------------------------ |
| 400    | Invalid request (the `code` names the parameter)                                     |
| 405    | Wrong HTTP method                                                                    |
| 413    | Body over 64 KiB                                                                     |
| 429    | Rate limited; `Retry-After` gives the seconds to wait                                |
| 500    | `internal_error`: a server-side fault, worth reporting with its `request_id`         |
| 503    | `backend_unavailable`: Typesense is unreachable or the corpus is paused; retry later |

Requests per minute per client are configurable under `dre_search.rate_limits`;
federated requests with counts and the cross-corpus suggestions count as more
than one request. Behind a reverse proxy that PHP sees as the client, list it in
`rate_limits.trusted_proxies` so `X-Forwarded-For` identifies the visitor.

Backend exception text is logged server-side and never returned publicly.
