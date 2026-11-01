import { describe, expect, it, vi } from 'vitest';
import {
  readUnionPage,
  readUrlState,
  createTypingBurst,
  syncToUrl,
  syncUnionPage,
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
   * The search box commits every 250 ms. Pushing on each query change buried
   * the page the visitor came from under one entry per typing pause; replacing
   * on each left no step at all. A typing burst is one step.
   */
  describe('history steps', () => {
    function record(prev: UrlSearchState | null, next: UrlSearchState, continuesBurst = false) {
      const push = vi.spyOn(window.history, 'pushState').mockImplementation(() => undefined);
      const replace = vi.spyOn(window.history, 'replaceState').mockImplementation(() => undefined);
      syncToUrl(next, prev, {}, '/search', continuesBurst);
      const calls = { push: push.mock.calls.length, replace: replace.mock.calls.length };
      push.mockRestore();
      replace.mockRestore();
      return calls;
    }

    it('pushes the first typed query of a burst and replaces the rest', () => {
      const first = record({ ...pristine }, { ...pristine, q: 'isla' });
      expect(first).toEqual({ push: 1, replace: 0 });
      const typed = record({ ...pristine, q: 'isla' }, { ...pristine, q: 'islam' }, true);
      expect(typed).toEqual({ push: 0, replace: 1 });
    });

    it('pushes a query that was not typed', () => {
      const picked = record({ ...pristine, q: 'islam' }, { ...pristine, q: 'kenya' });
      expect(picked).toEqual({ push: 1, replace: 0 });
    });

    it('leaves the query to the federated shell when it does not own it', () => {
      const shared = vi.spyOn(window.history, 'pushState').mockImplementation(() => undefined);
      const replace = vi.spyOn(window.history, 'replaceState').mockImplementation(() => undefined);
      syncToUrl(
        { ...pristine, q: 'kenya', page: 2 },
        { ...pristine, q: 'islam' },
        { includeQuery: false },
        '/search',
      );
      expect(shared).not.toHaveBeenCalled();
      expect(replace).toHaveBeenCalledOnce();
      shared.mockRestore();
      replace.mockRestore();
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

describe('typing bursts', () => {
  it('opens a burst on the first commit and continues it while commits keep coming', () => {
    const burst = createTypingBurst(1500);
    expect(burst.typed(0)).toBe(false);
    expect(burst.typed(800)).toBe(true);
    expect(burst.typed(2000)).toBe(true);
  });

  it('starts a new burst after an idle pause or an explicit end', () => {
    const burst = createTypingBurst(1500);
    burst.typed(0);
    expect(burst.typed(1500)).toBe(false);
    expect(burst.typed(1600)).toBe(true);
    burst.end();
    expect(burst.typed(1700)).toBe(false);
  });
});

describe('view and the All tab in the URL', () => {
  it('round-trips prefixed gallery state', () => {
    const search = writeUrlState(
      {
        q: '',
        page: 1,
        sort: 'relevance',
        filters: {},
        yearFrom: null,
        yearTo: null,
        view: 'gallery',
      },
      { prefix: 'b42.' },
    );
    expect(search).toBe('?b42.view=gallery');
    expect(readUrlState(`https://example.test/${search}`, { prefix: 'b42.' }).view).toBe('gallery');
  });

  it('round-trips through the bare page key and clamps junk', () => {
    window.history.replaceState({}, '', '/s/amira/search?q=x&profile=all');
    syncUnionPage(3);
    expect(window.location.search).toBe('?q=x&profile=all&page=3');
    expect(readUnionPage()).toBe(3);
    syncUnionPage(1);
    expect(window.location.search).toBe('?q=x&profile=all');
    expect(readUnionPage('https://x.test/?page=-4')).toBe(1);
    expect(readUnionPage('https://x.test/?page=99999')).toBe(250);
  });
});
