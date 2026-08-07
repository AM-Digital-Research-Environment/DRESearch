#!/usr/bin/env node
/**
 * Version-consistency lint — the release number lives in four places and a bump
 * has to touch all of them:
 *
 *   config/module.ini   what Omeka reads, and what the release workflow asserts
 *                       the tag matches
 *   package.json        the Svelte client
 *   CITATION.cff        what GitHub renders under "Cite this repository"
 *   CHANGELOG.md        the `## [x.y.z]` section the release notes are built from
 *
 *   node scripts/check-version-consistency.mjs   (also: npm run lint:version)
 *
 * This mirrors the `release-shape` job in .github/workflows/ci.yml, which stays
 * the enforcing copy — this one only moves the failure from a CI round-trip to
 * the local lint. Keep the two in step. It exists because v1.19.2 shipped with
 * CITATION.cff still on 1.19.1: the release workflow only compares the tag with
 * module.ini, so the mismatch got past it and reached a published archive.
 *
 * Exit code 1 on any disagreement.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const ROOT = join(import.meta.dirname, '..');
const read = (name) => readFileSync(join(ROOT, name), 'utf8');

// Each source is matched the same way the CI job's sed does, so the two agree
// on what counts as "the version" — including that it must be the first match.
const SOURCES = [
  { file: 'config/module.ini', re: /^version\s*=\s*"([^"]*)"/m },
  { file: 'package.json', re: /^\s*"version"\s*:\s*"([^"]*)"/m },
  { file: 'CITATION.cff', re: /^version:\s*"?([^"\r\n]*?)"?\s*$/m },
];

const found = [];
const errors = [];

for (const { file, re } of SOURCES) {
  const version = read(file).match(re)?.[1];
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
    '\nA release number lives in module.ini, package.json, CITATION.cff and CHANGELOG.md.',
  );
  process.exit(1);
} else {
  console.log(
    `Version consistency: clean (${reference.version} across ${found.length} files + CHANGELOG).`,
  );
}
