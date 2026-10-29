import { describe, expect, it } from 'vitest';
// @ts-expect-error — a plain-JS Node script with no type declarations.
import { judge } from '../../scripts/check-audit.mjs';
// @ts-expect-error — likewise.
import exceptions from '../../scripts/shared/audit-exceptions.mjs';

/**
 * scripts/check-audit.mjs — `npm audit` at high or critical, minus the
 * exceptions written down in scripts/shared/audit-exceptions.mjs, which retire
 * themselves. Fed synthetic `npm audit --json` reports.
 */

type Verdict = { problems: string[]; excepted: { id: string }[] };

function report(severity: string, fixAvailable: unknown = false) {
  return {
    vulnerabilities: {
      braces: {
        severity,
        fixAvailable,
        via: [
          {
            name: 'braces',
            severity,
            title: 'Uncontrolled resource consumption',
            range: '<3.0.3',
            url: 'https://github.com/advisories/GHSA-vfj7-8cjw-p6xm',
          },
        ],
      },
      // A dependent inherits the advisory: a string in `via`, not a second advisory.
      micromatch: { severity, fixAvailable, via: ['braces'] },
    },
  };
}

const EXCEPTION = {
  'GHSA-vfj7-8cjw-p6xm': { package: 'braces', since: '2026-10-29', reason: 'test' },
};

describe('check-audit', () => {
  it('passes a clean report', () => {
    expect((judge({ vulnerabilities: {} }, {}) as Verdict).problems).toEqual([]);
  });

  it('lets a low or moderate advisory through: the gate is high and critical', () => {
    expect((judge(report('moderate'), {}) as Verdict).problems).toEqual([]);
  });

  it('fails an undocumented high advisory, once, by its advisory id', () => {
    const { problems } = judge(report('high'), {}) as Verdict;
    expect(problems).toHaveLength(1);
    expect(problems[0]).toContain('high: braces <3.0.3');
    expect(problems[0]).toContain('GHSA-vfj7-8cjw-p6xm');
  });

  it('lets a documented one through while no fix exists in range', () => {
    const verdict = judge(report('critical', { name: 'braces', isSemVerMajor: true }), EXCEPTION);
    expect((verdict as Verdict).problems).toEqual([]);
    expect((verdict as Verdict).excepted.map((a) => a.id)).toEqual(['GHSA-vfj7-8cjw-p6xm']);
  });

  it('retires an exception npm can now fix in range', () => {
    const { problems } = judge(report('high', true), EXCEPTION) as Verdict;
    expect(problems.join('\n')).toContain('npm can now fix it in range');
  });

  it('retires an exception that is no longer reported, or no longer blocking', () => {
    expect((judge({ vulnerabilities: {} }, EXCEPTION) as Verdict).problems[0]).toContain(
      'no longer reported',
    );
    expect((judge(report('low'), EXCEPTION) as Verdict).problems[0]).toContain('below the gate');
  });

  it('documents every exception it ships with', () => {
    for (const entry of Object.values(exceptions) as Record<string, string>[]) {
      expect(entry.package).toBeTruthy();
      expect(entry.since).toMatch(/^\d{4}-\d{2}-\d{2}$/);
      expect(entry.reason?.length).toBeGreaterThan(20);
    }
  });
});
