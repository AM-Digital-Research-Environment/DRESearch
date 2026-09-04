import { fireEvent, render } from '@testing-library/svelte';
import axe from 'axe-core';
import { tick } from 'svelte';
import { describe, expect, it, vi } from 'vitest';
import SearchBox from '../../src/svelte/components/SearchBox.svelte';
import type { SearchApi } from '../../src/svelte/lib/api';

const api = { suggest: vi.fn().mockResolvedValue([]) } as unknown as SearchApi;

describe('SearchBox', () => {
  it('uses instance-scoped combobox/listbox ids and has no detectable axe violations', async () => {
    render(SearchBox, {
      value: '',
      placeholder: 'Search',
      api,
      itemUrlBase: '/s/site/item',
      instanceId: 'block-42',
      onQueryChange: vi.fn(),
    });
    const input = document.querySelector('input[role="combobox"]');
    expect(input).toHaveAttribute('aria-controls', 'dre-suggest-block-42');
    const result = await axe.run(document.body, { rules: { region: { enabled: false } } });
    expect(result.violations).toEqual([]);
  });

  /*
   * The parent owns `query` but only learns of a keystroke 250 ms later, so
   * `value` is a debounce behind the field for the whole time someone is typing.
   * A prop→local sync that also depends on `local` re-runs on every keystroke
   * and rewinds the field to that stale value — typing "islam" used to leave
   * a stray character or nothing at all.
   */
  it('keeps every typed character while the parent value prop lags behind', async () => {
    render(SearchBox, {
      value: '',
      placeholder: 'Search',
      api,
      itemUrlBase: '/s/site/item',
      instanceId: 'lagging',
      onQueryChange: vi.fn(),
    });
    const input = document.querySelector('input[role="combobox"]') as HTMLInputElement;
    for (const char of 'islam') {
      await fireEvent.input(input, { target: { value: input.value + char } });
      await tick();
    }
    expect(input.value).toBe('islam');
  });

  it('still accepts a query pushed in from outside the box', async () => {
    const { rerender } = render(SearchBox, {
      value: 'islam',
      placeholder: 'Search',
      api,
      itemUrlBase: '/s/site/item',
      instanceId: 'outside',
      onQueryChange: vi.fn(),
    });
    const input = document.querySelector('input[role="combobox"]') as HTMLInputElement;
    expect(input.value).toBe('islam');
    // e.g. the query chip being removed, or a back navigation.
    await rerender({ value: '' });
    await tick();
    expect(input.value).toBe('');
  });
});
