import type { SearchResponse, SortKey } from './types';
import type { UrlSearchState } from './urlState';

/**
 * Whether a surface may adopt its server-rendered first page instead of
 * searching on mount — the rule that used to sit inline in App.svelte.
 *
 * The PHP block renders the corpus's PRISTINE first page (no facet, default
 * sort, page 1, no year window) for `initial_query`. Adopting it for any
 * other state painted the "browse everything" snapshot under a shared link
 * that asked for facets, a sort, page 2 or a year, and never corrected it.
 */

/** The server's seed response, when it is one the client can render. */
export function usableInitialResponse(raw: SearchResponse | undefined): SearchResponse | null {
  return raw && Array.isArray(raw.hits) ? raw : null;
}

export interface SeedInput {
  initialResponse: SearchResponse | null;
  /** What the URL seeded; null on a surface that does not sync to the URL. */
  url: UrlSearchState | null;
  /** The sort the surface starts on (the URL's, when this corpus offers it). */
  seedSort: SortKey;
  defaultSort: SortKey;
  /** The query the surface starts on. */
  seedQuery: string;
  /** The query the server rendered the seed for (`initial_query`). */
  initialQuery: string;
  /** Whether this surface owns `q`. The federated App does not: its seed is for the shell's query. */
  includeQuery: boolean;
  /** The federated page requested the URL's corpus state itself (`initial_state_applied`). */
  stateApplied: boolean;
}

/** True when the first reactive search may be skipped because the seed already answers it. */
export function seedAnswersFirstSearch(input: SeedInput): boolean {
  const { initialResponse, url } = input;
  if (initialResponse === null || !initialResponse.available) return false;
  const corpusPristine =
    (url?.page ?? 1) === 1 &&
    input.seedSort === input.defaultSort &&
    Object.keys(url?.filters ?? {}).length === 0 &&
    (url?.yearFrom ?? null) === null &&
    (url?.yearTo ?? null) === null;
  // An own-query block's seed was rendered for initial_query, so the query
  // must match it too; the federated App's seed is for its shared query,
  // which its seed query already equals.
  const queryMatchesSeed = !input.includeQuery || input.seedQuery === input.initialQuery;
  return (corpusPristine || input.stateApplied) && queryMatchesSeed;
}
