import { fireEvent, render, screen, waitFor } from '@testing-library/svelte';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import FacetGroup from '../../src/svelte/components/FacetGroup.svelte';
import App from '../../src/svelte/App.svelte';
import type { Bootstrap, SearchResponse } from '../../src/svelte/lib/types';
import { rememberSearch, recentSearches } from '../../src/svelte/lib/searchHistory';

const searchSpy = vi.fn();

if (typeof window.matchMedia !== 'function') {
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    addListener: () => undefined,
    removeListener: () => undefined,
    dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia;
}

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

function response(found = 2): SearchResponse {
  return {
    available: true,
    found,
    page: 1,
    hits: Array.from({ length: found }, (_, i) => ({ id: String(i + 1), title: `Item ${i + 1}` })),
    facets: [],
  } as SearchResponse;
}

function bootstrap(): Bootstrap {
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
    sort_options: [
      { value: 'relevance', label: 'Relevance' },
      { value: 'title', label: 'Title' },
    ],
    per_page: 20,
    item_url_base: '/s/site/item',
    endpoints: { search: '/s', export: '/e', suggest: '/g', map: '/m' },
    initial_response: response(),
    initial_query: '',
  } as Bootstrap;
}

describe('facet value search', () => {
  it('keeps the searched list on screen while a ticked value re-counts it', async () => {
    const resolvers: ((v: { value: string; count: number | null }[]) => void)[] = [];
    const search = vi.fn(
      () =>
        new Promise<{ value: string; count: number | null }[]>((resolve) =>
          resolvers.push(resolve),
        ),
    );
    const props = {
      field: 'subject_ss',
      label: 'Subjects',
      counts: Array.from({ length: 20 }, (_, i) => ({ value: `Subject ${i}`, count: 3 })),
      selected: [] as string[],
      searchValues: search,
      onToggle: vi.fn(),
      scopeKey: 'a',
    };
    const view = render(FacetGroup, props);
    await fireEvent.input(screen.getByRole('searchbox'), { target: { value: 'rare' } });
    await waitFor(() => expect(resolvers).toHaveLength(1));
    resolvers[0]!([{ value: 'Rare topic', count: 1 }]);
    await screen.findByRole('checkbox', { name: /Rare topic/ });

    // The parent re-renders with the value selected and a new scope.
    await view.rerender({ ...props, selected: ['Rare topic'], scopeKey: 'b' });
    expect(screen.getByRole('checkbox', { name: /Rare topic/ })).toBeTruthy();
    expect(screen.queryByText('Loading…')).toBeNull();
    await waitFor(() => expect(resolvers).toHaveLength(2), { timeout: 2000 });
  });

  it('names each checkbox list after its facet', async () => {
    render(FacetGroup, {
      field: 'type_s',
      label: 'Type',
      counts: [{ value: 'Text', count: 2 }],
      selected: [],
      onToggle: vi.fn(),
    });
    expect(screen.getByRole('group', { name: /Type/ })).toBeTruthy();
  });

  it('shows no number for a selected value whose count is unknown', async () => {
    render(FacetGroup, {
      field: 'type_s',
      label: 'Type',
      counts: [{ value: 'Rare', count: null }],
      selected: ['Rare'],
      onToggle: vi.fn(),
    });
    const option = screen.getByRole('checkbox', { name: /Rare/ }).closest('label');
    expect(option?.textContent?.replace(/\s+/g, ' ').trim()).toBe('Rare');
  });
});

