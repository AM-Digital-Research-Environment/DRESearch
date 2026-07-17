# Public API

All endpoints return JSON and `X-Request-ID`. Search, export, and federated
search accept POST `application/json`; suggestions accept bounded GET queries.

- `POST /dre-search/api/search`: one profile's hits and facets.
- `POST /dre-search/api/export`: complete citation fields up to 1,000 hits.
- `GET /dre-search/api/suggest`: one profile's title suggestions.
- `GET /dre-search/api/suggest-all`: grouped suggestions across profiles.
- `POST /dre-search/api/search-all`: active results and optionally cached counts.

Requests are strict: unknown keys, profiles/fields, unsupported sorts, oversized
bodies/queries, and out-of-range paging are rejected. Clients may send a saved
`block_id`; the server resolves its locked filter and checks that its layout
matches the profile. Raw `locked_filter` input is not accepted.

Errors use:

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

Backend exception text is logged server-side and never returned publicly.
