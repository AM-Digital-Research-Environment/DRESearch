import { describe, expect, it } from 'vitest';
import {
  seedAnswersFirstSearch,
  usableInitialResponse,
  type SeedInput,
} from '../../src/svelte/lib/initialState';
import type { SearchResponse } from '../../src/svelte/lib/types';
import { readUrlState } from '../../src/svelte/lib/urlState';

/**
 * The first-fetch skip rule that used to sit inline in App.svelte: adopt the
 * server-rendered page only when it answers the state the surface starts on.
 */

const seed = { available: true, found: 2, page: 1, hits: [], facets: [] } as SearchResponse;

function input(over: Partial<SeedInput> = {}): SeedInput {
  return {
    initialResponse: seed,
    url: readUrlState('https://example.test/page', { prefix: 'b7.' }),
    seedSort: 'relevance',
    defaultSort: 'relevance',
    seedQuery: '',
    initialQuery: '',
    includeQuery: true,
    stateApplied: false,
    ...over,
  };
}

const url = (search: string) =>
  readUrlState(`https://example.test/page${search}`, { prefix: 'b7.' });

describe('the first-fetch skip rule', () => {
  it('adopts the seed for a pristine block, with or without URL sync', () => {
    expect(seedAnswersFirstSearch(input())).toBe(true);
    expect(seedAnswersFirstSearch(input({ url: null }))).toBe(true);
  });

  it.each([
    ['a facet', '?b7.f.type_s=Text'],
    ['page 2', '?b7.page=2'],
    ['a year', '?b7.date.from=1990'],
    ['an upper year', '?b7.date.to=2000'],
  ])('searches when a shared link carries %s', (_label, search) => {
    expect(seedAnswersFirstSearch(input({ url: url(search) }))).toBe(false);
  });

  it('searches for a sort other than the default', () => {
    expect(seedAnswersFirstSearch(input({ seedSort: 'title' }))).toBe(false);
  });

  it('searches when the block starts on a query the seed was not rendered for', () => {
    expect(seedAnswersFirstSearch(input({ seedQuery: 'islam' }))).toBe(false);
    expect(seedAnswersFirstSearch(input({ seedQuery: 'islam', initialQuery: 'islam' }))).toBe(true);
  });

  it("adopts the federated page's seed, which it requested for the URL's state", () => {
    expect(
      seedAnswersFirstSearch(
        input({
          url: url('?b7.f.type_s=Text&b7.page=3'),
          seedSort: 'title',
          includeQuery: false,
          seedQuery: 'islam',
          stateApplied: true,
        }),
      ),
    ).toBe(true);
  });

  it('never adopts a missing or unavailable seed', () => {
    expect(seedAnswersFirstSearch(input({ initialResponse: null }))).toBe(false);
    expect(seedAnswersFirstSearch(input({ initialResponse: { ...seed, available: false } }))).toBe(
      false,
    );
  });

  it('only renders a seed that carries a hit list', () => {
    expect(usableInitialResponse(undefined)).toBeNull();
    expect(usableInitialResponse({ available: false } as SearchResponse)).toBeNull();
    expect(usableInitialResponse(seed)).toBe(seed);
  });
});
