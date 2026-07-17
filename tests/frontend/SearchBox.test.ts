import { render } from '@testing-library/svelte';
import axe from 'axe-core';
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
});
