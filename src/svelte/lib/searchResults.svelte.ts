/**
 * What a search surface has fetched, and the rules for fetching it —
 * extracted from App.svelte, where they were two `$effect`s (the search and
 * the map) that no test could reach without rendering the whole block.
 *
 * App still owns the search STATE (query, page, sort, filters, year window,
 * view), because every control writes it. These modules own what that state
 * turns into: the response, the loading and failure flags, the "did you
 * mean" correction, and the Map view's geo-tagged set.
 *
 * The shape is IwacSearch's (its lib/searchResults.svelte.ts): App calls
 * `run()` from inside an `$effect`, passing the state it read, so the
 * effect's dependencies stay visible where the state lives; `run()` also
 * reads its own retry counter, so `retry()` re-runs the effect by itself.
 * Tests call `run()` directly against a fake client — no component needed.
 *
 * The rules this carries, each of which regressed at least once:
 *
 *  - The server-rendered first page is adopted for the first run only, and
 *    only when it answers the seeded state (lib/initialState.ts).
 *  - Paging through the same scope keeps the facet counts on screen and asks
 *    for none: they cannot change.
 *  - A superseded request settles nothing — the newer one owns the loading
 *    flag. Abort cancels the network; a sequence ticket covers what abort
 *    cannot reach.
 *  - A page past the end of the results (a stale shared link) lands on the
 *    last page instead of an empty list under a non-zero count.
 *  - A failure clears the response: results left on screen under the error
 *    would read as the new filters having worked.
 */
import { untrack } from 'svelte';
import type { SearchApi } from './api';
import type { ActiveFilters, MapResponse, SearchResponse, SortKey } from './types';
import { SeqGuard, isAbortError } from './transport';
import { rememberSearch } from './searchHistory';

/** The slice of search state one fetch is made from. */
export interface ResultsRequest {
  q: string;
  page: number;
  sort: SortKey;
  filters: ActiveFilters;
  yearFrom: number | null;
  yearTo: number | null;
  /**
   * Hits to ask for: the corpus's page size, or 1 under the Map view, where
   * the list request only feeds the facets and the count.
   */
  perPage: number;
}

export interface SearchResultsOptions {
  /** The corpus's sidebar facets, counted whenever the scope changes. */
  facets: readonly string[];
  /** The corpus's page size, which a page past the end is clamped against. */
  perPage: number;
  /** The server-rendered first page, shown until the first search settles. */
  initialResponse: SearchResponse | null;
  /** The first run adopts `initialResponse` instead of searching (lib/initialState.ts). */
  adoptInitial: boolean;
  /** The requested page is past the last one: the caller moves to `lastPage`. */
  onPageOverflow: (lastPage: number) => void;
  /** A current response has settled (App's one-off gallery suggestion reads it). */
  onSettled?: (response: SearchResponse) => void;
  /** A query that found something. Defaults to the recent-searches history. */
  onFruitfulQuery?: (q: string) => void;
}

export type SearchResultsClient = Pick<SearchApi, 'search' | 'suggest'>;

export interface SearchResults {
  readonly response: SearchResponse | null;
  readonly isLoading: boolean;
  /** The last search failed: the surface shows its translated error and "Try again". */
  readonly failed: boolean;
  /** "Did you mean" for a query that found nothing, or null. */
  readonly correction: string | null;
  /** Re-run the current search (the error's "Try again"). */
  retry(): void;
  /** Run inside an $effect: fetch for `req`; returns the cleanup when one is needed. */
  run(req: ResultsRequest): (() => void) | undefined;
}

/** Everything but the page: two requests in one scope share their facet counts. */
function scopeOf(req: ResultsRequest): string {
  return JSON.stringify([req.q, req.sort, req.filters, req.yearFrom, req.yearTo]);
}

