import { afterEach, describe, expect, it, vi } from 'vitest';
import { SearchApi } from '../../src/svelte/lib/api';
import { embeddedUrlPrefix, readUrlState, writeUrlState } from '../../src/svelte/lib/urlState';

const endpoints = {
  search: '/dre-search/api/search',
  export: '/dre-search/api/export',
  suggest: '/dre-search/api/suggest',
  map: '/dre-search/api/map',
  facet: '/dre-search/api/facet',
};

const request = {
  q: '',
  page: 1,
  per_page: 20,
  sort: 'relevance' as const,
  filters: {},
  facets: ['type_s'],
};

/** Record every fetch; answer with an empty search response. */
function stubFetch(): { url: string; body: Record<string, unknown> | null }[] {
  const calls: { url: string; body: Record<string, unknown> | null }[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn((url: string, init?: RequestInit) => {
      calls.push({
        url,
        body:
          typeof init?.body === 'string'
            ? (JSON.parse(init.body) as Record<string, unknown>)
            : null,
      });
      return Promise.resolve(
        new Response(
          JSON.stringify({ available: true, found: 0, page: 1, hits: [], facets: {}, counts: [] }),
          {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
          },
        ),
      );
    }),
  );
  return calls;
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('Project records scope (scope_item)', () => {
  it('sends the project item, never a block id, on every scoped request', async () => {
    const calls = stubFetch();
    const api = new SearchApi(endpoints, 'research_items', null, 1797);

    await api.search(request);
    await api.facet(request, 'type_s', 'sou', new AbortController().signal);
    await api.export({ q: '', sort: 'relevance', filters: {} });
    await api.map({ q: '', sort: 'relevance', filters: {} });
    await api.suggest('inter');

    const bodies = calls.filter((call) => call.body !== null).map((call) => call.body);
    expect(bodies).toHaveLength(4);
    for (const body of bodies) {
      expect(body).toMatchObject({ profile: 'research_items', scope_item: 1797 });
      expect(body).not.toHaveProperty('block_id');
    }
    const suggest = new URL(calls[4]!.url, 'https://example.test');
    expect(suggest.searchParams.get('scope_item')).toBe('1797');
    expect(suggest.searchParams.has('block_id')).toBe(false);
  });

  it('leaves the scope out of page-block and federated requests', async () => {
    const calls = stubFetch();
    await new SearchApi(endpoints, 'research_items', 42).search(request);
    await new SearchApi(endpoints, 'research_items').search(request);
    expect(calls[0]!.body).toMatchObject({ block_id: 42 });
    expect(calls[0]!.body).not.toHaveProperty('scope_item');
    expect(calls[1]!.body).not.toHaveProperty('scope_item');
    expect(calls[1]!.body).not.toHaveProperty('block_id');
  });
});

describe('embedded URL prefix', () => {
  it('namespaces a page block by block id and the item-page block by item id', () => {
    expect(embeddedUrlPrefix({ block_id: 42 })).toBe('b42.');
    expect(embeddedUrlPrefix({ block_id: null, scope_item: 1797 })).toBe('r1797.');
    expect(embeddedUrlPrefix({ block_id: null })).toBeNull();
  });

  it("keeps the project's search clear of the item page's own params", () => {
    const query = writeUrlState(
      {
        q: 'interview',
        page: 2,
        sort: 'newest',
        filters: { type_s: ['Sound'] },
        yearFrom: null,
        yearTo: null,
      },
      { prefix: 'r1797.' },
      '?page=3&q=theme&b42.q=other',
    );
    const params = new URLSearchParams(query);
    expect(params.get('page')).toBe('3');
    expect(params.get('q')).toBe('theme');
    expect(params.get('b42.q')).toBe('other');
    expect(params.get('r1797.q')).toBe('interview');
    expect(params.get('r1797.page')).toBe('2');
    expect(params.getAll('r1797.f.type_s')).toEqual(['Sound']);

    const state = readUrlState(`https://example.test/s/amira/item/1797${query}`, {
      prefix: 'r1797.',
    });
    expect(state).toMatchObject({ q: 'interview', page: 2, filters: { type_s: ['Sound'] } });
  });
});
