import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  compare,
  readSearchPins,
  readVisManifest,
  sri,
  // @ts-expect-error — a plain-JS Node script with no type declarations.
} from '../../scripts/check-maplibre-pins.mjs';
import { CDN_INTEGRITY } from '../../src/svelte/lib/maplibreLoader';

/**
 * scripts/check-maplibre-pins.mjs — the weekly MapLibre lockstep check against
 * DRE Visualizations (maplibre-pins.yml).
 *
 * It reads the loader's SOURCE with regexes, so the first case is the one that
 * matters day to day: a reformat of maplibreLoader.ts the parser no longer
 * understands must fail here, on the PR, not silently in a scheduled job.
 */

type Pins = { version: string; pins: Record<string, string> };
type Verdict = { ok: boolean; lines: string[] };

const LOADER = readFileSync('src/svelte/lib/maplibreLoader.ts', 'utf8');
const search = readSearchPins(LOADER) as Pins;

/** DRE Visualizations' asset/vendor/versions.json, reduced to what is read. */
function manifest(version: string): string {
  return JSON.stringify({
    packages: {
      echarts: { version: '6.1.0', files: { 'echarts.min.js': { from: 'dist/echarts.min.js' } } },
      'maplibre-gl': {
        version,
        files: {
          'maplibre-gl.js': { from: 'dist/maplibre-gl.mjs' },
          'maplibre-gl-worker.js': { from: 'dist/maplibre-gl-worker.mjs' },
          'maplibre-gl.css': { from: 'dist/maplibre-gl.css' },
        },
      },
    },
  });
}

describe('readSearchPins', () => {
  it('reads exactly what the loader exports', () => {
    expect(search.pins).toEqual(CDN_INTEGRITY);
    expect(LOADER).toContain(`maplibre-gl@\${VERSION}/`);
    expect(search.version).toMatch(/^\d+\.\d+\.\d+$/);
  });
});

describe('readVisManifest', () => {
  it('keys each vendored file by the dist file this module pins on the CDN', () => {
    expect(readVisManifest(manifest('6.16.0'))).toEqual({
      version: '6.16.0',
      files: {
        'maplibre-gl.mjs': 'asset/vendor/maplibre-gl.js',
        'maplibre-gl-worker.mjs': 'asset/vendor/maplibre-gl-worker.js',
        'maplibre-gl.css': 'asset/vendor/maplibre-gl.css',
      },
    });
  });

  it('fails loudly when the manifest no longer has MapLibre', () => {
    expect(() => readVisManifest('{"packages":{}}')).toThrow(/no maplibre-gl entry/);
  });
});

describe('compare', () => {
  it('passes when DRE Visualizations vendors the same files', () => {
    expect((compare(search, { ...search }) as Verdict).ok).toBe(true);
  });

  it('fails when this module is behind, naming the hashes to copy', () => {
    const verdict = compare(search, {
      version: '99.0.0',
      pins: { 'maplibre-gl.mjs': 'sha384-new' },
    }) as Verdict;
    expect(verdict.ok).toBe(false);
    expect(verdict.lines.join('\n')).toContain("'maplibre-gl.mjs': 'sha384-new'");
  });

  it('reports but passes when this module is ahead of an unmerged upgrade', () => {
    expect((compare(search, { version: '0.1.0', pins: {} }) as Verdict).ok).toBe(true);
  });

  it('fails on the same version with a different hash or file', () => {
    const changed = { ...search.pins, 'maplibre-gl.css': 'sha384-other' };
    expect((compare(search, { version: search.version, pins: changed }) as Verdict).ok).toBe(false);
    const extra = { ...search.pins, 'maplibre-gl-csp.js': 'sha384-x' };
    const verdict = compare(search, { version: search.version, pins: extra }) as Verdict;
    expect(verdict.ok).toBe(false);
    expect(verdict.lines.join('\n')).toContain(
      'maplibre-gl-csp.js: vendored by DRE Visualizations',
    );
  });
});

describe('check-maplibre-pins --against', () => {
  it('hashes the vendored bytes of a local checkout', () => {
    const vis = mkdtempSync(join(tmpdir(), 'dre-vis-'));
    try {
      mkdirSync(join(vis, 'asset', 'vendor'), { recursive: true });
      writeFileSync(join(vis, 'asset', 'vendor', 'versions.json'), manifest(search.version));
      for (const name of ['maplibre-gl.js', 'maplibre-gl-worker.js', 'maplibre-gl.css']) {
        writeFileSync(join(vis, 'asset', 'vendor', name), `not ${name}`);
      }
      const run = spawnSync(
        process.execPath,
        ['scripts/check-maplibre-pins.mjs', '--against', vis],
        { encoding: 'utf8' },
      );
      // Bytes other than the release's: same version, different hashes.
      expect(run.status).toBe(1);
      expect(run.stderr).toContain(`maplibre-gl.css: ${search.pins['maplibre-gl.css']} here`);
      expect(run.stderr).toContain(sri(Buffer.from('not maplibre-gl.css')));
    } finally {
      rmSync(vis, { recursive: true, force: true });
    }
  });
});
