#!/usr/bin/env node
/**
 * Writes every version declaration at once, dates the release, and opens the
 * changelog's next Unreleased section. The only supported way to bump the
 * module:
 *
 *   npm run bump -- patch                     # 1.32.2 -> 1.32.3
 *   npm run bump -- minor                     # 1.32.2 -> 1.33.0
 *   npm run bump -- 1.33.0                    # explicit
 *   npm run bump -- minor --date 2026-11-02   # date other than today
 *
 * What it writes:
 *   - package.json and both package-lock.json copies, through `npm version`
 *     (npm owns those files' formatting);
 *   - config/module.ini and CITATION.cff (with date-released), the sites listed
 *     in scripts/shared/versions.mjs;
 *   - CHANGELOG.md: the `## [Unreleased]` notes become `## [x.y.z] - date`, and
 *     a fresh, empty `## [Unreleased]` opens above them.
 *
 * Every check runs before the first write — the target version, the changelog
 * (it refuses an empty Unreleased: the release notes are built from it), every
 * pattern — so a refused bump leaves the tree as it was. A re-run of the same
 * bump changes nothing. It never commits, tags or pushes.
 */
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { join } from 'node:path';

import {
  ROOT,
  nextVersion,
  planOwned,
  readAll,
  releaseChangelog,
  withReleaseDate,
  writeFiles,
} from './shared/versions.mjs';

/**
 * Today, local time, as YYYY-MM-DD. Assembled from the parts rather than
 * through toLocaleDateString, whose output depends on the host's locale data.
 */
function today() {
  const now = new Date();
  const pad = (value) => String(value).padStart(2, '0');
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

function fail(message) {
  console.error(`✗ bump: ${message}`);
  process.exit(1);
}

/**
 * Run npm from this process's own npm when there is one (an `npm run bump`
 * parent sets npm_execpath), so the bump cannot silently use a different npm
 * from the one that wrote the lockfile.
 */
function npm(argv) {
  const execpath = process.env.npm_execpath;
  const result =
    execpath && execpath.endsWith('.js')
      ? spawnSync(process.execPath, [execpath, ...argv], { cwd: ROOT, stdio: 'inherit' })
      : spawnSync(`npm ${argv.join(' ')}`, { cwd: ROOT, stdio: 'inherit', shell: true });
  if (result.status !== 0) fail(`npm ${argv.join(' ')} exited ${result.status}`);
}

const args = process.argv.slice(2);
const dateAt = args.indexOf('--date');
const date = dateAt === -1 ? today() : args[dateAt + 1];
const spec = args.filter((_, i) => dateAt === -1 || (i !== dateAt && i !== dateAt + 1))[0];

if (!spec) fail('usage: npm run bump -- <patch|minor|major|X.Y.Z> [--date YYYY-MM-DD]');
if (!/^\d{4}-\d{2}-\d{2}$/.test(date ?? '')) fail(`--date must be YYYY-MM-DD, got "${date}"`);

let version;
let plan;
try {
  // package.json is npm's view of the current version, and the one `npm
  // version` itself would step from.
  const current = readAll().find((d) => d.label === 'package.json')?.version;
  version = nextVersion(current, spec);
  plan = planOwned(version).map((step) =>
    step.file === 'CITATION.cff' ? { ...step, text: withReleaseDate(step.text, date) } : step,
  );
  const changelog = readFileSync(join(ROOT, 'CHANGELOG.md'), 'utf8');
  plan.push({ file: 'CHANGELOG.md', text: releaseChangelog(changelog, version, date) });
} catch (error) {
  fail(error.message);
}

// npm writes package.json and both lockfile copies; it is handed the resolved
// version, not the keyword, so it cannot step from a different base.
npm(['version', version, '--no-git-tag-version', '--allow-same-version']);
writeFiles(plan);

const sites = readAll();
console.log(`\n✓ bumped to ${version}, released ${date}`);
for (const { label, version: value } of sites) {
  console.log(`  ${value === version ? '✓' : '✗'} ${label.padEnd(34)} ${value}`);
}
console.log(`  ✓ ${'CHANGELOG.md'.padEnd(34)} ## [${version}] - ${date}, fresh ## [Unreleased]`);
if (sites.some((s) => s.version !== version)) fail('a declaration did not take the new version');
console.log(
  `\n  Next: review the diff, commit ("Release ${version}: …"), then tag v${version} on main.`,
);
