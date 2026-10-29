#!/usr/bin/env node
/**
 * Version-consistency lint — every version declaration agrees, and, given an
 * argument, agrees with it (release.yml passes the tag):
 *
 *   node scripts/check-version-consistency.mjs          (also: npm run lint:version)
 *   node scripts/check-version-consistency.mjs 1.33.0   ...and is this version
 *
 * The sites are data in scripts/shared/versions.mjs, shared with the writer,
 * `npm run bump`; that file says why each one exists. Beyond the five
 * declarations this asserts what the release reads from CHANGELOG.md and
 * CITATION.cff: a `## [x.y.z]` section for the current version (the release
 * notes are sliced from it), an ISO date-released, and the same date on that
 * changelog heading when it carries one.
 *
 * The single implementation: `npm run lint`, the release-shape CI job and the
 * release workflow all run it. Exit code 1 on any disagreement.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
  ROOT,
  changelogDate,
  changelogHeading,
  readAll,
  readReleaseDate,
} from './shared/versions.mjs';

const expected = process.argv[2] ? process.argv[2].replace(/^v/, '') : null;
const declarations = readAll();
const problems = [];

for (const { label, version } of declarations) {
  if (version === null) {
    problems.push(
      `${label}  no version found — the pattern in scripts/shared/versions.mjs no longer matches`,
    );
  }
}

const found = declarations.filter((d) => d.version !== null);
const reference = expected ?? found[0]?.version;
const against = expected ? `the tag v${expected}` : found[0]?.label;

for (const { label, version } of found) {
  if (version !== reference) {
    problems.push(`${label}  is ${version}, but ${against} is ${reference}`);
  }
}

const date = readReleaseDate();
if (!/^\d{4}-\d{2}-\d{2}$/.test(date ?? '')) {
  problems.push(`CITATION.cff  date-released is "${date}", not an ISO date`);
}

if (reference) {
  const changelog = readFileSync(join(ROOT, 'CHANGELOG.md'), 'utf8');
  if (!changelogHeading(changelog, reference)) {
    problems.push(`CHANGELOG.md  no '## [${reference}]' section for the current version`);
  } else {
    const dated = changelogDate(changelog, reference);
    if (dated && date && dated !== date) {
      problems.push(
        `CHANGELOG.md  '## [${reference}]' is dated ${dated}, CITATION.cff's date-released is ${date}`,
      );
    }
  }
}

if (problems.length) {
  console.error(`Version consistency: ${problems.length} problem(s)\n`);
  for (const p of problems) console.error('  ' + p);
  console.error(
    '\nThe sites are listed in scripts/shared/versions.mjs. Never edit a version by hand:' +
      '\n  npm run bump -- <patch|minor|major|X.Y.Z>',
  );
  process.exit(1);
}

console.log(
  `Version consistency: clean (${reference}${expected ? ', matches the tag' : ''} across ` +
    `${declarations.length} declarations + CHANGELOG, released ${date}).`,
);
