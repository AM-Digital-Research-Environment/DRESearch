import { describe, expect, it } from 'vitest';
import { readUrlState, writeUrlState, type UrlSearchState } from '../../src/svelte/lib/urlState';

const pristine: UrlSearchState = {
  q: '',
  page: 1,
  sort: 'relevance',
  filters: {},
  yearFrom: null,
  yearTo: null,
};

describe('URL search state', () => {
  it('preserves commas inside repeated facet values', () => {
    const state = readUrlState(
      'https://example.test/search?f.creator_ss=Doe%2C+Jane&f.creator_ss=Smith%2C+John',
    );
    expect(state.filters.creator_ss).toEqual(['Doe, Jane', 'Smith, John']);
  });

  it('clamps pages to the public API limit', () => {
    expect(readUrlState('https://example.test/?page=999999').page).toBe(250);
  });

  it('rewrites only the owning block namespace', () => {
    const query = writeUrlState(
      { ...pristine, q: 'archive', filters: { subject_ss: ['Islam'] } },
      { prefix: 'b42.' },
      '?tracking=kept&b7.q=other&b42.q=old',
    );
    const params = new URLSearchParams(query);
    expect(params.get('tracking')).toBe('kept');
    expect(params.get('b7.q')).toBe('other');
    expect(params.get('b42.q')).toBe('archive');
    expect(params.getAll('b42.f.subject_ss')).toEqual(['Islam']);
  });
});
