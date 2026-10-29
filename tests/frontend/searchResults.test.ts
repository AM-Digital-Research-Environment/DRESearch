import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  createMapResults,
  createSearchResults,
  type ResultsRequest,
  type SearchResultsClient,
  type SearchResultsOptions,
} from '../../src/svelte/lib/searchResults.svelte';
import type { MapResponse, SearchResponse, Suggestion } from '../../src/svelte/lib/types';

/**
 * The fetch rules that used to live in App.svelte's search and map effects,
 * reached here through `run()` against a fake client, with no component.
 */

function res(found: number, over: Partial<SearchResponse> = {}): SearchResponse {
  return {
    available: true,
    found,
    page: 1,
    hits: Array.from({ length: Math.min(found, 3) }, (_, i) => ({
      id: String(i + 1),
      title: `Hit ${i + 1}`,
    })),
    facets: [{ field: 'type_s', label: 'Type', counts: [{ value: 'Text', count: found }] }],
    ...over,
  } as SearchResponse;
}

/** A settled-by-hand promise, so ordering between calls is under the test's control. */
function deferred<T>() {
  let resolve!: (v: T) => void;
  let reject!: (e: unknown) => void;
  const promise = new Promise<T>((a, b) => {
    resolve = a;
    reject = b;
  });
  return { promise, resolve, reject };
}

const flush = () => new Promise((r) => setTimeout(r, 0));

function fakeClient(answer: () => Promise<SearchResponse> = async () => res(3)) {
  return {
    search: vi.fn((_req: Parameters<SearchResultsClient['search']>[0], _signal?: AbortSignal) =>
      answer(),
    ),
    suggest: vi.fn(async (): Promise<Suggestion[]> => [{ id: '9', title: 'colonial' }]),
  };
}

function make(client: SearchResultsClient, over: Partial<SearchResultsOptions> = {}) {
  const onFruitfulQuery = vi.fn();
  const onPageOverflow = vi.fn();
  const onSettled = vi.fn();
  const results = createSearchResults(client, {
    facets: ['type_s'],
    perPage: 20,
    initialResponse: null,
    adoptInitial: false,
    onPageOverflow,
    onSettled,
    onFruitfulQuery,
    ...over,
  });
  return { results, onFruitfulQuery, onPageOverflow, onSettled };
}

const req = (over: Partial<ResultsRequest> = {}): ResultsRequest => ({
  q: '',
  page: 1,
  sort: 'relevance',
  filters: {},
  yearFrom: null,
  yearTo: null,
  perPage: 20,
  ...over,
});

afterEach(() => {
  vi.useRealTimers();
});

