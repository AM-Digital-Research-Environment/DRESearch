/**
 * Thin client for the module's own JSON endpoints (NOT Typesense directly).
 * The PHP proxy holds the key and enforces is_public:=true, so this just
 * shuttles JSON. The active profile (corpus) is injected into every request.
 */

import type {
  Bootstrap,
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

export class SearchApi {
  constructor(
    private readonly endpoints: Bootstrap['endpoints'],
    private readonly profile: string,
    private readonly blockId: number | null = null,
  ) {}

  async search(req: SearchRequest, signal?: AbortSignal): Promise<SearchResponse> {
    const res = await fetch(this.endpoints.search, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(this.withScope(req)),
      signal,
    });
    await requireOk(res, 'Search request failed');
    return (await res.json()) as SearchResponse;
  }

  /**
   * Fetch the CURRENT result set (same query / filters / sort / year scope) for a
   * client-side export. The server pages internally and caps the count, returning
   * citation-only documents; the export menu serializes them to txt/json/ris/bibtex.
   */
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

async function requireOk(res: Response, fallback: string): Promise<void> {
  if (res.ok) return;
  type ErrorBody = { error?: { message?: string; request_id?: string } };
  let body: ErrorBody | undefined;
  try {
    body = (await res.json()) as ErrorBody;
  } catch {
    // Non-JSON intermediary response: fall back to the stable HTTP message.
  }
  const message = body?.error?.message?.trim() || `${fallback} (HTTP ${res.status})`;
  const requestId = body?.error?.request_id || res.headers.get('X-Request-ID');
  throw new Error(requestId ? `${message} [${requestId}]` : message);
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
    body: JSON.stringify(req),
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
