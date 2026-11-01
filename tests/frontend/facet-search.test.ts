import { fireEvent, render, screen, waitFor } from '@testing-library/svelte';
import { describe, expect, it, vi } from 'vitest';
import FacetGroup from '../../src/svelte/components/FacetGroup.svelte';

it('finds a remote value outside the downloaded counts and cancels obsolete requests', async () => {
  const pending: {
    query: string;
    signal: AbortSignal;
    resolve: (values: { value: string; count: number }[]) => void;
  }[] = [];
  const search = vi.fn(
    (_field: string, query: string, signal: AbortSignal) =>
      new Promise<{ value: string; count: number }[]>((resolve) =>
        pending.push({ query, signal, resolve }),
      ),
  );
  const toggle = vi.fn();
  render(FacetGroup, {
    field: 'subject_ss',
    label: 'Subjects',
    counts: Array.from({ length: 100 }, (_, i) => ({ value: `Subject ${i}`, count: 5 })),
    selected: [],
    searchValues: search,
    onToggle: toggle,
  });
  const input = screen.getByRole('searchbox');
  await fireEvent.input(input, { target: { value: 'old' } });
  await waitFor(() => expect(pending).toHaveLength(1));
  await fireEvent.input(input, { target: { value: 'rare' } });
  await waitFor(() => expect(pending).toHaveLength(2));
  expect(pending[0]!.signal.aborted).toBe(true);
  pending[1]!.resolve([{ value: 'Rare archival topic', count: 1 }]);
  pending[0]!.resolve([{ value: 'Old result', count: 5 }]);
  const checkbox = await screen.findByRole('checkbox', { name: /Rare archival topic/ });
  expect(screen.queryByText('Old result')).toBeNull();
  await fireEvent.click(checkbox);
  expect(toggle).toHaveBeenCalledWith('subject_ss', 'Rare archival topic', true);
});

it('reports remote errors without calling them an empty result set', async () => {
  render(FacetGroup, {
    field: 'subject_ss',
    label: 'Subjects',
    counts: Array.from({ length: 9 }, (_, i) => ({ value: String(i), count: 1 })),
    selected: [],
    searchValues: vi.fn().mockRejectedValue(new Error('Unavailable')),
    onToggle: vi.fn(),
  });
  await fireEvent.input(screen.getByRole('searchbox'), { target: { value: 'rare' } });
  await screen.findByText('Could not load facet values. Edit your search to retry.');
});

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