export function createSearchResults(
  client: SearchResultsClient,
  opts: SearchResultsOptions,
): SearchResults {
  const onFruitfulQuery = opts.onFruitfulQuery ?? rememberSearch;

  let response = $state<SearchResponse | null>(opts.initialResponse);
  let isLoading = $state(false);
  let failed = $state(false);
  let correction = $state<string | null>(null);
  let retryVersion = $state(0);

  // Plain `let`s, not $state: run() reads them without wanting a dependency,
  // so writing them never re-triggers the calling effect.
  let skipNextFetch = opts.adoptInitial;
  // The scope of the response on screen: a page-only change keeps its facets.
  let shownScope: string | null = null;
  const seq = new SeqGuard();

  function run(req: ResultsRequest): (() => void) | undefined {
    // Read for its dependency only: bumping it re-runs the calling effect.
    void retryVersion;
    const scope = scopeOf(req);

    if (skipNextFetch) {
      skipNextFetch = false;
      shownScope = scope;
      return undefined;
    }

    const { q, page, perPage } = req;
    const previous = untrack(() => response);
    // Paging through the same scope: the facet counts cannot change. Card
    // chips may filter display fields that are not sidebar facets, so the
    // scope, not the facet list, decides.
    const keepFacets = scope === shownScope && previous !== null && previous.available;
    const ticket = seq.start();
    const controller = new AbortController();
    isLoading = true;
    failed = false;

    client
      .search(
        {
          q,
          page,
          per_page: perPage,
          sort: req.sort,
          filters: req.filters,
          facets: keepFacets ? [] : [...opts.facets],
          year_from: req.yearFrom,
          year_to: req.yearTo,
        },
        controller.signal,
      )
      .then((r) => {
        if (seq.isStale(ticket)) return; // a newer search has superseded this one
        const lastPage = Math.max(1, Math.ceil(r.found / opts.perPage));
        if (page > lastPage && perPage === opts.perPage) {
          opts.onPageOverflow(lastPage);
          return;
        }
        response = keepFacets && previous ? { ...r, facets: previous.facets } : r;
        shownScope = scope;
        if (q.trim() && r.found > 0) onFruitfulQuery(q);
        opts.onSettled?.(r);
        correction = null;
        if (r.found === 0 && q.trim().length >= 2) {
          // Best effort: a failed or aborted suggestion just means no offer.
          void client
            .suggest(q, controller.signal)
            .then((suggestions) => {
              if (!seq.isStale(ticket)) correction = suggestions[0]?.title ?? null;
            })
            .catch(() => undefined);
        }
      })
      .catch((e: unknown) => {
        if (seq.isStale(ticket) || isAbortError(e)) return;
        console.error('[dre-search] search failed', e);
        failed = true;
        response = null;
      })
      .finally(() => {
        // The newer request owns the flag: clearing it here would blank its skeleton.
        if (!seq.isStale(ticket)) isLoading = false;
      });

    return () => controller.abort();
  }

  return {
    get response() {
      return response;
    },
    get isLoading() {
      return isLoading;
    },
    get failed() {
      return failed;
    },
    get correction() {
      return correction;
    },
    retry(): void {
      retryVersion += 1;
    },
    run,
  };
}

/** The slice of search state the Map view is drawn from — no page. */
export interface MapScope {
  q: string;
  sort: SortKey;
  filters: ActiveFilters;
  yearFrom: number | null;
  yearTo: number | null;
}

export interface MapResults {
  readonly response: MapResponse | null;
  readonly loading: boolean;
  /** The last fetch failed: the map says so and offers a retry, rather than "no places". */
  readonly failed: boolean;
  /** Re-run the current fetch (the failure notice's button). */
  retry(): void;
  /** Leave the Map view: drop what it drew and settle nothing still in flight. */
  reset(): void;
  /** Run inside an $effect while the Map view is active; returns its cleanup. */
  run(scope: MapScope): () => void;
}

/** Typing pace: the map endpoint pulls up to 1,000 documents per request. */
export const MAP_DEBOUNCE_MS = 300;

/**
 * Every geo-tagged document matching the current scope, for the Map view.
 * Debounced like typing; one abort signal cancels the request, and the
 * sequence ticket guards the settle, so a fetch that lost the race to a
 * newer one can never overwrite it. Like {@link createSearchResults},
 * `run()` reads the retry counter, so `retry()` re-runs the effect by itself.
 */
export function createMapResults(
  client: Pick<SearchApi, 'map'>,
  debounceMs = MAP_DEBOUNCE_MS,
): MapResults {
  let response = $state<MapResponse | null>(null);
  let loading = $state(false);
  let failed = $state(false);
  let retryVersion = $state(0);
  const seq = new SeqGuard();

  return {
    get response() {
      return response;
    },
    get loading() {
      return loading;
    },
    get failed() {
      return failed;
    },
    retry(): void {
      retryVersion += 1;
    },
    reset(): void {
      seq.start();
      response = null;
      loading = false;
      failed = false;
    },
    run(scope: MapScope): () => void {
      void retryVersion;
      const ticket = seq.start();
      const controller = new AbortController();
      loading = true;
      failed = false;
      const timer = window.setTimeout(() => {
        client
          .map(
            {
              q: scope.q,
              sort: scope.sort,
              filters: scope.filters,
              year_from: scope.yearFrom,
              year_to: scope.yearTo,
            },
            controller.signal,
          )
          .then((next) => {
            if (seq.isStale(ticket)) return; // superseded — a newer fetch is in flight
            response = next;
            loading = false;
          })
          .catch((e: unknown) => {
            if (seq.isStale(ticket) || isAbortError(e)) return;
            console.error('[dre-search] map request failed', e);
            failed = true;
            loading = false;
          });
      }, debounceMs);
      return () => {
        window.clearTimeout(timer);
        controller.abort();
        seq.start();
      };
    },
  };
}
