import { fireEvent, render, screen } from '@testing-library/svelte';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../../src/svelte/App.svelte';
import { ANNOUNCE_DELAY_MS, resultAnnouncement } from '../../src/svelte/lib/announce';
import { configureI18n } from '../../src/svelte/lib/i18n';
import type { Bootstrap, SearchResponse } from '../../src/svelte/lib/types';

/**
 * The results live region speaks SETTLED results only, one beat after the
 * surface has gone quiet — never "Loading…", and never a count that a newer
 * search replaced a moment later.
 */

const searchSpy = vi.fn();

vi.mock('../../src/svelte/lib/api', () => ({
  SearchApi: class {
    search(...args: unknown[]) {
      return searchSpy(...args);
    }
    facet() {
      return Promise.resolve([]);
    }
    suggest() {
      return Promise.resolve([]);
    }
    popular() {
      return Promise.resolve([]);
    }
    map() {
      return Promise.resolve({ available: true, found: 0, mapped: 0, capped: false, docs: [] });
    }
    export() {
      return Promise.resolve({});
    }
  },
}));

function response(found: number): SearchResponse {
  return {
    available: true,
    found,
    page: 1,
    hits: Array.from({ length: Math.min(found, 3) }, (_, i) => ({
      id: String(i + 1),
      title: `Item ${i + 1}`,
    })),
    facets: [],
  } as SearchResponse;
}

function bootstrap(over: Partial<Bootstrap> = {}): Bootstrap {
  return {
    block_id: 7,
    profile: 'research_items',
    card_kind: 'item',
    date_mode: 'single',
    show_year: false,
    year_bounds: null,
    facets: [],
    facet_labels: {},
    default_sort: 'relevance',
    sort_options: [{ value: 'relevance', label: 'Relevance' }],
    per_page: 20,
    item_url_base: '/s/site/item',
    endpoints: { search: '/s', export: '/e', suggest: '/g', map: '/m' },
    initial_response: response(2),
    initial_query: '',
    ...over,
  } as Bootstrap;
}

const status = () => screen.getByRole('status').textContent?.trim();
const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));

describe('resultAnnouncement', () => {
  const base = { loading: false, failed: false, response: response(3), emptyMessage: 'None.' };

  it('holds while a request is in flight', () => {
    expect(resultAnnouncement({ ...base, loading: true })).toBeNull();
    expect(resultAnnouncement({ ...base, loading: true, failed: true })).toBeNull();
  });

  it('says the count, the empty message or the failure once settled', () => {
    expect(resultAnnouncement(base)).toBe('3 results');
    expect(resultAnnouncement({ ...base, response: response(1) })).toBe('1 result');
    expect(resultAnnouncement({ ...base, response: response(0) })).toBe('None.');
    expect(resultAnnouncement({ ...base, failed: true })).toBe(
      'Search is temporarily unavailable.',
    );
    expect(resultAnnouncement({ ...base, response: null })).toBe('');
  });

  it('chooses the plural by the locale', () => {
    configureI18n('fr', { result_one: 'résultat', result_other: 'résultats' });
    expect(resultAnnouncement({ ...base, response: response(1) })).toBe('1 résultat');
    expect(resultAnnouncement({ ...base, response: response(2) })).toBe('2 résultats');
    configureI18n('en');
  });
});

describe('the results live region', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/page');
    searchSpy.mockReset();
  });
  afterEach(() => {
    window.localStorage.clear();
  });

  it("holds a block's seeded count from mount without announcing it later", async () => {
    render(App, { bootstrap: bootstrap() });
    expect(status()).toBe('2 results');
    await wait(ANNOUNCE_DELAY_MS + 50);
    expect(status()).toBe('2 results');
    expect(searchSpy).not.toHaveBeenCalled();
  });

  it('never says "Loading…", and speaks the settled count after a pause', async () => {
    let resolve: (value: SearchResponse) => void = () => undefined;
    searchSpy.mockReturnValue(new Promise<SearchResponse>((r) => (resolve = r)));
    render(App, { bootstrap: bootstrap({ initial_response: undefined }) });
    await wait(ANNOUNCE_DELAY_MS + 50);
    expect(status()).toBe('');
    resolve(response(5));
    await wait(ANNOUNCE_DELAY_MS / 2);
    expect(status()).toBe('');
    await wait(ANNOUNCE_DELAY_MS);
    expect(status()).toBe('5 results');
  });

  it('speaks one sentence for searches settled in quick succession', async () => {
    const found = [7, 4, 1];
    searchSpy.mockImplementation(() => Promise.resolve(response(found.shift() ?? 1)));
    const { container } = render(App, { bootstrap: bootstrap() });
    const node = screen.getByRole('status');
    const spoken: string[] = [];
    new MutationObserver(() => spoken.push(node.textContent?.trim() ?? '')).observe(node, {
      childList: true,
      characterData: true,
      subtree: true,
    });
    const box = container.querySelector('input[role="combobox"]') as HTMLInputElement;
    for (const q of ['is', 'isl', 'islam']) {
      await fireEvent.input(box, { target: { value: q } });
      await fireEvent.keyDown(box, { key: 'Enter' });
      await wait(100);
    }
    await wait(ANNOUNCE_DELAY_MS + 100);
    expect(searchSpy).toHaveBeenCalledTimes(3);
    expect(spoken.filter((text) => text !== '')).toEqual(['1 result']);
  });

  it('speaks a failure once the request has settled', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    searchSpy.mockRejectedValue(new Error('Search request failed'));
    render(App, { bootstrap: bootstrap({ initial_response: undefined }) });
    await screen.findByRole('button', { name: 'Try again' });
    expect(status()).toBe('');
    await wait(ANNOUNCE_DELAY_MS + 50);
    expect(status()).toBe('Search is temporarily unavailable.');
    consoleError.mockRestore();
  });
});
