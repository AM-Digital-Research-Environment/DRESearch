import { fireEvent, render, screen, waitFor } from '@testing-library/svelte';
import { expect, it, vi } from 'vitest';
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
