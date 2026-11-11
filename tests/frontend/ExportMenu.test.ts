import { fireEvent, render, screen, waitFor } from '@testing-library/svelte';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ExportMenu from '../../src/svelte/components/ExportMenu.svelte';
import ViewToggle from '../../src/svelte/components/ViewToggle.svelte';
import type { ExportResponse } from '../../src/svelte/lib/types';

const download = vi.hoisted(() => vi.fn());
vi.mock('../../src/svelte/lib/export', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../src/svelte/lib/export')>()),
  download,
}));

function answer(overrides: Partial<ExportResponse> = {}): ExportResponse {
  const docs = [{ id: '7', title: 'Swahili poetry', year: 2014 }];
  return {
    available: true,
    found: 1,
    exported: docs.length,
    capped: false,
    complete: true,
    docs,
    ...overrides,
  };
}

function props(fetchDocs: () => Promise<ExportResponse>, found = 1) {
  return {
    fetchDocs,
    query: ' poetry ',
    found,
    kind: 'item' as const,
    itemUrlBase: 'https://example.org/s/amira/item',
    filters: { type_s: ['Text'] },
    yearFrom: 1990,
    yearTo: null,
    facetLabels: { type_s: 'Type' },
  };
}

afterEach(() => {
  download.mockReset();
  vi.restoreAllMocks();
});

describe('export menu', () => {
  it('is a disclosure: the trigger names and controls the panel, Escape closes it', async () => {
    render(
      ExportMenu,
      props(() => Promise.resolve(answer())),
    );
    const trigger = screen.getByRole('button', { name: 'Export results' });
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
    expect(trigger).not.toHaveAttribute('aria-controls');

    await fireEvent.click(trigger);
    expect(trigger).toHaveAttribute('aria-expanded', 'true');
    const panel = screen.getByRole('group', { name: 'Export results' });
    expect(trigger).toHaveAttribute('aria-controls', panel.id);
    expect(screen.getByRole('button', { name: 'BibTeX (.bib)' })).toBeInTheDocument();

    await fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() => expect(trigger).toHaveAttribute('aria-expanded', 'false'));
  });

  it('downloads the current set in the chosen format and closes', async () => {
    const fetchDocs = vi.fn(() => Promise.resolve(answer()));
    render(ExportMenu, props(fetchDocs));
    await fireEvent.click(screen.getByRole('button', { name: 'Export results' }));
    await fireEvent.click(screen.getByRole('button', { name: 'JSON (.json)' }));

    await waitFor(() => expect(download).toHaveBeenCalledTimes(1));
    expect(fetchDocs).toHaveBeenCalledTimes(1);
    const [filename, mime, content] = download.mock.calls[0] as [string, string, string];
    expect(filename).toMatch(/\.json$/);
    expect(mime).toContain('application/json');
    expect(content).toContain('Swahili poetry');
    // The header records the search it came from, the query trimmed.
    expect(JSON.parse(content)).toMatchObject({ year_from: 1990, total_found: 1, exported: 1 });
    expect(content).toContain('"poetry"');
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Export results' })).toHaveAttribute(
        'aria-expanded',
        'false',
      ),
    );
  });

  it('refuses an incomplete pull and says so, without a file', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    render(
      ExportMenu,
      props(() => Promise.resolve(answer({ complete: false }))),
    );
    await fireEvent.click(screen.getByRole('button', { name: 'Export results' }));
    await fireEvent.click(screen.getByRole('button', { name: 'BibTeX (.bib)' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The export could not be completed.',
    );
    expect(download).not.toHaveBeenCalled();
  });

  it('says when there is nothing to export', async () => {
    render(
      ExportMenu,
      props(() => Promise.resolve(answer({ docs: [], exported: 0, found: 0 }))),
    );
    await fireEvent.click(screen.getByRole('button', { name: 'Export results' }));
    await fireEvent.click(screen.getByRole('button', { name: 'BibTeX (.bib)' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('No results to export.');
    expect(download).not.toHaveBeenCalled();
  });

  it('warns, with a formatted number, when the set is larger than an export', async () => {
    render(
      ExportMenu,
      props(() => Promise.resolve(answer()), 5000),
    );
    await fireEvent.click(screen.getByRole('button', { name: 'Export results' }));
    expect(screen.getByText('Exports the first 1,000 results of the current set.')).toBeVisible();
  });
});

describe('view toggle', () => {
  it('presses the current view and reports a choice', async () => {
    const onChange = vi.fn();
    render(ViewToggle, { value: 'list', options: ['list', 'map'], onChange });
    expect(screen.getByRole('group', { name: 'View as' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'List' })).toHaveAttribute('aria-pressed', 'true');
    const map = screen.getByRole('button', { name: 'Map' });
    expect(map).toHaveAttribute('aria-pressed', 'false');
    await fireEvent.click(map);
    expect(onChange).toHaveBeenCalledWith('map');
    expect(screen.queryByRole('button', { name: 'Grid' })).toBeNull();
  });
});
