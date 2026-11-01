#!/usr/bin/env node
/**
 * MapLibre lockstep: does this module's CDN fallback pin the files DRE
 * Visualizations vendors?
 *
 *   node scripts/check-maplibre-pins.mjs                   # vs DRE-Visualizations main
 *   node scripts/check-maplibre-pins.mjs --against <dir>   # vs a local checkout of it
 *
 * src/svelte/lib/maplibreLoader.ts loads MapLibre from the page or from DRE
 * Visualizations' same-origin copy (`window.RV_LIBS`) first, and only then
 * from jsDelivr, pinned to the version DRE Visualizations vendors, with
 * sha384 Subresource Integrity hashes. The pin is a promise that a site
 * running both modules has one MapLibre, not two: a fallback on an older
 * version would load a second, different copy of an ~800 KB library whenever
 * the vendored one is absent. Nothing in this repository can see the other
 * one, so that promise used to be a comment ("keep VERSION in step"); the
 * loader's own test checks only the hashes' format.
 *
 * DRE Visualizations records what it vendors in asset/vendor/versions.json:
 * each package's version and, per file, the dist file it was copied from,
 * byte for byte (only renamed .mjs → .js). That dist file is the one this
 * module pins on jsDelivr. So this reads the version and the file list from
 * that manifest, downloads the vendored files and compares their sha384 with
 * the hashes pinned here — the strongest check available: the browser would
 * accept the vendored bytes under the pinned hash.
 *
 * Verdicts, modelled on IwacSearch's check-maplibre-pins.js:
 *   - same version, same files and hashes  → pass
 *   - this module AHEAD (a pin waiting on an unmerged DRE Visualizations
 *     upgrade)                              → reported, pass
 *   - this module BEHIND, or the same version with different hashes or
 *     files                                → fail, with the values to copy
 *
 * Zero dependencies (global fetch, fs, crypto), so the weekly job needs no
 * `npm ci`. It never upgrades anything: the upgrade is a deliberate two-repo
 * change (DRE Visualizations' `node scripts/vendor-maplibre.mjs <version>`,
 * then VERSION and the three hashes here).
 */
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { join, posix, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(import.meta.dirname, '..');
const LOADER = 'src/svelte/lib/maplibreLoader.ts';
const VIS_REPO = 'AM-Digital-Research-Environment/DRE-Visualizations';
const VIS_RAW = `https://raw.githubusercontent.com/${VIS_REPO}/main/`;
const VIS_MANIFEST = 'asset/vendor/versions.json';

/** -1 / 0 / 1 for dotted numeric versions ("6.16.0"). */
export function compareVersions(a, b) {
  const pa = String(a).split('.').map(Number);
  const pb = String(b).split('.').map(Number);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] || 0) - (pb[i] || 0);
    if (d) return d < 0 ? -1 : 1;
  }
  return 0;
}

/** This module's pins, read from the loader's source: VERSION and CDN_INTEGRITY. */
export function readSearchPins(source) {
  const version = source.match(/\bconst VERSION\s*=\s*'([^']+)'/)?.[1];
  const block = source.match(/\bCDN_INTEGRITY\s*=\s*\{([\s\S]*?)\}/)?.[1] ?? '';
  const pins = {};
  for (const m of block.matchAll(/'([^']+)'\s*:\s*'(sha384-[^']+)'/g)) pins[m[1]] = m[2];
  if (!version || Object.keys(pins).length === 0) {
    throw new Error(
      `${LOADER}: could not find VERSION / CDN_INTEGRITY — the parser needs updating`,
    );
  }
  return { version, pins };
}

/**
 * DRE Visualizations' MapLibre, from its vendor manifest: the version, and
 * each vendored file keyed by the dist file it is a copy of — the name this
 * module pins on jsDelivr ('maplibre-gl.mjs' → 'asset/vendor/maplibre-gl.js').
 */
