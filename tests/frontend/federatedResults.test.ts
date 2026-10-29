import { describe, expect, it, vi } from 'vitest';
import {
  createFederatedResults,
  type FederatedClient,
  type FederatedResultsOptions,
} from '../../src/svelte/lib/federatedResults.svelte';
import type {
  SearchAllRequest,
  SearchAllResponse,
  SearchResponse,
  UnionSearchRequest,
} from '../../src/svelte/lib/types';

/** The federated page's fetch rules, reached without mounting FederatedApp. */

function res(title: string, found = 1): SearchResponse {
  return {
    available: true,
    found,
    page: 1,
    facets: [],
    hits: [{ id: '1', title }],
  } as SearchResponse;
}

function deferred<T>() {
  let resolve!: (v: T) => void;
  const promise = new Promise<T>((a) => (resolve = a));
  return { promise, resolve };
}

function make(client: FederatedClient, over: Partial<FederatedResultsOptions> = {}) {
  const onUnionOverflow = vi.fn();
  const onFruitfulQuery = vi.fn();
  const results = createFederatedResults(client, {
    all: 'all',
    unionPerPage: 20,
    maxPage: 250,
    countsProfile: 'first',
    corpusRequest: () => ({ sort: 'relevance', page: 1, filters: {} }),
    onUnionOverflow,
    onFruitfulQuery,
    ...over,
  });
  return { results, onUnionOverflow, onFruitfulQuery };
}

describe('createFederatedResults', () => {
  it('asks for the counts once per query and serves a revisited tab from the cache', async () => {
    const client = {
      searchAll: vi.fn(async (req: { profile: string }): Promise<SearchAllResponse> => ({
        available: true,
        counts: { first: 1, second: 4 },
        active: res(`${req.profile} document`),
      })),
      searchUnion: vi.fn(async (_req: UnionSearchRequest) => res('merged')),
    };
    const { results } = make(client);
    expect(results.countsKnown).toBe(false);
    await results.load('first', 'islam');
    expect(client.searchAll.mock.calls[0]?.[0]).toMatchObject({
      profile: 'first',
      q: 'islam',
      include_counts: true,
    });
    expect(results.countsKnown).toBe(true);
    expect(results.counts.second).toBe(4);

    await results.load('second', 'islam');
    expect(client.searchAll.mock.calls[1]?.[0]).toMatchObject({ include_counts: false });
    await results.load('first', 'islam');
    expect(client.searchAll).toHaveBeenCalledTimes(2);
    expect(results.activeResponse?.hits[0]?.title).toBe('first document');

    // A new query forgets the cache and the counts.
    await results.load('first', 'kenya');
    expect(client.searchAll).toHaveBeenCalledTimes(3);
    expect(client.searchAll.mock.calls[2]?.[0]).toMatchObject({ include_counts: true });
  });

  it('sends the corpus state the URL holds for the tab', async () => {
    const client = {
      searchAll: vi.fn(async (_req: SearchAllRequest) => ({
        available: true,
        counts: {},
        active: res('x'),
      })),
      searchUnion: vi.fn(async (_req: UnionSearchRequest) => res('merged')),
    };
    const { results } = make(client, {
      corpusRequest: (profile) => ({
        sort: 'title',
        page: 2,
        filters: { type_s: [profile] },
        year_from: 1990,
      }),
    });
    await results.load('second', '');
    expect(client.searchAll.mock.calls[0]?.[0]).toMatchObject({
      profile: 'second',
      sort: 'title',
      page: 2,
      filters: { type_s: ['second'] },
      year_from: 1990,
    });
  });

  it('ignores a pending answer once a cached tab has been shown again', async () => {
    const pending = deferred<SearchAllResponse>();
    const client = {
      searchAll: vi.fn(async (req: { profile: string }) =>
        req.profile === 'first'
          ? { available: true, counts: { first: 1 }, active: res('First corpus document') }
          : pending.promise,
      ),
      searchUnion: vi.fn(async (_req: UnionSearchRequest) => res('merged')),
    };
    const { results } = make(client);
    await results.load('first', '');
    const second = results.load('second', '');
    expect(results.isLoading).toBe(true);
    await results.load('first', '');
    expect(results.isLoading).toBe(false);
    pending.resolve({ available: true, counts: {}, active: res('Second corpus document') });
    await second;
    expect(results.activeResponse?.hits[0]?.title).toBe('First corpus document');
    expect(results.isLoading).toBe(false);
  });

  it('merges the All tab with the counts, and clamps a page past the end', async () => {
    const client = {
      searchAll: vi.fn(async (_req: SearchAllRequest) => ({
        available: true,
        counts: { first: 30 },
        active: res('x'),
      })),
      searchUnion: vi.fn(async (_req: UnionSearchRequest) => res('merged', 30)),
    };
    const { results, onUnionOverflow, onFruitfulQuery } = make(client);
    await results.load('all', 'islam', 9);
    expect(onUnionOverflow).toHaveBeenCalledWith(2);
    expect(results.unionResponse).toBeNull();

    await results.load('all', 'islam', 2);
    expect(client.searchUnion.mock.calls[1]?.[0]).toEqual({ q: 'islam', page: 2, per_page: 20 });
    expect(results.unionResponse?.found).toBe(30);
    expect(onFruitfulQuery).toHaveBeenCalledWith('islam');
    expect(results.counts.first).toBe(30);
    expect(client.searchAll.mock.calls[0]?.[0]).toMatchObject({
      profile: 'first',
      per_page: 1,
      include_counts: true,
      record_query: false,
    });
  });

  it('fails visibly, but never for an aborted request', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    let fail: Error = new DOMException('Aborted', 'AbortError');
    const client = {
      searchAll: vi.fn(() => Promise.reject(fail)),
      searchUnion: vi.fn(async (_req: UnionSearchRequest) => res('merged')),
    };
    const { results } = make(client);
    await results.load('first', '');
    expect(results.failed).toBe(false);
    fail = new Error('Federated search failed');
    await results.load('second', '');
    expect(results.failed).toBe(true);
    expect(results.isLoading).toBe(false);
    consoleError.mockRestore();
  });
});
