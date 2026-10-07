import { fireEvent, render, screen } from '@testing-library/svelte';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import Pagination from '../../src/svelte/components/Pagination.svelte';
import ResultsList from '../../src/svelte/components/ResultsList.svelte';
import { MAX_PAGE } from '../../src/svelte/lib/urlState';

const components = join(process.cwd(), 'src', 'svelte', 'components');

describe('the results pager', () => {
  it('is one component: no other component draws a pager of its own', () => {
    const drawers = readdirSync(components)
      .filter((name) => name.endsWith('.svelte'))
      .filter((name) => readFileSync(join(components, name), 'utf8').includes('class="dre-pager"'));
    expect(drawers).toEqual(['Pagination.svelte']);
  });

  it('clamps to the deepest page the server serves', async () => {
    const onPageChange = vi.fn();
    render(Pagination, { found: 1_000_000, page: MAX_PAGE, perPage: 20, onPageChange });
    expect(screen.getByRole('button', { current: 'page' }).textContent).toBe(String(MAX_PAGE));
    expect(screen.queryByRole('button', { name: String(MAX_PAGE + 1) })).toBeNull();
    const next = screen.getByRole('button', { name: 'Next page' });
    expect(next).toHaveProperty('disabled', true);
    await fireEvent.click(screen.getByRole('button', { name: '1' }));
    expect(onPageChange).toHaveBeenCalledWith(1);
  });

  it('renders under a search block’s results', () => {
    render(ResultsList, {
      hits: [],
      found: 45,
      page: 2,
      perPage: 20,
      itemUrlBase: '/s/site/item',
      cardKind: 'item',
      onPageChange: vi.fn(),
      onAddFilter: vi.fn(),
    });
    expect(screen.getByRole('navigation', { name: 'Pagination' })).toBeTruthy();
    expect(screen.getByRole('button', { current: 'page' }).textContent).toBe('2');
  });
});
