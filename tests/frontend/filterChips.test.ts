import { describe, expect, it } from 'vitest';
import {
  buildFilterChips,
  learnValueLabels,
  type ValueLabels,
} from '../../src/svelte/lib/filterChips';

describe('active-filter chips', () => {
  it('uses one chip model for query, facets, and year scope', () => {
    const chips = buildFilterChips({ type_s: ['Book'] }, { type_s: 'Type' }, 2000, 2020, 'Islam');
    expect(chips.map((chip) => chip.kind)).toEqual(['query', 'facet', 'year']);
    expect(chips[1]).toMatchObject({ label: 'Type', value: 'Book', display: 'Book' });
  });

  it('shows a project id filter under its title, but removes it by id (#45)', () => {
    const known: ValueLabels = {};
    const labels = learnValueLabels(known, [
      { id: '1', title: 'Interview', project_s: 'Muslim Youth in Kenya', project_id_s: '1797' },
      { id: '2', title: 'Untagged' },
    ]);
    const [chip] = buildFilterChips({ project_id_s: ['1797'] }, {}, null, null, '', labels);
    expect(chip).toMatchObject({
      field: 'project_id_s',
      value: '1797',
      display: 'Muslim Youth in Kenya',
      label: 'Project',
    });

    // A later search that finds nothing keeps the title already learnt.
    const later = learnValueLabels(known, []);
    expect(
      buildFilterChips({ project_id_s: ['1797'] }, {}, null, null, '', later)[0]?.display,
    ).toBe('Muslim Youth in Kenya');
    // An id no hit has named shows as itself.
    expect(buildFilterChips({ project_id_s: ['42'] }, {}, null, null, '', later)[0]?.display).toBe(
      '42',
    );
  });
});