describe('createSearchResults', () => {
  it('adopts the server-rendered page for the first run, then searches', async () => {
    const client = fakeClient();
    const seed = res(5);
    const { results } = make(client, { initialResponse: seed, adoptInitial: true });
    expect(results.run(req())).toBeUndefined();
    expect(client.search).not.toHaveBeenCalled();
    expect(results.response).toEqual(seed);
    expect(results.isLoading).toBe(false);

    results.run(req({ q: 'islam' }));
    expect(client.search).toHaveBeenCalledTimes(1);
    expect(results.isLoading).toBe(true);
    await flush();
    expect(results.response?.found).toBe(3);
    expect(results.isLoading).toBe(false);
  });

  it('searches on the first run when the seed does not answer it', () => {
    const client = fakeClient();
    const { results } = make(client, { initialResponse: res(5), adoptInitial: false });
    results.run(req({ filters: { type_s: ['Text'] } }));
    expect(client.search).toHaveBeenCalledTimes(1);
    expect(client.search.mock.calls[0]?.[0]).toMatchObject({
      filters: { type_s: ['Text'] },
      facets: ['type_s'],
    });
  });

  it('keeps the facet counts when only the page changes, and recounts for a new scope', async () => {
    const client = fakeClient(async () => res(60, { facets: [] }));
    const { results } = make(client, { initialResponse: res(60), adoptInitial: true });
    results.run(req());
    const seedFacets = results.response?.facets;

    results.run(req({ page: 2 }));
    expect(client.search.mock.calls[0]?.[0].facets).toEqual([]);
    await flush();
    expect(results.response?.facets).toEqual(seedFacets);

    results.run(req({ page: 1, sort: 'title' }));
    expect(client.search.mock.calls[1]?.[0].facets).toEqual(['type_s']);
  });

  it('lets only the latest request settle, whatever order the answers arrive in', async () => {
    const first = deferred<SearchResponse>();
    const second = deferred<SearchResponse>();
    const answers = [first, second];
    const client = fakeClient(() => answers.shift()!.promise);
    const { results } = make(client);

    const cleanupFirst = results.run(req({ q: 'old' }));
    cleanupFirst?.();
    results.run(req({ q: 'new' }));
    const signal = client.search.mock.calls[0]?.[1];
    expect(signal?.aborted).toBe(true);

    second.resolve(res(2, { hits: [{ id: 'n', title: 'New' }] }));
    await flush();
    // The superseded request resolves late (a server that ignores the abort).
    first.resolve(res(1, { hits: [{ id: 'o', title: 'Old' }] }));
    await flush();
    expect(results.response?.hits[0]?.title).toBe('New');
    expect(results.isLoading).toBe(false);
  });

  it('leaves the loading flag to the newer request when an older one fails', async () => {
    const first = deferred<SearchResponse>();
    const second = deferred<SearchResponse>();
    const answers = [first, second];
    const client = fakeClient(() => answers.shift()!.promise);
    const { results } = make(client);
    results.run(req({ q: 'a' }));
    results.run(req({ q: 'ab' }));
    first.reject(new Error('boom'));
    await flush();
    expect(results.isLoading).toBe(true);
    expect(results.failed).toBe(false);
    second.resolve(res(1));
    await flush();
    expect(results.isLoading).toBe(false);
  });

  it('swallows an abort and fails visibly on anything else, clearing the stale results', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    let answer: () => Promise<SearchResponse> = () =>
      Promise.reject(new DOMException('Aborted', 'AbortError'));
    const client = fakeClient(() => answer());
    const { results } = make(client, { initialResponse: res(4), adoptInitial: false });
    results.run(req());
    await flush();
    expect(results.failed).toBe(false);
    expect(results.response?.found).toBe(4);

    answer = () => Promise.reject(new DOMException('Timed out', 'TimeoutError'));
    results.run(req({ q: 'x' }));
    await flush();
    expect(results.failed).toBe(true);
    expect(results.response).toBeNull();
    expect(results.isLoading).toBe(false);
    expect(consoleError).toHaveBeenCalled();
    consoleError.mockRestore();
  });

  it('asks the caller to move to the last page when the page is past the end', async () => {
    const client = fakeClient(async () => res(30));
    const { results, onPageOverflow, onSettled } = make(client);
    results.run(req({ page: 9 }));
    await flush();
    expect(onPageOverflow).toHaveBeenCalledWith(2);
    expect(onSettled).not.toHaveBeenCalled();
    // Under the Map view one hit is asked for: the clamp does not apply.
    results.run(req({ page: 9, perPage: 1 }));
    await flush();
    expect(onPageOverflow).toHaveBeenCalledTimes(1);
  });

  it('remembers a fruitful query and offers a correction for a dead one', async () => {
    let found = 3;
    const client = fakeClient(async () => res(found));
    const { results, onFruitfulQuery } = make(client);
    results.run(req({ q: 'colonial' }));
    await flush();
    expect(onFruitfulQuery).toHaveBeenCalledWith('colonial');
    expect(results.correction).toBeNull();

    found = 0;
    results.run(req({ q: 'colonail' }));
    await flush();
    await flush();
    expect(onFruitfulQuery).toHaveBeenCalledTimes(1);
    expect(client.suggest).toHaveBeenCalledWith('colonail', expect.any(AbortSignal));
    expect(results.correction).toBe('colonial');

    // One character is not worth a suggestion.
    client.suggest.mockClear();
    results.run(req({ q: 'c' }));
    await flush();
    expect(client.suggest).not.toHaveBeenCalled();
  });

  it('re-runs through retry(): the counter is read by run()', () => {
    const client = fakeClient();
    const { results } = make(client);
    // Outside an effect the read is inert; retry() must still not throw and
    // must leave the next run free to search again.
    results.retry();
    results.run(req());
    expect(client.search).toHaveBeenCalledTimes(1);
  });
});

describe('createMapResults', () => {
  const map = (found: number): MapResponse => ({
    available: true,
    found,
    mapped: found,
    capped: false,
    docs: [],
  });

  it('debounces, then draws the latest scope only', async () => {
    vi.useFakeTimers();
    const pending: Array<{ resolve: (r: MapResponse) => void; signal?: AbortSignal }> = [];
    const client = {
      map: vi.fn(
        (_req: unknown, signal?: AbortSignal) =>
          new Promise<MapResponse>((resolve) => pending.push({ resolve, signal })),
      ),
    };
    const results = createMapResults(client, 300);
    const stop = results.run({
      q: 'a',
      sort: 'relevance',
      filters: {},
      yearFrom: null,
      yearTo: null,
    });
    expect(results.loading).toBe(true);
    await vi.advanceTimersByTimeAsync(299);
    expect(client.map).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(client.map).toHaveBeenCalledTimes(1);

    stop();
    expect(pending[0]?.signal?.aborted).toBe(true);
    results.run({ q: 'ab', sort: 'relevance', filters: {}, yearFrom: null, yearTo: null });
    await vi.advanceTimersByTimeAsync(300);
    pending[1]!.resolve(map(7));
    await vi.advanceTimersByTimeAsync(0);
    pending[0]!.resolve(map(1));
    await vi.advanceTimersByTimeAsync(0);
    expect(results.response?.found).toBe(7);
    expect(results.loading).toBe(false);
  });

  it('fails visibly, and reset() clears what it drew and what is in flight', async () => {
    vi.useFakeTimers();
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    let answer = (): Promise<MapResponse> => Promise.reject(new Error('down'));
    const client = { map: vi.fn(() => answer()) };
    const results = createMapResults(client, 0);
    results.run({ q: '', sort: 'relevance', filters: {}, yearFrom: null, yearTo: null });
    await vi.advanceTimersByTimeAsync(0);
    expect(results.failed).toBe(true);
    expect(results.loading).toBe(false);

    const late = deferred<MapResponse>();
    answer = () => late.promise;
    results.run({ q: '', sort: 'relevance', filters: {}, yearFrom: null, yearTo: null });
    await vi.advanceTimersByTimeAsync(0);
    results.reset();
    late.resolve(map(3));
    await vi.advanceTimersByTimeAsync(0);
    expect(results.response).toBeNull();
    expect(results.loading).toBe(false);
    expect(results.failed).toBe(false);
    consoleError.mockRestore();
  });
});
