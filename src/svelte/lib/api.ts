/**
 * Thin client for the module's own JSON endpoints (NOT Typesense directly).
 * The PHP proxy holds the key and enforces is_public:=true, so this just
 * shuttles JSON. The active profile (corpus) is injected into every request.
 */

import type { Bootstrap, SearchRequest, SearchResponse, Suggestion } from './types';

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
