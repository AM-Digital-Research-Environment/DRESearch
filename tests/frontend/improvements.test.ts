import { describe, expect, it } from 'vitest';
import { buildFilterChips } from '../../src/svelte/lib/filterChips';
import { thumbnailFor } from '../../src/svelte/lib/thumbnail';
import { readUrlState, writeUrlState } from '../../src/svelte/lib/urlState';

describe('improvement contracts', () => {
  it('selects Omeka derivatives without inventing IIIF URLs', () => {
    const source = 'https://example.test/files/square/abc.jpg';
    expect(thumbnailFor(source, 'list')).toContain('/files/medium/');
    expect(thumbnailFor(source, 'gallery')).toContain('/files/large/');
    expect(thumbnailFor('https://example.test/original.jpg', 'gallery')).toBe(
      'https://example.test/original.jpg',
    );
  });

  it('uses one chip model for query, facets, and year scope', () => {
    const chips = buildFilterChips({ type_s: ['Book'] }, { type_s: 'Type' }, 2000, 2020, 'Islam');
    expect(chips.map((chip) => chip.kind)).toEqual(['query', 'facet', 'year']);
    expect(chips[1]).toMatchObject({ label: 'Type', value: 'Book' });
  });

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
});
