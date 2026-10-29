/**
 * What the federated "Search all" page has fetched — extracted from
 * FederatedApp.svelte, whose `load()` carried the tab cache, the counts
 * ride-along and the request race inline.
 *
 * FederatedApp still owns the shell STATE (the shared query, the active tab,
 * the merged list's page) and the URL. This owns the responses: the merged
 * "All" list, the active corpus's first page (which the embedded App adopts),
 * the per-corpus tab counts, and the loading and failure flags.
 *
 * The rules this carries:
 *
 *  - One response per tab and page is cached for the current query, so going
 *    back to a tab paints at once; a new query empties the cache and forgets
 *    the counts.
 *  - The counts fan out across every corpus, so they are asked for once per
 *    query, by whichever request comes first.
 *  - The latest request wins. Returning to a cached tab supersedes a request
 *    still in flight for another one: its late answer must not replace the
 *    tab now on screen (tests/frontend/federated-cache-race.test.ts).
 *  - A shared link past the last merged page lands on the last page.
 */
import type {
  SearchAllRequest,
  SearchAllResponse,
  SearchResponse,
  UnionSearchRequest,
} from './types';
import { SeqGuard, isAbortError } from './transport';
import { rememberSearch } from './searchHistory';

export interface FederatedClient {
  searchAll(req: SearchAllRequest, signal: AbortSignal): Promise<SearchAllResponse>;
  searchUnion(req: UnionSearchRequest, signal: AbortSignal): Promise<SearchResponse>;
}

/** A corpus tab's request beyond the shared query: what its URL state asks for. */
export type CorpusRequest = Omit<SearchAllRequest, 'profile' | 'q' | 'include_counts'>;

export interface FederatedResultsOptions {
  /** The merged tab's name. */
  all: string;
  /** Hits per page of the merged list. */
  unionPerPage: number;
  /** The deepest page the server serves (urlState MAX_PAGE). */
  maxPage: number;
  /** The corpus the counts ride along with while the merged tab is open. */
  countsProfile: string;
  /**
   * The state the URL already holds for `profile` (a deep link, or a card
   * chip's hand-off), so the embedded App can adopt the response instead of
   * searching again with the filters.
   */
  corpusRequest: (profile: string) => CorpusRequest;
  /** The merged list's page is past its last one: the caller moves to `lastPage`. */
  onUnionOverflow: (lastPage: number) => void;
  /** A query that found something. Defaults to the recent-searches history. */
  onFruitfulQuery?: (q: string) => void;
}

export interface FederatedResults {
  /** profile => matches for the current query; meaningful once {@link countsKnown}. */
  readonly counts: Record<string, number>;
  /** The counts answer the current query (until then the tabs show none). */
  readonly countsKnown: boolean;
  readonly unionResponse: SearchResponse | null;
  readonly activeResponse: SearchResponse | null;
  readonly isLoading: boolean;
  readonly failed: boolean;
  /** Show `profile`'s `page` for `q`, from the cache or the network. */
  load(profile: string, q: string, page?: number): Promise<void>;
  /** Cancel whatever is in flight (the surface is unmounting). */
  abort(): void;
}

export function createFederatedResults(
  client: FederatedClient,
  opts: FederatedResultsOptions,
): FederatedResults {
  const onFruitfulQuery = opts.onFruitfulQuery ?? rememberSearch;

  let counts = $state<Record<string, number>>({});
  let countsQuery = $state<string | null>(null);
  let activeResponse = $state<SearchResponse | null>(null);
  let unionResponse = $state<SearchResponse | null>(null);
  let isLoading = $state(false);
  let failed = $state(false);

  // Plain, not $state: nothing renders them.
  let cache: Record<string, SearchResponse> = {};
  let cacheQuery: string | null = null;
  let controller: AbortController | null = null;
  const seq = new SeqGuard();

  async function load(profile: string, q: string, page = 1): Promise<void> {
    if (q !== cacheQuery) {
      cache = {};
      cacheQuery = q;
      countsQuery = null;
    }
    const ticket = seq.start();
    controller?.abort();
    isLoading = false;
    failed = false;
    const cacheKey = `${profile}:${page}`;
    const cached = cache[cacheKey];
    if (cached) {
      if (profile === opts.all) unionResponse = cached;
      else activeResponse = cached;
      return;
    }
    const request = new AbortController();
    controller = request;
    isLoading = true;
    try {
      if (profile === opts.all) {
        unionResponse = null;
        const unionPromise = client.searchUnion(
          { q, page, per_page: opts.unionPerPage },
          request.signal,
        );
        const countsPromise =
          countsQuery === q
            ? Promise.resolve(null)
            : client.searchAll(
                {
                  profile: opts.countsProfile,
                  q,
                  per_page: 1,
                  include_counts: true,
                  record_query: false,
                },
                request.signal,
              );
        const [merged, countResult] = await Promise.all([unionPromise, countsPromise]);
        if (seq.isStale(ticket)) return;
        const lastPage = Math.min(
          opts.maxPage,
          Math.max(1, Math.ceil(merged.found / opts.unionPerPage)),
        );
        if (page > lastPage) {
          opts.onUnionOverflow(lastPage);
          return;
        }
        unionResponse = merged;
        cache[cacheKey] = merged;
        if (q.trim() && merged.found > 0) onFruitfulQuery(q);
        if (countResult) {
          counts = countResult.counts;
          countsQuery = q;
        }
      } else {
        activeResponse = null;
        const result = await client.searchAll(
          { ...opts.corpusRequest(profile), profile, q, include_counts: countsQuery !== q },
          request.signal,
        );
        if (seq.isStale(ticket)) return;
        activeResponse = result.active;
        cache[cacheKey] = result.active;
        if (Object.keys(result.counts).length) {
          counts = result.counts;
          countsQuery = q;
        }
      }
    } catch (reason) {
      if (!seq.isStale(ticket) && !isAbortError(reason)) {
        console.error('[dre-search] federated search failed', reason);
        failed = true;
      }
    } finally {
      if (!seq.isStale(ticket)) isLoading = false;
    }
  }

  return {
    get counts() {
      return counts;
    },
    get countsKnown() {
      return countsQuery !== null;
    },
    get unionResponse() {
      return unionResponse;
    },
    get activeResponse() {
      return activeResponse;
    },
    get isLoading() {
      return isLoading;
    },
    get failed() {
      return failed;
    },
    load,
    abort(): void {
      controller?.abort();
    },
  };
}
