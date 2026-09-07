import { fireEvent, render, screen, waitFor } from '@testing-library/svelte';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import FederatedApp from '../../src/svelte/components/FederatedApp.svelte';
import { searchAll, searchUnion } from '../../src/svelte/lib/api';
import type { FederatedBootstrap } from '../../src/svelte/lib/types';

vi.mock('../../src/svelte/lib/api', () => ({
  searchAll: vi.fn(),
  searchUnion: vi.fn(),
  SearchApi: class {},
}));

const bootstrap: FederatedBootstrap = {
  variant: 'federated',
  available: true,
  item_url_base: '/items',
  initial_query: '',
  default_profile: 'all',
  profiles: ['people', 'locations'].map((name) => ({
    name,
    label: name,
    kind: 'item',
    date_mode: 'single',
    show_year: false,
    year_bounds: null,
    facets: [],
    facet_labels: {},
    default_sort: 'relevance',
    sort_options: [],
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
};

describe('federated corpus chooser', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/');
    vi.mocked(searchUnion).mockResolvedValue({
      available: true,
      found: 0,
      hits: [],
      page: 1,
      per_page: 20,
    } as never);
    vi.mocked(searchAll).mockResolvedValue({
      counts: { people: 12, locations: 5 },
      active: null,
    } as never);
  });

  it('retains corpus counts and changes the active search and panel label', async () => {
    render(FederatedApp, { bootstrap });
    const chooser = screen.getByRole('combobox', { name: 'Result types' });
    await waitFor(() => expect(screen.getByRole('option', { name: 'locations (5)' })).toBeTruthy());
    await fireEvent.change(chooser, { target: { value: 'locations' } });
    await waitFor(() =>
      expect(searchAll).toHaveBeenLastCalledWith(
        '/all',
        expect.objectContaining({ profile: 'locations' }),
        expect.any(AbortSignal),
      ),
    );
    expect(screen.getByRole('tabpanel')).toHaveAttribute('aria-label', 'locations');
    expect(window.location.search).toContain('profile=locations');
  });
});
