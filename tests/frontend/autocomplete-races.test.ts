import { cleanup, fireEvent, render } from '@testing-library/svelte';
import { tick } from 'svelte';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SearchBox from '../../src/svelte/components/SearchBox.svelte';
import SearchBar from '../../src/svelte/components/SearchBar.svelte';
import type { SearchApi } from '../../src/svelte/lib/api';
import type { Suggestion } from '../../src/svelte/lib/types';

const suggestAll = vi.hoisted(() => vi.fn());
vi.mock('../../src/svelte/lib/api', () => ({ suggestAll }));

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.clearAllMocks();
});

for (const component of ['box', 'bar']) {
  describe(`${component} autocomplete request races`, () => {
    function setup() {
      vi.useFakeTimers();
      const pending: Array<{ resolve: (items: Suggestion[]) => void; signal: AbortSignal }> = [];
      const suggest = vi.fn(
        (_q: string, signal: AbortSignal) =>
          new Promise<Suggestion[]>((resolve) => {
            pending.push({ resolve, signal });
          }),
      );
      if (component === 'box') {
        render(SearchBox, {
          value: '',
          placeholder: 'Search',
          api: { suggest } as unknown as SearchApi,
          itemUrlBase: '/s/site/item',
          instanceId: 'races',
          onQueryChange: vi.fn(),
        });
      } else {
        suggestAll.mockImplementation(async (_url: string, q: string, signal: AbortSignal) => {
          const suggestions = await suggest(q, signal);
          return [{ profile: 'items', label: 'Items', kind: 'item', suggestions }];
        });
        render(SearchBar, {
          bootstrap: {
            variant: 'bar',
            available: true,
            item_url_base: '/s/site/item',
            results_url: '/search',
            placeholder: 'Search',
            endpoints: { suggest_all: '/suggest-all' },
          },
        });
      }
      const input = document.querySelector('input[role="combobox"]') as HTMLInputElement;
      return { input, pending };
    }

    async function type(input: HTMLInputElement, value: string) {
      await fireEvent.focus(input);
      await fireEvent.input(input, { target: { value } });
      await vi.advanceTimersByTimeAsync(250);
      await tick();
    }

    it('does not restore suggestions after clear, even if the server ignores abort', async () => {
      const { input, pending } = setup();
      await type(input, 'old query');
      const clear = document.querySelector(
        'button[aria-label="Clear search"]',
      ) as HTMLButtonElement;
      await fireEvent.click(clear);
      expect(pending[0].signal.aborted).toBe(true);
      pending[0].resolve([{ id: '1', title: 'Obsolete result' }]);
      await vi.advanceTimersByTimeAsync(0);
      await tick();
      expect(input.value).toBe('');
      expect(input).toHaveAttribute('aria-expanded', 'false');
      expect(document.body.textContent).not.toContain('Obsolete result');
    });

    it('invalidates an in-flight request immediately when input becomes one character', async () => {
      const { input, pending } = setup();
      await type(input, 'old query');
      await fireEvent.input(input, { target: { value: 'x' } });
      expect(pending[0].signal.aborted).toBe(true);
      pending[0].resolve([{ id: '1', title: 'Obsolete result' }]);
      await vi.advanceTimersByTimeAsync(250);
      await tick();
      expect(input).toHaveAttribute('aria-expanded', 'false');
      expect(pending).toHaveLength(1);
    });

    it('keeps newer suggestions when an older request completes last', async () => {
      const { input, pending } = setup();
      await type(input, 'old query');
      await type(input, 'new query');
      pending[1].resolve([{ id: '2', title: 'Current result' }]);
      await vi.advanceTimersByTimeAsync(0);
      pending[0].resolve([{ id: '1', title: 'Obsolete result' }]);
      await vi.advanceTimersByTimeAsync(0);
      await tick();
      expect(document.body.textContent).toContain('Current result');
      expect(document.body.textContent).not.toContain('Obsolete result');
    });
  });
}
