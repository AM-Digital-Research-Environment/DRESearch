/**
 * Thin client for the module's own JSON endpoints (NOT Typesense directly).
 * The PHP proxy holds the key and enforces is_public:=true, so this just
 * shuttles JSON. The active profile (corpus) is injected into every request.
 */

import type {
  ActiveFilters,
  Bootstrap,
  SearchAllRequest,
  SearchAllResponse,
  SearchRequest,
  SearchResponse,
  SuggestAllResponse,
  SuggestGroup,
  Suggestion,
  YearBucket,
} from './types';

export class SearchApi {
  constructor(
    private readonly endpoints: Bootstrap['endpoints'],
    private readonly profile: string,
  ) {}

  async search(req: SearchRequest): Promise<SearchResponse> {
    const res = await fetch(this.endpoints.search, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ ...req, profile: this.profile }),
    });
    if (!res.ok) {
      throw new Error(`Search request failed (HTTP ${res.status})`);
    }
    return (await res.json()) as SearchResponse;
  }

  /**
   * Per-year counts for the date-slider histogram, scoped to the query +
   * categorical filters (the server ignores the year range, so the bars show the
   * full span). Best-effort: a missing endpoint, a non-OK response, or an abort
   * yields [] so the slider still works without bars.
   */
  async yearHistogram(
    req: { q: string; filters: ActiveFilters; locked_filter: string },
    signal?: AbortSignal,
  ): Promise<YearBucket[]> {
    const endpoint = this.endpoints.year_histogram;
    if (!endpoint) {
      return [];
    }
    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ ...req, profile: this.profile }),
        signal,
      });
      if (!res.ok) {
        return [];
      }
      const data = (await res.json()) as { buckets?: YearBucket[] };
      return data.buckets ?? [];
    } catch {
      // Aborted or network error — the histogram is a progressive enhancement.
      return [];
    }
  }

  async suggest(q: string, signal?: AbortSignal): Promise<Suggestion[]> {
    const url =
      `${this.endpoints.suggest}?profile=${encodeURIComponent(this.profile)}` +
      `&q=${encodeURIComponent(q)}`;
    try {
      const res = await fetch(url, { headers: { Accept: 'application/json' }, signal });
      if (!res.ok) {
        return [];
      }
      const data = (await res.json()) as { available: boolean; suggestions: Suggestion[] };
      return data.suggestions ?? [];
    } catch {
      // Aborted or network error — silently yield nothing; the input still works.
      return [];
    }
  }
}

/**
 * Federated autocomplete across every corpus (the header search bar). Returns
 * grouped, type-tagged suggestions. Errors/aborts yield an empty group list so
 * the input keeps working.
 */
export async function suggestAll(
  endpoint: string,
  q: string,
  signal?: AbortSignal,
): Promise<SuggestGroup[]> {
  const url = `${endpoint}?q=${encodeURIComponent(q)}`;
  try {
    const res = await fetch(url, { headers: { Accept: 'application/json' }, signal });
    if (!res.ok) {
      return [];
    }
    const data = (await res.json()) as SuggestAllResponse;
    return data.groups ?? [];
  } catch {
    return [];
  }
}

/**
 * Federated search for the results page: per-corpus counts (tab badges) plus the
 * focused corpus's full faceted response.
 */
export async function searchAll(
  endpoint: string,
  req: SearchAllRequest,
): Promise<SearchAllResponse> {
  const res = await fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(req),
  });
  if (!res.ok) {
    throw new Error(`Federated search failed (HTTP ${res.status})`);
  }
  return (await res.json()) as SearchAllResponse;
}
