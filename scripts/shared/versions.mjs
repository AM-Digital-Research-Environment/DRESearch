/**
 * The version declarations, in one place.
 *
 * A release number lives in five places, and a release that ships them out of
 * step reports the wrong version to Omeka, to npm or to a citation manager:
 *
 *   config/module.ini   what Omeka reads, and what the release workflow asserts
 *                       the tag matches
 *   package.json        the Svelte client
 *   package-lock.json   npm's copy of it, twice (top level and packages[""]);
 *                       a hand-edited package.json leaves both stale until the
 *                       next `npm install`
 *   CITATION.cff        what GitHub renders under "Cite this repository", with
 *                       its date-released
 *
 * and CHANGELOG.md carries the `## [x.y.z] - YYYY-MM-DD` section the release
 * notes are sliced from. v1.19.2 shipped with CITATION.cff still on 1.19.1:
 * the release workflow compared the tag with module.ini only, so the mismatch
 * reached a published archive.
 *
 * So the list is data, here, read by both sides: the writer
 * (scripts/bump-version.mjs, `npm run bump`) and the guard
 * (scripts/check-version-consistency.mjs, run by `npm run lint`, by the
 * release-shape CI job and against the tag by release.yml). A sixth site is one
 * entry in DECLARATIONS, and both pick it up. Ported from IwacSearch's
 * scripts/lib/versions.js (scripts/lib here holds DRE-theme's vendored lint
 * rules, so this lives beside it instead).
 *
 * Every function takes the repository root, so the tests can point it at a
 * scratch copy; the scripts pass nothing and get this checkout.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

export const ROOT = join(import.meta.dirname, '..', '..');

const read = (root, file) => readFileSync(join(root, file), 'utf8');

/**
 * Replace exactly one regex match's first capture group, failing loudly when
 * the pattern no longer matches. A silent no-op here is the whole failure mode
 * this module exists to prevent.
 */
export function substitute(text, pattern, value, label) {
  let hits = 0;
  const out = text.replace(pattern, (match, captured) => {
    hits += 1;
    return match.replace(captured, value);
  });
  if (hits !== 1) throw new Error(`${label}: expected 1 match for ${pattern}, found ${hits}`);
  return out;
}

/** One JSON path, e.g. ['packages', '', 'version']; null when it is not a string. */
function readJsonPath(root, file, keys) {
  let node = JSON.parse(read(root, file));
  for (const key of keys) node = node?.[key];
  return typeof node === 'string' ? node : null;
}

// The first match counts as "the version", as it always has.
const MODULE_INI = /^version\s*=\s*"([^"]*)"/m;
// CITATION.cff writes both values unquoted; a hand-quoted one still reads and writes.
const CFF_VERSION = /^version:\s*"?([^"\r\n]*?)"?\s*$/m;
const CFF_DATE = /^date-released:\s*"?([^"\r\n]*?)"?\s*$/m;

/**
 * `write: null` marks a declaration the writer does not own. package.json and
 * package-lock.json are npm's to edit — `npm version` keeps the lockfile's two
 * copies in step with the manifest, and reformatting a lockfile by hand to move
 * two strings is how a review gets lost.
 */
export const DECLARATIONS = [
  {
    label: 'config/module.ini',
    file: 'config/module.ini',
    get: (root) => read(root, 'config/module.ini').match(MODULE_INI)?.[1] || null,
    pattern: MODULE_INI,
  },
  {
    label: 'package.json',
    get: (root) => readJsonPath(root, 'package.json', ['version']),
  },
  {
    label: 'package-lock.json',
    get: (root) => readJsonPath(root, 'package-lock.json', ['version']),
  },
  {
    label: 'package-lock.json (packages[""])',
    get: (root) => readJsonPath(root, 'package-lock.json', ['packages', '', 'version']),
  },
  {
    label: 'CITATION.cff',
    file: 'CITATION.cff',
    get: (root) => read(root, 'CITATION.cff').match(CFF_VERSION)?.[1] || null,
    pattern: CFF_VERSION,
  },
];

/** Every declaration's current value, in declaration order (null when absent). */
export function readAll(root = ROOT) {
  return DECLARATIONS.map(({ label, get }) => ({ label, version: get(root) }));
}

