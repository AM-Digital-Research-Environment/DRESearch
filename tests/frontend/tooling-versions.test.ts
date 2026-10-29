import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
  changelogDate,
  nextVersion,
  planOwned,
  readAll,
  readReleaseDate,
  releaseChangelog,
  withReleaseDate,
  writeFiles,
  // @ts-expect-error — a plain-JS Node module with no type declarations.
} from '../../scripts/shared/versions.mjs';

/**
 * scripts/shared/versions.mjs — the version sites `npm run bump` writes and
 * `npm run lint:version` checks. The bump itself only adds `npm version` on
 * top of these, so they carry the behaviour worth pinning: which files are
 * read, that a stale pattern fails loudly instead of writing nothing, and what
 * the changelog rotation refuses.
 */

const CHANGELOG = [
  '# Changelog',
  '',
  '## [Unreleased]',
  '',
  '### Fixed',
  '',
  '- A fix.',
  '',
  '## [1.32.2] - 2026-10-27',
  '',
  '- Earlier.',
  '',
].join('\n');

describe('nextVersion', () => {
  it('steps patch, minor and major', () => {
    expect(nextVersion('1.32.2', 'patch')).toBe('1.32.3');
    expect(nextVersion('1.32.2', 'minor')).toBe('1.33.0');
    expect(nextVersion('1.32.2', 'major')).toBe('2.0.0');
  });

  it('takes an explicit version, with or without its v', () => {
    expect(nextVersion('1.32.2', '1.40.0')).toBe('1.40.0');
    expect(nextVersion('1.32.2', 'v1.40.0')).toBe('1.40.0');
  });

  it('refuses anything else', () => {
    expect(() => nextVersion('1.32.2', 'premajor')).toThrow(/not patch, minor, major/);
    expect(() => nextVersion('1.32.2', '1.40')).toThrow();
    expect(() => nextVersion('1.32.2-beta.1', 'patch')).toThrow(/not X\.Y\.Z/);
  });
});

describe('releaseChangelog', () => {
  it('turns the Unreleased notes into the dated section and opens a fresh one', () => {
    const out: string = releaseChangelog(CHANGELOG, '1.33.0', '2026-11-02');
    expect(out).toBe(
      [
        '# Changelog',
        '',
        '## [Unreleased]',
        '',
        '## [1.33.0] - 2026-11-02',
        '',
        '### Fixed',
        '',
        '- A fix.',
        '',
        '## [1.32.2] - 2026-10-27',
        '',
        '- Earlier.',
        '',
      ].join('\n'),
    );
    expect(changelogDate(out, '1.33.0')).toBe('2026-11-02');
  });

  it('keeps CRLF line endings', () => {
    const crlf = CHANGELOG.replace(/\n/g, '\r\n');
    const out: string = releaseChangelog(crlf, '1.33.0', '2026-11-02');
    expect(out).toContain('## [Unreleased]\r\n\r\n## [1.33.0] - 2026-11-02\r\n\r\n### Fixed');
    expect(out.replace(/\r\n/g, '')).not.toContain('\n');
  });

  it('works when Unreleased is the last section', () => {
    const out: string = releaseChangelog('## [Unreleased]\n\n- Only.\n', '0.1.0', '2026-01-01');
    expect(out).toBe('## [Unreleased]\n\n## [0.1.0] - 2026-01-01\n\n- Only.\n');
  });

  it('refuses an empty Unreleased: the release notes are built from it', () => {
    const empty = CHANGELOG.replace('### Fixed\n\n- A fix.\n\n', '');
    expect(() => releaseChangelog(empty, '1.33.0', '2026-11-02')).toThrow(/nothing under/);
  });

  it('refuses a changelog without an Unreleased heading', () => {
    expect(() => releaseChangelog('# Changelog\n', '1.33.0', '2026-11-02')).toThrow(
      /no "## \[Unreleased\]"/,
    );
  });

  it('is a no-op on a re-run, and refuses a second section for one version', () => {
    const once: string = releaseChangelog(CHANGELOG, '1.33.0', '2026-11-02');
    expect(releaseChangelog(once, '1.33.0', '2026-11-02')).toBe(once);
    const again = once.replace('## [Unreleased]\n', '## [Unreleased]\n\n- Another.\n');
    expect(() => releaseChangelog(again, '1.33.0', '2026-11-02')).toThrow(/already exists/);
  });
});

describe('version declarations', () => {
  let root: string;

  beforeEach(() => {
    root = mkdtempSync(join(tmpdir(), 'dre-versions-'));
    mkdirSync(join(root, 'config'));
    writeFileSync(
      join(root, 'config', 'module.ini'),
      '[info]\nname = "DRE Search"\nversion           = "1.32.2"\n',
    );
    writeFileSync(join(root, 'package.json'), '{\n  "name": "x",\n  "version": "1.32.2"\n}\n');
    writeFileSync(
      join(root, 'package-lock.json'),
      JSON.stringify({ version: '1.32.2', packages: { '': { version: '1.32.1' } } }),
    );
    writeFileSync(
      join(root, 'CITATION.cff'),
      'cff-version: 1.2.0\nversion: 1.32.2\ndate-released: 2026-10-27\n',
    );
  });
  afterEach(() => rmSync(root, { recursive: true, force: true }));

  it('reads all five sites, the lockfile twice', () => {
    expect(readAll(root)).toEqual([
      { label: 'config/module.ini', version: '1.32.2' },
      { label: 'package.json', version: '1.32.2' },
      { label: 'package-lock.json', version: '1.32.2' },
      { label: 'package-lock.json (packages[""])', version: '1.32.1' },
      { label: 'CITATION.cff', version: '1.32.2' },
    ]);
    expect(readReleaseDate(root)).toBe('2026-10-27');
  });

  it('writes module.ini and CITATION.cff, leaving the npm files to npm', () => {
    const plan = planOwned('1.33.0', root).map((step: { file: string; text: string }) =>
      step.file === 'CITATION.cff'
        ? { ...step, text: withReleaseDate(step.text, '2026-11-02') }
        : step,
    );
    writeFiles(plan, root);
    expect(readFileSync(join(root, 'config', 'module.ini'), 'utf8')).toBe(
      '[info]\nname = "DRE Search"\nversion           = "1.33.0"\n',
    );
    expect(readFileSync(join(root, 'CITATION.cff'), 'utf8')).toBe(
      'cff-version: 1.2.0\nversion: 1.33.0\ndate-released: 2026-11-02\n',
    );
    expect(readAll(root)[1]).toEqual({ label: 'package.json', version: '1.32.2' });
  });

  it('fails loudly when a pattern no longer matches, before anything is written', () => {
    writeFileSync(join(root, 'config', 'module.ini'), '[info]\nrelease = "1.32.2"\n');
    expect(() => planOwned('1.33.0', root)).toThrow(/config\/module\.ini: expected 1 match/);
    expect(readFileSync(join(root, 'CITATION.cff'), 'utf8')).toContain('version: 1.32.2');
  });
});
