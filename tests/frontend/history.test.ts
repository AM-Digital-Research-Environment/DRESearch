import { fireEvent, render, screen, waitFor } from '@testing-library/svelte';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../../src/svelte/App.svelte';
import FederatedApp from '../../src/svelte/components/FederatedApp.svelte';
import { searchAll, searchUnion } from '../../src/svelte/lib/api';
import type { Bootstrap, SearchResponse } from '../../src/svelte/lib/types';

/**
 * A burst of typing is ONE history step on both surfaces: its first commit
 * pushes, the commits after it replace, and Enter, leaving the box, a pick
 * or another control ends it. What the URL encodes does not change.
 */

function response(found = 2): SearchResponse {
  return {
    available: true,
    found,
    page: 1,
    hits: Array.from({ length: found }, (_, i) => ({ id: String(i + 1), title: `Item ${i + 1}` })),
    facets: [],
  } as SearchResponse;
}

vi.mock('../../src/svelte/lib/api', () => ({
  SearchApi: class {
    search() {
      return Promise.resolve(response());
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
  searchAll: vi.fn(),
  searchUnion: vi.fn(),
}));

const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));

let push: ReturnType<typeof vi.spyOn>;
let replace: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
  window.history.replaceState({}, '', '/page');
  vi.stubGlobal('matchMedia', () => ({
    matches: false,
    addEventListener() {},
    removeEventListener() {},
  }));
  push = vi.spyOn(window.history, 'pushState');
  replace = vi.spyOn(window.history, 'replaceState');
});

afterEach(() => {
  push.mockRestore();
  replace.mockRestore();
  vi.unstubAllGlobals();
  window.localStorage.clear();
});

describe('a search block', () => {
  const bootstrap = (): Bootstrap =>
    ({
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
      initial_response: response(),
      initial_query: '',
    }) as Bootstrap;

  /** Type `value` and wait out the box's debounce, so it commits. */
  async function type(box: HTMLInputElement, value: string): Promise<void> {
    await fireEvent.input(box, { target: { value } });
    await wait(300);
  }

  it('writes one step per typing burst', async () => {
    render(App, { bootstrap: bootstrap() });
    const box = screen.getByRole('combobox', {
      name: 'Search research items…',
    }) as HTMLInputElement;
    push.mockClear();
    for (const value of ['is', 'isla', 'islam']) await type(box, value);
    expect(push).toHaveBeenCalledTimes(1);
    expect(new URL(window.location.href).searchParams.get('b7.q')).toBe('islam');

    // Leaving the box ends the burst: the next query is a step of its own.
    await fireEvent.blur(box);
    await type(box, 'islamic');
    expect(push).toHaveBeenCalledTimes(2);
    expect(new URL(window.location.href).searchParams.get('b7.q')).toBe('islamic');
  });

  it('ends the burst on Enter', async () => {
    render(App, { bootstrap: bootstrap() });
    const box = screen.getByRole('combobox', {
      name: 'Search research items…',
    }) as HTMLInputElement;
    push.mockClear();
    await type(box, 'kenya');
    await fireEvent.keyDown(box, { key: 'Enter' });
    await type(box, 'kenyan');
    expect(push).toHaveBeenCalledTimes(2);
  });

  it('keeps paging a replacement and a removed query chip a step', async () => {
    window.history.replaceState({}, '', '/page?b7.q=islam');
    render(App, { bootstrap: { ...bootstrap(), initial_query: 'islam' } });
    await screen.findByText('Item 1');
    push.mockClear();
    await fireEvent.click(screen.getByRole('button', { name: /Remove filter Search: islam/ }));
    await waitFor(() => expect(new URL(window.location.href).searchParams.has('b7.q')).toBe(false));
    expect(push).toHaveBeenCalledTimes(1);
  });
});

describe('the federated page', () => {
  const profile = {
    name: 'first',
    label: 'first',
    kind: 'item',
    date_mode: 'none',
    show_year: false,
    year_bounds: null,
    facets: [],
    facet_labels: {},
    default_sort: 'relevance',
    sort_options: [{ value: 'relevance', label: 'Relevance' }],
    per_page: 20,
  };

  it('writes one ?q step per typing burst in the shared box', async () => {
    vi.mocked(searchAll).mockResolvedValue({ available: true, counts: {}, active: response() });
    vi.mocked(searchUnion).mockResolvedValue(response());
    window.history.replaceState({}, '', '/search');
    render(FederatedApp, {
      bootstrap: {
        variant: 'federated',
        available: true,
        item_url_base: '/items',
        initial_query: '',
        default_profile: 'first',
        profiles: [profile],
        endpoints: {
          search: '/search',
          export: '/export',
          search_all: '/all',
          union: '/union',
          map: '/map',
          suggest: '/suggest',
          suggest_all: '/suggest-all',
        },
      },
    } as never);
    const box = screen.getByRole('searchbox', { name: 'Search everything…' });
    push.mockClear();
    for (const value of ['ra', 'rama', 'ramadan']) {
      await fireEvent.input(box, { target: { value } });
      await wait(350);
    }
    expect(push).toHaveBeenCalledTimes(1);
    expect(new URL(window.location.href).searchParams.get('q')).toBe('ramadan');

    await fireEvent.keyDown(box, { key: 'Enter' });
    await fireEvent.input(box, { target: { value: 'ramadan kenya' } });
    await wait(350);
    expect(push).toHaveBeenCalledTimes(2);
  });
});
