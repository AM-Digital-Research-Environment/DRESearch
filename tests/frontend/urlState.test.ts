import { describe, expect, it, vi } from 'vitest';
import {
  readUrlState,
  syncToUrl,
  writeUrlState,
  type UrlSearchState,
} from '../../src/svelte/lib/urlState';

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

  /*
   * The search box commits every 250 ms, so pushing on each query change buried
   * the page the visitor came from under one history entry per typing pause.
   */
  describe('history steps', () => {
    function record(prev: UrlSearchState | null, next: UrlSearchState) {
      const push = vi.spyOn(window.history, 'pushState').mockImplementation(() => undefined);
      const replace = vi.spyOn(window.history, 'replaceState').mockImplementation(() => undefined);
      syncToUrl(next, prev, {}, '/search');
      const calls = { push: push.mock.calls.length, replace: replace.mock.calls.length };
      push.mockRestore();
      replace.mockRestore();
      return calls;
    }

    it('replaces history while the query is being typed', () => {
      const typed = record({ ...pristine, q: 'isla' }, { ...pristine, q: 'islam' });
      expect(typed).toEqual({ push: 0, replace: 1 });
      const first = record({ ...pristine }, { ...pristine, q: 'islam' });
      expect(first).toEqual({ push: 0, replace: 1 });
    });

    it('replaces history when only the page changed', () => {
      const paged = record({ ...pristine, q: 'islam' }, { ...pristine, q: 'islam', page: 3 });
      expect(paged).toEqual({ push: 0, replace: 1 });
    });

    it('pushes a step when the scope changes', () => {
      const faceted = record(
        { ...pristine, q: 'islam' },
        { ...pristine, q: 'islam', filters: { subject_ss: ['Islam'] } },
      );
      expect(faceted).toEqual({ push: 1, replace: 0 });
      const sorted = record({ ...pristine }, { ...pristine, sort: 'newest' });
      expect(sorted).toEqual({ push: 1, replace: 0 });
      const scoped = record({ ...pristine }, { ...pristine, yearFrom: 1990 });
      expect(scoped).toEqual({ push: 1, replace: 0 });
    });

    it('synchronises rather than navigates on the first call after mount', () => {
      expect(record(null, { ...pristine, q: 'islam', sort: 'newest' })).toEqual({
        push: 0,
        replace: 1,
      });
    });
  });
});
