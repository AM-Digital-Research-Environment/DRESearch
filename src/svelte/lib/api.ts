/**
 * Thin client for the module's own JSON endpoints (NOT Typesense directly).
 * The PHP proxy holds the key and enforces is_public:=true, so this just
 * shuttles JSON. The active profile (corpus) is injected into every request.
 */

import type {
  Bootstrap,
  FacetCount,
  ExportRequest,
  ExportResponse,
  MapRequest,
  MapResponse,
  SearchAllRequest,
  SearchAllResponse,
  SearchRequest,
  SearchResponse,
  SuggestAllResponse,
  SuggestGroup,
  Suggestion,
  UnionSearchRequest,
} from './types';

// Ephemeral per-page identity: no IP address, cookie, account ID, or persistent storage.
let analyticsId: string | undefined;
const recordedQueries = new Map<string, string>();
function analytics(q: string, scope: string): { record_query: boolean; analytics_id?: string } {
  const record = q.trim() !== '' && recordedQueries.get(scope) !== q;
  recordedQueries.set(scope, q);
  if (record && !analyticsId && typeof globalThis.crypto !== 'undefined') {
    analyticsId = Array.from(crypto.getRandomValues(new Uint8Array(16)), (v) =>
      v.toString(16).padStart(2, '0'),
    ).join('');
  }
  return {
    record_query: record && !!analyticsId,
    ...(analyticsId ? { analytics_id: analyticsId } : {}),
  };
}

export class SearchApi {
  private popularRequest: Promise<string[]> | null = null;

  constructor(
    private readonly endpoints: Bootstrap['endpoints'],
    private readonly profile: string,
    private readonly blockId: number | null = null,
  ) {}

  /**
   * The corpus's popular searches, fetched once per page view (the server
   * caches the list for minutes). Empty when the instance has not opted in,
   * and on any failure: the list is a convenience.
   */
  popular(): Promise<string[]> {
    const endpoint = this.endpoints.popular;
    if (!endpoint) return Promise.resolve([]);
    this.popularRequest ??= fetch(`${endpoint}?profile=${encodeURIComponent(this.profile)}`, {
      headers: { Accept: 'application/json' },
    })
      .then(async (res) =>
        res.ok ? (((await res.json()) as { queries?: string[] }).queries ?? []) : [],
      )
      .catch(() => []);
    return this.popularRequest;
  }

  async search(req: SearchRequest, signal?: AbortSignal): Promise<SearchResponse> {
    const res = await fetch(this.endpoints.search, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ ...this.withScope(req), ...analytics(req.q, this.profile) }),
      signal,
    });
    await requireOk(res, 'Search request failed');
    return (await res.json()) as SearchResponse;
  }

  /** Search facet values beyond the initial top counts, retaining the current scope. */
  async facet(
    req: SearchRequest,
    field: string,
    query: string,
    signal: AbortSignal,
  ): Promise<FacetCount[]> {
    if (!this.endpoints.facet) return [];
    const res = await fetch(this.endpoints.facet, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ ...this.withScope(req), facet_field: field, facet_query: query }),
      signal,
    });
    await requireOk(res, 'Facet search failed');
    return ((await res.json()) as { counts: FacetCount[] }).counts;
  }

  /** Fetch capped citation documents for the current query, filters, sort and year scope. */
  async export(req: ExportRequest): Promise<ExportResponse> {
    const res = await fetch(this.endpoints.export, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(this.withScope(req)),
    });
    await requireOk(res, 'Export request failed');
    return (await res.json()) as ExportResponse;
  }

  async suggest(q: string, signal?: AbortSignal): Promise<Suggestion[]> {
    const url =
      `${this.endpoints.suggest}?profile=${encodeURIComponent(this.profile)}` +
      `&q=${encodeURIComponent(q)}` +
      (this.blockId !== null ? `&block_id=${encodeURIComponent(this.blockId)}` : '');
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

  async map(req: MapRequest, signal?: AbortSignal): Promise<MapResponse> {
    const res = await fetch(this.endpoints.map, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(this.withScope(req)),
      signal,
    });
    await requireOk(res, 'Map request failed');
    return (await res.json()) as MapResponse;
  }

  private withScope<T extends SearchRequest | ExportRequest | MapRequest>(
    req: T,
  ): T & { profile: string } {
    return {
      ...req,
      profile: this.profile,
      ...(this.blockId !== null ? { block_id: this.blockId } : {}),
    };
  }
}

/**
 * Throw on a failed response. The HTTP status, the request id and the server's
 * message are for whoever debugs it, so they go to the console; the thrown
 * error carries only `fallback`, and the surfaces show their own translated
 * message with a Try again button instead (DRE-theme integration contract,
 * "Asynchronous states"). They used to print all three to the visitor.
 */
async function requireOk(res: Response, fallback: string): Promise<void> {
  if (res.ok) return;
  type ErrorBody = { error?: { message?: string; request_id?: string } };
  let body: ErrorBody | undefined;
  try {
    body = (await res.json()) as ErrorBody;
  } catch {
    // Non-JSON intermediary response: the status alone has to do.
  }
  console.error(`[dre-search] ${fallback}`, {
    status: res.status,
    requestId: body?.error?.request_id || res.headers.get('X-Request-ID') || undefined,
    message: body?.error?.message?.trim() || undefined,
  });
  throw new Error(fallback);
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
  signal?: AbortSignal,
): Promise<SearchAllResponse> {
  const res = await fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({
      ...req,
      ...(req.record_query === false ? {} : analytics(req.q, req.profile)),
    }),
    signal,
  });
  await requireOk(res, 'Federated search failed');
  return (await res.json()) as SearchAllResponse;
}

/** Typesense v30 union search through the module's server-side proxy. */
export async function searchUnion(
  endpoint: string,
  req: UnionSearchRequest,
  signal?: AbortSignal,
): Promise<SearchResponse> {
  const res = await fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(req),
    signal,
  });
  await requireOk(res, 'Merged search failed');
  return (await res.json()) as SearchResponse;
}