export function readVisManifest(json) {
  const entry = JSON.parse(json)?.packages?.['maplibre-gl'];
  if (!entry?.version || !entry.files) {
    throw new Error(`${VIS_MANIFEST}: no maplibre-gl entry with a version and files`);
  }
  const files = {};
  for (const [vendored, { from }] of Object.entries(entry.files)) {
    if (!from) continue;
    files[posix.basename(from)] = `asset/vendor/${vendored}`;
  }
  return { version: entry.version, files };
}

export const sri = (bytes) => `sha384-${createHash('sha384').update(bytes).digest('base64')}`;

/** Pure verdict: { ok, lines }. `vis.pins` is keyed like `search.pins`. */
export function compare(search, vis) {
  const order = compareVersions(search.version, vis.version);
  if (order > 0) {
    return {
      ok: true,
      lines: [
        `ℹ MapLibre ${search.version} here is AHEAD of DRE Visualizations main (${vis.version}) —`,
        '  expected while the matching DRE Visualizations upgrade is unmerged; this check',
        '  turns red if it is never merged and main moves elsewhere.',
      ],
    };
  }
  if (order < 0) {
    return {
      ok: false,
      lines: [
        `✗ MapLibre ${search.version} here is BEHIND DRE Visualizations main (${vis.version}).`,
        '  A page without its vendored copy now loads a second, older MapLibre.',
        `  Fix: set VERSION = '${vis.version}' in ${LOADER} and copy these hashes into CDN_INTEGRITY:`,
        ...Object.entries(vis.pins).map(([file, hash]) => `    '${file}': '${hash}',`),
      ],
    };
  }
  const problems = [];
  for (const file of new Set([...Object.keys(search.pins), ...Object.keys(vis.pins)])) {
    if (!(file in search.pins)) {
      problems.push(`  ${file}: vendored by DRE Visualizations, not pinned here`);
    } else if (!(file in vis.pins)) {
      problems.push(`  ${file}: pinned here, not vendored by DRE Visualizations`);
    } else if (search.pins[file] !== vis.pins[file]) {
      problems.push(`  ${file}: ${search.pins[file]} here, ${vis.pins[file]} there`);
    }
  }
  if (problems.length > 0) {
    return {
      ok: false,
      lines: [
        `✗ MapLibre ${search.version}: same version, different files — the browser would refuse`,
        '  the CDN copy under one of these hashes, or the two modules load different bytes:',
        ...problems,
      ],
    };
  }
  return {
    ok: true,
    lines: [
      `✓ MapLibre ${search.version}: ${Object.keys(search.pins).length} files, hashes identical ` +
        'to the copies DRE Visualizations main vendors',
    ],
  };
}

/** Read one DRE Visualizations file, from a local checkout or from GitHub. */
async function visFile(against, path) {
  if (against) return readFileSync(join(against, path));
  const response = await fetch(VIS_RAW + path, { signal: AbortSignal.timeout(30000) });
  if (!response.ok) throw new Error(`${VIS_RAW}${path}: HTTP ${response.status}`);
  return Buffer.from(await response.arrayBuffer());
}

async function main() {
  const search = readSearchPins(readFileSync(join(ROOT, LOADER), 'utf8'));
  const againstAt = process.argv.indexOf('--against');
  const against = againstAt === -1 ? null : process.argv[againstAt + 1];
  const vis = readVisManifest((await visFile(against, VIS_MANIFEST)).toString('utf8'));
  const pins = {};
  for (const [file, vendored] of Object.entries(vis.files)) {
    pins[file] = sri(await visFile(against, vendored));
  }
  const verdict = compare(search, { version: vis.version, pins });
  for (const line of verdict.lines) (verdict.ok ? console.log : console.error)(line);
  return verdict.ok ? 0 : 1;
}

// exitCode, not exit(): an exit() while fetch's sockets are still closing
// aborts Node on Windows (a libuv assertion) instead of returning the verdict.
if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  main().then(
    (code) => {
      process.exitCode = code;
    },
    (e) => {
      console.error(`✗ could not compare MapLibre pins: ${e.message}`);
      process.exitCode = 1;
    },
  );
}
