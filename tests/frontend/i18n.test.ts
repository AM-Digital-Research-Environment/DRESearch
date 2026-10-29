import { render } from '@testing-library/svelte';
import { afterEach, describe, expect, it } from 'vitest';

import ResultSummary from '../../src/svelte/components/ResultSummary.svelte';
import {
  configureI18n,
  formatDate,
  formatNumber,
  matchFieldLabel,
  peopleLabel,
  resultCount,
  resultWord,
} from '../../src/svelte/lib/i18n';

afterEach(() => configureI18n('en'));

describe('locale-aware formatting', () => {
  it('formats dates and numbers with the configured locale', () => {
    configureI18n('en-GB');

    expect(formatDate('2026-03-29')).toBe('29 Mar 2026');
    expect(formatDate('2026-03')).toBe('Mar 2026');
    expect(formatDate('2026')).toBe('2026');
    expect(formatNumber(12345)).toBe('12,345');
  });

  it('applies runtime overrides to match-field labels', () => {
    configureI18n('fr', { field_title: 'Titre' });

    expect(matchFieldLabel('title')).toBe('Titre');
  });
});

describe('plurals', () => {
  const french = {
    result_one: 'résultat',
    result_other: 'résultats',
    people_one: '{n} personne',
    people_other: '{n} personnes',
  };

  it("follow the locale's rules, not n === 1", () => {
    configureI18n('en');
    expect([0, 1, 2].map(resultCount)).toEqual(['0 results', '1 result', '2 results']);

    configureI18n('fr', french);
    // French puts zero with the singular.
    expect([0, 1, 2].map(resultCount)).toEqual(['0 résultat', '1 résultat', '2 résultats']);
    expect(resultWord(1234)).toBe('résultats');
    expect(peopleLabel(0)).toBe('0 personne');
  });

  it('reach the result summary', () => {
    configureI18n('fr', french);
    const { container } = render(ResultSummary, { found: 0, chips: [], onRemove: () => {} });
    expect(container.querySelector('.dre-summary__count')?.textContent?.trim()).toBe('0 résultat');
  });
});
