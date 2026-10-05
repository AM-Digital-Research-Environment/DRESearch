import { fireEvent, render, screen, waitFor } from '@testing-library/svelte';
import { expect, it, vi } from 'vitest';
import FederatedApp from '../../src/svelte/components/FederatedApp.svelte';
import type { SearchAllResponse } from '../../src/svelte/lib/types';
import { searchAll } from '../../src/svelte/lib/api';

vi.mock('../../src/svelte/lib/api', () => ({
  searchAll: vi.fn(),
  searchUnion: vi.fn(),
  SearchApi: class {},
}));

it('ignores a pending response after returning to a cached tab', async () => {
  vi.stubGlobal('matchMedia', () => ({
    matches: false,
    addEventListener() {},
    removeEventListener() {},
  }));
  window.history.replaceState({}, '', '/');
  const response = (title: string) => ({
    available: true,
    found: 1,
    page: 1,
    per_page: 20,
    facets: [],
    hits: [{ id: '1', title, is_public: true }],
  });
  let resolveSecond!: (data: SearchAllResponse) => void;
  vi.mocked(searchAll).mockImplementation(async (_url, request) => {
    if (request.profile === 'first')
      return {
        available: true,
        counts: { first: 1, second: 1 },
        active: response('First corpus document'),
      };
    return new Promise((resolve) => {
      resolveSecond = resolve;
    });
  });
  render(FederatedApp, {
    bootstrap: {
      variant: 'federated',
      available: true,
      item_url_base: '/items',
      initial_query: '',
      default_profile: 'first',
      profiles: ['first', 'second'].map((name) => ({
        name,
        label: name,
        kind: 'item',
        date_mode: 'none',
        show_year: false,
        year_bounds: null,
        facets: [],
        facet_labels: {},
        default_sort: 'relevance',
        sort_options: [{ value: 'relevance', label: 'Relevance' }],
        per_page: 20,
      })),
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
  await waitFor(() => expect(screen.getByText('First corpus document')).toBeTruthy());
  const chooser = screen.getByRole('combobox', { name: 'Result types' });
  await fireEvent.change(chooser, { target: { value: 'second' } });
  await waitFor(() => expect(resolveSecond).toBeTypeOf('function'));
  await fireEvent.change(chooser, { target: { value: 'first' } });
  resolveSecond({ available: true, counts: {}, active: response('Second corpus document') });
  await waitFor(() => expect(screen.getByText('First corpus document')).toBeTruthy());
  expect(screen.getByRole('tabpanel')).toHaveAttribute('aria-label', 'first');
  expect(screen.queryByText('Second corpus document')).toBeNull();
  expect(screen.queryByRole('status', { name: 'Loading results' })).toBeNull();
  vi.unstubAllGlobals();
});
