import { describe, expect, it } from 'vitest';
import { rememberSearch, recentSearches } from '../../src/svelte/lib/searchHistory';

describe('recent searches', () => {
  it('keeps the longest form of a query typed in pauses', () => {
    try {
      localStorage.clear();
    } catch {
      return;
    }
    rememberSearch('col');
    rememberSearch('colon');
    rememberSearch('colonial');
    rememberSearch('kenya');
    expect(recentSearches()).toEqual(['kenya', 'colonial']);
  });
});
