import { fireEvent, render, screen, waitFor } from '@testing-library/svelte';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../../src/svelte/App.svelte';
import CiteMenu from '../../src/svelte/components/CiteMenu.svelte';
import CopyLinkButton from '../../src/svelte/components/CopyLinkButton.svelte';
import type { Bootstrap, SearchResponse } from '../../src/svelte/lib/types';

// The shared widgets of DRE-theme's integration contract ("Shared widgets"):
// disclosure popovers, copy feedback and the heading levels inside a block.

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
}));

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

function response(): SearchResponse {
  return {
    available: true,
    found: 1,
    page: 1,
    hits: [{ id: '1', title: 'Item 1' }],
    facets: [],
  } as SearchResponse;
}

function bootstrap(): Bootstrap {
  return {
    block_id: 9,
    profile: 'research_items',
    card_kind: 'item',
    date_mode: 'single',
    show_year: false,
    year_bounds: null,
    facets: ['type_s'],
    facet_labels: { type_s: 'Type' },
    default_sort: 'relevance',
    sort_options: [{ value: 'relevance', label: 'Relevance' }],
    per_page: 20,
    item_url_base: '/s/site/item',
    endpoints: { search: '/s', export: '/e', suggest: '/g', map: '/m' },
    initial_response: response(),
    initial_query: '',
  } as Bootstrap;
}

const citeProps = {
  doc: { id: '7', title: 'Swahili poetry', year: 2014 },
  kind: 'publication' as const,
  itemUrlBase: 'https://example.org/s/amira/item',
};

afterEach(() => {
  delete (window as unknown as { DREUtils?: unknown }).DREUtils;
  vi.unstubAllGlobals();
});

describe('disclosure popovers', () => {
  it('close on Escape and hand focus back to their summary', async () => {
    const { container } = render(CiteMenu, citeProps);
    const details = container.querySelector('details')!;
    const summary = container.querySelector('summary')!;
    details.open = true;
    details.dispatchEvent(new Event('toggle'));
    screen.getByRole('button', { name: 'Copy BibTeX' }).focus();

    await fireEvent.keyDown(window, { key: 'Escape' });
    expect(details.open).toBe(false);
    expect(document.activeElement).toBe(summary);
  });

  it('close on a press outside without moving focus', async () => {
    const outside = document.createElement('button');
    document.body.append(outside);
    const { container } = render(CiteMenu, citeProps);
    const details = container.querySelector('details')!;
    details.open = true;
    details.dispatchEvent(new Event('toggle'));
    outside.focus();

    await fireEvent.pointerDown(outside);
    expect(details.open).toBe(false);
    expect(document.activeElement).toBe(outside);
    outside.remove();
  });
});

describe('copy feedback', () => {
  it('reads "Copied" for two seconds and speaks through DREUtils when the theme is there', async () => {
    const announce = vi.fn();
    (window as unknown as { DREUtils: unknown }).DREUtils = { announce };
    vi.stubGlobal('navigator', {
      ...navigator,
      clipboard: { writeText: vi.fn().mockResolvedValue(undefined) },
    });
    const timeout = vi.spyOn(window, 'setTimeout');
    render(CopyLinkButton);

    await fireEvent.click(screen.getByRole('button', { name: 'Copy link' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Copied' })).toBeTruthy());
    expect(announce).toHaveBeenCalledWith('Copied');
    // The theme's region speaks: the button's own fallback node stays empty.
    expect(screen.queryByRole('status')?.textContent ?? '').toBe('');
    expect(timeout).toHaveBeenCalledWith(expect.any(Function), 2000);
    timeout.mockRestore();
  });
});

describe('heading levels', () => {
  it('nests the inner headings under a titled block', () => {
    render(App, { bootstrap: bootstrap(), headingLevel: 3 });
    expect(screen.getByRole('heading', { level: 3, name: 'Search results' })).toBeTruthy();
    expect(screen.getByRole('heading', { level: 3, name: 'Filters' })).toBeTruthy();
    expect(screen.getByRole('heading', { level: 4, name: 'Item 1' })).toBeTruthy();
    expect(screen.queryByRole('heading', { level: 2 })).toBeNull();
  });

  it('keeps them at h2 without a block title', () => {
    render(App, { bootstrap: bootstrap() });
    expect(screen.getByRole('heading', { level: 2, name: 'Search results' })).toBeTruthy();
    expect(screen.getByRole('heading', { level: 2, name: 'Filters' })).toBeTruthy();
    expect(screen.getByRole('heading', { level: 3, name: 'Item 1' })).toBeTruthy();
  });

  it('speaks a widget message through the surface status node without the theme', async () => {
    vi.stubGlobal('navigator', {
      ...navigator,
      clipboard: { writeText: vi.fn().mockResolvedValue(undefined) },
    });
    const { container } = render(App, { bootstrap: bootstrap() });
    await fireEvent.click(screen.getByRole('button', { name: 'Copy link' }));
    await waitFor(() => expect(screen.getByRole('status').textContent?.trim()).toBe('Copied'));
    expect(container.querySelectorAll('[role="status"]')).toHaveLength(1);
  });
});