describe('search block history', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/page');
    searchSpy.mockReset();
    searchSpy.mockResolvedValue(response());
    try {
      localStorage.clear();
    } catch {
      /* jsdom storage optional */
    }
  });

  it('ignores a popstate that does not change its state', async () => {
    render(App, { bootstrap: bootstrap() });
    await screen.findByText('Item 1');
    const before = searchSpy.mock.calls.length;
    window.history.pushState({}, '', '/page#footnote');
    window.dispatchEvent(new PopStateEvent('popstate'));
    await new Promise((r) => setTimeout(r, 50));
    expect(searchSpy.mock.calls.length).toBe(before);
  });

  it('returns to the list view when Back leaves a URL without a view', async () => {
    render(App, { bootstrap: bootstrap() });
    await screen.findByText('Item 1');
    window.history.pushState({}, '', '/page?b7.view=gallery');
    window.dispatchEvent(new PopStateEvent('popstate'));
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /Grid/ })).toHaveAttribute('aria-pressed', 'true'),
    );
    window.history.pushState({}, '', '/page');
    window.dispatchEvent(new PopStateEvent('popstate'));
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /List/ })).toHaveAttribute('aria-pressed', 'true'),
    );
  });

  it('never sends a sort this corpus does not offer', async () => {
    render(App, { bootstrap: bootstrap() });
    await screen.findByText('Item 1');
    window.history.pushState({}, '', '/page?b7.sort=bogus&b7.page=1');
    window.dispatchEvent(new PopStateEvent('popstate'));
    await new Promise((r) => setTimeout(r, 50));
    for (const call of searchSpy.mock.calls) {
      expect((call[0] as { sort: string }).sort).not.toBe('bogus');
    }
  });
});

describe('asynchronous states', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/page?b7.sort=title');
    searchSpy.mockReset();
  });

  it('shows a translated failure with Try again, and keeps the detail in the console', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    searchSpy.mockRejectedValueOnce(new Error('Search failed (HTTP 502) [req-123]'));
    searchSpy.mockResolvedValue(response());
    render(App, { bootstrap: { ...bootstrap(), initial_response: undefined } });

    const retry = await screen.findByRole('button', { name: 'Try again' });
    expect(
      screen.getByText('Search is temporarily unavailable.', { selector: 'strong' }),
    ).toBeTruthy();
    expect(document.body.textContent).not.toContain('HTTP 502');
    expect(document.body.textContent).not.toContain('req-123');
    expect(consoleError).toHaveBeenCalled();

    await fireEvent.click(retry);
    await screen.findByText('Item 1');
    expect(searchSpy).toHaveBeenCalledTimes(2);
    expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull();
    consoleError.mockRestore();
  });

  it('keeps one persistent status node and an aria-hidden skeleton', async () => {
    let resolve: (value: SearchResponse) => void = () => undefined;
    searchSpy.mockReturnValue(new Promise<SearchResponse>((r) => (resolve = r)));
    const { container } = render(App, {
      bootstrap: { ...bootstrap(), initial_response: undefined },
    });
    await waitFor(() => expect(container.querySelector('.dre-skeletons')).toBeTruthy());
    expect(container.querySelector('.dre-skeletons')).toHaveAttribute('aria-hidden', 'true');
    expect(container.querySelectorAll('[role="status"]')).toHaveLength(1);
    expect(screen.getByRole('status').textContent?.trim()).toBe('Loading…');
    expect(container.querySelector('.dre-search__results')).toHaveAttribute('aria-busy', 'true');

    // An empty corpus with no query or filter: nothing was searched for.
    resolve(response(0));
    await waitFor(() =>
      expect(screen.getByRole('status').textContent?.trim()).toBe('Nothing to show yet.'),
    );
    expect(container.querySelectorAll('[role="status"]')).toHaveLength(1);
    expect(container.querySelector('.dre-search__results')).toHaveAttribute('aria-busy', 'false');
  });
});

describe('recent searches', () => {
  it('keeps the longest form of a query typed in pauses', () => {
    try {
      localStorage.clear();
    } catch {
      return;
    }
    rememberSearch('col');
    rememberSearch('colon');
    rememberSearch('colonial');
    rememberSearch('kenya');
    expect(recentSearches()).toEqual(['kenya', 'colonial']);
  });
});
