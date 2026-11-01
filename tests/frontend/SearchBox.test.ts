import { fireEvent, render, screen } from '@testing-library/svelte';
import axe from 'axe-core';
import { tick } from 'svelte';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SearchBox from '../../src/svelte/components/SearchBox.svelte';
import type { SearchApi } from '../../src/svelte/lib/api';
import { rememberSearch } from '../../src/svelte/lib/searchHistory';

const api = {
  suggest: vi.fn().mockResolvedValue([]),
  popular: vi.fn().mockResolvedValue([]),
} as unknown as SearchApi;

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

afterEach(() => {
  window.localStorage.clear();
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
    // A pick, not typing: its own history step.
    expect(onQueryChange).toHaveBeenCalledWith('ivory coast', false);
    expect(input.value).toBe('ivory coast');
  });

  it('shows nothing when the instance has no popular searches', async () => {
    const { input } = box([]);
    await fireEvent.focus(input);
    await tick();
    expect(screen.queryByRole('listbox')).toBeNull();
  });
});
