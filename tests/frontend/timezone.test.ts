import { describe, expect, it } from 'vitest';
import { configureI18n, formatDate } from '../../src/svelte/lib/i18n';

/**
 * The guard on vite.config.ts's TZ: the date tests only prove anything if they
 * run somewhere midnight UTC is still YESTERDAY. If a platform ever ignores
 * the variable, this fails instead of every date test passing vacuously.
 */
describe('test environment', () => {
  it('runs west of Greenwich', () => {
    expect(Intl.DateTimeFormat().resolvedOptions().timeZone).toBe('America/New_York');
    // 1989-11-04T00:00:00Z is still 3 November in New York.
    expect(new Date(626140800 * 1000).getDate()).toBe(3);
  });

  it('formats an indexed date as the day it names, not the reader’s day before', () => {
    configureI18n('en-GB');
    expect(formatDate('2026-03-01')).toBe('1 Mar 2026');
    expect(formatDate('2026-01')).toBe('Jan 2026');
    configureI18n('en');
  });
});
