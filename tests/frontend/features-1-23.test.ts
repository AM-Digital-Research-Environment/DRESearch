import { fireEvent, render, screen, waitFor } from '@testing-library/svelte';
import axe from 'axe-core';
import { tick } from 'svelte';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CiteMenu from '../../src/svelte/components/CiteMenu.svelte';
import FederatedApp from '../../src/svelte/components/FederatedApp.svelte';
import RecordLink from '../../src/svelte/components/RecordLink.svelte';
import SearchBox from '../../src/svelte/components/SearchBox.svelte';
import YearRangeFacet from '../../src/svelte/components/YearRangeFacet.svelte';
import type { SearchApi } from '../../src/svelte/lib/api';
import { searchAll, searchUnion } from '../../src/svelte/lib/api';
import { rememberSearch } from '../../src/svelte/lib/searchHistory';
import { readUnionPage, syncUnionPage } from '../../src/svelte/lib/urlState';

vi.mock('../../src/svelte/lib/api', () => ({
  searchAll: vi.fn(),
  searchUnion: vi.fn(),
  SearchApi: class {},
}));

afterEach(() => {
  window.history.replaceState({}, '', '/');
  window.localStorage.clear();
  vi.unstubAllGlobals();
});

describe('popular searches in the search box', () => {
  const box = (popular: string[]) => {
    const onQueryChange = vi.fn();
    const api = {
      suggest: vi.fn().mockResolvedValue([]),
      popular: vi.fn().mockResolvedValue(popular),
    } as unknown as SearchApi;
    render(SearchBox, {
      value: '',
      placeholder: 'Search',
      api,
      itemUrlBase: '/s/site/item',
      instanceId: 'popular',
      onQueryChange,
    });
    return { input: screen.getByRole('combobox') as HTMLInputElement, onQueryChange };
  };

  it('lists recent then popular searches in labelled groups, without repeats', async () => {
    rememberSearch('Islam');
    const { input } = box(['islam', 'ivory coast']);
    await fireEvent.focus(input);
    const popular = await screen.findByRole('group', { name: 'Popular searches' });
    expect(screen.getByRole('group', { name: 'Recent searches' })).toBeTruthy();
    expect(popular.querySelectorAll('[role="option"]')).toHaveLength(1);
    expect(screen.getAllByRole('option').map((o) => o.textContent?.trim())).toEqual([
      'Islam',
      'ivory coast',
    ]);
    const result = await axe.run(document.body, { rules: { region: { enabled: false } } });
    expect(result.violations).toEqual([]);
  });

  it('runs a popular search from the keyboard', async () => {
    const { input, onQueryChange } = box(['ivory coast']);
    await fireEvent.focus(input);
    await screen.findByRole('option', { name: 'ivory coast' });
    await fireEvent.keyDown(input, { key: 'ArrowDown' });
    await fireEvent.keyDown(input, { key: 'Enter' });
    expect(onQueryChange).toHaveBeenCalledWith('ivory coast');
    expect(input.value).toBe('ivory coast');
  });

  it('shows nothing when the instance has no popular searches', async () => {
    const { input } = box([]);
    await fireEvent.focus(input);
    await tick();
    expect(screen.queryByRole('listbox')).toBeNull();
  });
});

describe('typed years', () => {
  const facet = () => {
    const onChange = vi.fn();
    render(YearRangeFacet, { min: 1500, max: 2026, from: 1500, to: 2026, onChange });
    return {
      from: screen.getByRole('spinbutton', { name: 'From' }) as HTMLInputElement,
      to: screen.getByRole('spinbutton', { name: 'To' }) as HTMLInputElement,
      onChange,
    };
  };

  it('commits a typed year at once', async () => {
    const { from, onChange } = facet();
    await fireEvent.change(from, { target: { value: '1990' } });
    expect(onChange).toHaveBeenCalledWith(1990, 2026);
  });

  it('clamps to the bounds and keeps the range in order', async () => {
    const { from, to, onChange } = facet();
    await fireEvent.change(to, { target: { value: '3000' } });
    expect(onChange).toHaveBeenLastCalledWith(1500, 2026);
    await fireEvent.change(to, { target: { value: '1900' } });
    await fireEvent.change(from, { target: { value: '1950' } });
    expect(onChange).toHaveBeenLastCalledWith(1900, 1900);
    expect(from.value).toBe('1900');
  });

  it('restores the current year for an empty entry without searching', async () => {
    const { from, onChange } = facet();
    await fireEvent.change(from, { target: { value: '' } });
    expect(onChange).not.toHaveBeenCalled();
    expect(from.value).toBe('1500');
  });
});

describe('record links and citations', () => {
  it('links to the record with a named target, or renders nothing', () => {
    const { container } = render(RecordLink, {
      itemUrlBase: '/s/amira/item',
      id: '42',
      name: 'Vierke, Ulf',
    });
    const link = screen.getByRole('link', { name: 'Open Vierke, Ulf' });
    expect(link).toHaveAttribute('href', '/s/amira/item/42');
    render(RecordLink, { itemUrlBase: '/s/amira/item', id: undefined, name: 'Nobody' });
    expect(container.querySelectorAll('a')).toHaveLength(1);
  });

  it('copies one record as BibTeX and announces it', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } });
    render(CiteMenu, {
      doc: {
        id: '7',
        title: 'Swahili poetry',
        creator_ss: ['Vierke, Clarissa'],
        year: 2014,
      },
      kind: 'publication',
      itemUrlBase: 'https://example.org/s/amira/item',
    });
    await fireEvent.click(screen.getByRole('button', { name: 'Copy BibTeX' }));
    await waitFor(() => expect(screen.getByRole('status').textContent).toBe('Copied'));
    const bibtex = writeText.mock.calls[0]?.[0] as string;
    expect(bibtex).toMatch(/^@\w+\{/);
    expect(bibtex).toContain('Swahili poetry');
    expect(bibtex).toContain('https://example.org/s/amira/item/7');
    await fireEvent.click(screen.getByRole('button', { name: 'Copy RIS' }));
    await waitFor(() => expect(writeText).toHaveBeenCalledTimes(2));
    expect(writeText.mock.calls[1]?.[0]).toMatch(/\r\nER {2}- \r\n$/);
  });
});

describe('the All results page in the URL', () => {
  it('round-trips through the bare page key and clamps junk', () => {
    window.history.replaceState({}, '', '/s/amira/search?q=x&profile=all');
    syncUnionPage(3);
    expect(window.location.search).toBe('?q=x&profile=all&page=3');
    expect(readUnionPage()).toBe(3);
    syncUnionPage(1);
    expect(window.location.search).toBe('?q=x&profile=all');
    expect(readUnionPage('https://x.test/?page=-4')).toBe(1);
    expect(readUnionPage('https://x.test/?page=99999')).toBe(250);
  });

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