/**
 * The declarations this repository writes itself, rewritten in memory: a list
 * of { file, text }. Separate from the write so the bump can prove every
 * pattern still matches before it touches a single file.
 */
export function planOwned(version, root = ROOT) {
  return DECLARATIONS.filter((d) => d.file).map(({ file, pattern, label }) => ({
    file,
    text: substitute(read(root, file), pattern, version, label),
  }));
}

export function readReleaseDate(root = ROOT) {
  return read(root, 'CITATION.cff').match(CFF_DATE)?.[1] || null;
}

/** CITATION.cff with its date-released set; GitHub prints it in every citation. */
export function withReleaseDate(text, date) {
  return substitute(text, CFF_DATE, date, 'CITATION.cff');
}

export function writeFiles(plan, root = ROOT) {
  for (const { file, text } of plan) writeFileSync(join(root, file), text);
}

// ---------------------------------------------------------------------------
// CHANGELOG.md

const UNRELEASED = /^## \[Unreleased\][^\S\r\n]*$/m;
const escape = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** The `## [x.y.z]` heading of a version, with or without its date. */
export function changelogHeading(text, version) {
  return text.match(new RegExp(`^## \\[${escape(version)}\\](?: - (\\S+))?[^\\S\\r\\n]*$`, 'm'));
}

/** The date on a version's changelog heading, or null (no section, or undated). */
export function changelogDate(text, version) {
  return changelogHeading(text, version)?.[1] ?? null;
}

/**
 * Turn the `## [Unreleased]` notes into the `## [version] - date` section and
 * open a fresh, empty Unreleased above it.
 *
 * Refuses, rather than writing something the release would trip over:
 *   - no Unreleased heading (the next release would have nowhere to collect
 *     its notes);
 *   - nothing under it (the release notes are sliced from this section, and
 *     release.yml fails on an empty one);
 *   - a `## [version]` section that already exists while Unreleased still
 *     holds notes (two sections for one version).
 * A re-run of the same bump finds its section written and Unreleased empty,
 * and returns the text unchanged.
 */
export function releaseChangelog(text, version, date) {
  const heading = UNRELEASED.exec(text);
  if (!heading) throw new Error('CHANGELOG.md: no "## [Unreleased]" heading');
  const start = heading.index + heading[0].length;
  const next = text.slice(start).search(/^## \[/m);
  const end = next === -1 ? text.length : start + next;
  const notes = text.slice(start, end).trim();
  const existing = changelogHeading(text, version);

  if (existing) {
    if (notes) {
      throw new Error(
        `CHANGELOG.md: "## [${version}]" already exists and "## [Unreleased]" still has notes — merge them by hand`,
      );
    }
    return text;
  }
  if (!notes) {
    throw new Error(
      `CHANGELOG.md: nothing under "## [Unreleased]" — write the ${version} notes there first; the release notes are built from them`,
    );
  }
  const eol = text.includes('\r\n') ? '\r\n' : '\n';
  return (
    text.slice(0, heading.index) +
    `## [Unreleased]${eol}${eol}## [${version}] - ${date}${eol}${eol}${notes}${eol}` +
    (next === -1 ? '' : eol) +
    text.slice(end)
  );
}

// ---------------------------------------------------------------------------

const SEMVER = /^(\d+)\.(\d+)\.(\d+)$/;

/**
 * The version a bump spec resolves to: `patch` / `minor` / `major` from the
 * current version, or an explicit X.Y.Z (a leading `v` is accepted). Plain
 * releases only — this module has never shipped a pre-release, and the release
 * workflow's `v*` tag and changelog slicing assume none.
 */
export function nextVersion(current, spec) {
  const explicit = String(spec ?? '').replace(/^v/, '');
  if (SEMVER.test(explicit)) return explicit;
  const parts = SEMVER.exec(current ?? '');
  if (!parts) throw new Error(`the current version "${current}" is not X.Y.Z`);
  const [major, minor, patch] = parts.slice(1).map(Number);
  switch (spec) {
    case 'patch':
      return `${major}.${minor}.${patch + 1}`;
    case 'minor':
      return `${major}.${minor + 1}.0`;
    case 'major':
      return `${major + 1}.0.0`;
    default:
      throw new Error(`"${spec}" is not patch, minor, major or an X.Y.Z version`);
  }
}
