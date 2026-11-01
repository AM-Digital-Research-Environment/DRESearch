import { fireEvent, render, screen } from '@testing-library/svelte';
import { afterEach, describe, expect, it, vi } from 'vitest';
import FederatedApp from '../../src/svelte/components/FederatedApp.svelte';
import { searchAll, searchUnion } from '../../src/svelte/lib/api';

vi.mock('../../src/svelte/lib/api', () => ({
  searchAll: vi.fn(),
  searchUnion: vi.fn(),
  SearchApi: class {},
}));

afterEach(() => {
  window.history.replaceState({}, '', '/');
  vi.unstubAllGlobals();
});

describe('the All results page in the URL', () => {
  const union = (page: number, found: number) => ({
    available: true,
    found,
    page,
    per_page: 20,
    facets: [],
    hits: [{ id: `${page}`, title: `Hit on page ${page}`, is_public: true, _profile: 'first' }],
  });

  const mount = () =>
    render(FederatedApp, {
      bootstrap: {
        variant: 'federated',
        available: true,
        item_url_base: '/items',
        initial_query: '',
        default_profile: 'first',
        profiles: [
          {
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
          },
        ],
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

  it('opens a shared link on its page and records paging', async () => {
    // jsdom has no layout: the pager scrolls the panel into view.
    Object.defineProperty(Element.prototype, 'scrollIntoView', {
      value: vi.fn(),
      configurable: true,
      writable: true,
    });
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener() {},
      removeEventListener() {},
    }));
    window.history.replaceState({}, '', '/?q=islam&profile=all&page=3');
    vi.mocked(searchAll).mockResolvedValue({ available: true, counts: {}, active: union(1, 0) });
    vi.mocked(searchUnion).mockImplementation(async (_url, req) => union(req.page ?? 1, 100));
    mount();
    await screen.findByText('Hit on page 3');
    expect(vi.mocked(searchUnion).mock.calls[0]?.[1]).toMatchObject({ q: 'islam', page: 3 });
    await fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    await screen.findByText('Hit on page 4');
    expect(new URL(window.location.href).searchParams.get('page')).toBe('4');
  });

  it('lands a link past the end on the last page', async () => {
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener() {},
      removeEventListener() {},
    }));
    vi.mocked(searchUnion).mockReset();
    window.history.replaceState({}, '', '/?q=islam&profile=all&page=9');
    vi.mocked(searchAll).mockResolvedValue({ available: true, counts: {}, active: union(1, 0) });
    vi.mocked(searchUnion).mockImplementation(async (_url, req) => union(req.page ?? 1, 30));
    mount();
    await screen.findByText('Hit on page 2');
    expect(new URL(window.location.href).searchParams.get('page')).toBe('2');
  });
});
