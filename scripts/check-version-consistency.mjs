#!/usr/bin/env node
/**
 * Version-consistency lint — the release number lives in five places and a bump
 * has to touch all of them:
 *
 *   config/module.ini   what Omeka reads, and what the release workflow asserts
 *                       the tag matches
 *   package.json        the Svelte client
 *   package-lock.json   npm's copy of it, twice (top level and packages[""]);
 *                       a hand-edited package.json leaves both stale until the
 *                       next `npm install`
 *   CITATION.cff        what GitHub renders under "Cite this repository"
 *   CHANGELOG.md        the `## [x.y.z]` section the release notes are built from
 *
 *   node scripts/check-version-consistency.mjs   (also: npm run lint:version)
 *
 * The single implementation: `npm run lint` and the `release-shape` CI job both
 * run it. It exists because v1.19.2 shipped with
 * CITATION.cff still on 1.19.1: the release workflow only compares the tag with
 * module.ini, so the mismatch got past it and reached a published archive.
 *
 * Exit code 1 on any disagreement.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const ROOT = join(import.meta.dirname, '..');
const read = (name) => readFileSync(join(ROOT, name), 'utf8');

// Each text source is matched the same way the CI job's sed does, so the two
// agree on what counts as "the version" — including that it must be the first
// match. The lockfile is JSON and is read as such.
const lock = () => JSON.parse(read('package-lock.json'));
const SOURCES = [
  { file: 'config/module.ini', re: /^version\s*=\s*"([^"]*)"/m },
  { file: 'package.json', re: /^\s*"version"\s*:\s*"([^"]*)"/m },
  { file: 'package-lock.json', get: () => lock().version },
  { file: 'package-lock.json (packages[""])', get: () => lock().packages?.['']?.version },
  { file: 'CITATION.cff', re: /^version:\s*"?([^"\r\n]*?)"?\s*$/m },
];

const found = [];
const errors = [];

for (const { file, re, get } of SOURCES) {
  const version = get ? get() : read(file).match(re)?.[1];
  if (!version) {
    errors.push(`${file}  no version key found`);
    continue;
  }
  found.push({ file, version });
}

const [reference, ...rest] = found;
if (reference) {
  for (const { file, version } of rest) {
    if (version !== reference.version) {
      errors.push(`${file}  is ${version}, but ${reference.file} is ${reference.version}`);
    }
  }
  // The release workflow builds its notes by slicing this heading out of the
  // changelog, and fails the release when the section is missing.
  if (
    !new RegExp(`^## \\[${reference.version.replace(/\./g, '\\.')}\\]`, 'm').test(
      read('CHANGELOG.md'),
    )
  ) {
    errors.push(`CHANGELOG.md  no '## [${reference.version}]' section for the current version`);
  }
}

if (errors.length) {
  console.error(`Version consistency: ${errors.length} problem(s)\n`);
  for (const e of errors) console.error('  ' + e);
  console.error(
    '\nA release number lives in module.ini, package.json (and its lockfile, via `npm install`),' +
      ' CITATION.cff and CHANGELOG.md.',
  );
  process.exit(1);
} else {
  console.log(
    `Version consistency: clean (${reference.version} across ${found.length} files + CHANGELOG).`,
  );
}
