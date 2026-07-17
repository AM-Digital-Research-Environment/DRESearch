import { describe, expect, it } from 'vitest';

import {
  configureI18n,
  formatDate,
  formatNumber,
  matchFieldLabel,
} from '../../src/svelte/lib/i18n';

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
