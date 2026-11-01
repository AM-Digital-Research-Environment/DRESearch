import { describe, expect, it } from 'vitest';
import { buildFilterChips } from '../../src/svelte/lib/filterChips';

describe('active-filter chips', () => {
  it('uses one chip model for query, facets, and year scope', () => {
    const chips = buildFilterChips({ type_s: ['Book'] }, { type_s: 'Type' }, 2000, 2020, 'Islam');
    expect(chips.map((chip) => chip.kind)).toEqual(['query', 'facet', 'year']);
    expect(chips[1]).toMatchObject({ label: 'Type', value: 'Book' });
  });
});
