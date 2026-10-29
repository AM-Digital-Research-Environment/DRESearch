#!/usr/bin/env node
/**
 * `npm audit --audit-level=high`, with its exceptions written down.
 *
 *   node scripts/check-audit.mjs   (npm run check:audit; CI's frontend job)
 *
 * A bare `npm audit` gate has no answer to an advisory nothing can fix —
 * IWAC-theme met one in braces GHSA-vfj7-8cjw-p6xm, which had no patched
 * release and held every push red — and turning the gate off would hide the
 * next real one. So this fails on any high or critical advisory not listed in
 * scripts/shared/audit-exceptions.mjs, and on any listed one that has stopped
 * needing the exception: npm can now fix it in range, or it no longer appears.
 *
 * Ported from IwacSearch's scripts/check-audit.js, so the repositories share
 * one rule. Don't reach for `--omit=dev` or a lower `--audit-level`: every npm
 * dependency here is a dev dependency, so either turns the gate off. The PHP
 * side has its own gate, `composer audit --locked`, in the php job.
 */
import { spawnSync } from 'node:child_process';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import exceptions from './shared/audit-exceptions.mjs';

const ROOT = join(import.meta.dirname, '..');
const BLOCKING = new Set(['high', 'critical']);

/**
 * Run `npm audit --json` with this process's own npm when there is one (an
 * `npm run` parent sets npm_execpath), and return the parsed report. npm exits
 * non-zero whenever it finds anything, so the exit status says nothing here; a
 * report that does not parse, or carries an error, does.
 */
function audit() {
  const execpath = process.env.npm_execpath;
  const options = { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 };
  // Outside `npm run`, a whole command line through the shell finds npm on
  // every platform (npm.cmd on Windows) without passing it separate args.
  const result =
    execpath && execpath.endsWith('.js')
      ? spawnSync(process.execPath, [execpath, 'audit', '--json'], options)
      : spawnSync('npm audit --json', { ...options, shell: true });
  let report;
  try {
    report = JSON.parse(result.stdout);
  } catch {
    throw new Error(`npm audit produced no report:\n${result.stderr || result.stdout}`);
  }
  if (report.error) {
    throw new Error(`npm audit failed: ${report.error.summary || JSON.stringify(report.error)}`);
  }
  return report;
}

/**
 * The verdict on one `npm audit --json` report: { problems, excepted }.
 *
 * A vulnerability's `via` lists advisories (objects) and the vulnerable
 * dependencies it inherits them through (strings). Only the objects are
 * advisories; the strings are the same advisory seen from a dependent.
 */
export function judge(report, allowed) {
  const vulnerabilities = report.vulnerabilities || {};
  const advisories = new Map();
  for (const vulnerability of Object.values(vulnerabilities)) {
    for (const via of vulnerability.via || []) {
      if (typeof via !== 'object') continue;
      const id = String(via.url || via.source)
        .split('/')
        .pop();
      advisories.set(id, {
        id,
        package: via.name,
        severity: via.severity,
        title: via.title,
        range: via.range,
      });
    }
  }

  const problems = [];
  const excepted = [];
  for (const advisory of advisories.values()) {
    if (!BLOCKING.has(advisory.severity)) continue;
    const exception = allowed[advisory.id];
    if (!exception) {
      problems.push(
        `${advisory.severity}: ${advisory.package} ${advisory.range} — ${advisory.title} (${advisory.id})`,
      );
      continue;
    }
    // `true` means a fix inside the declared ranges; an object names a
    // semver-major move, which is no fix at all and keeps the exception standing.
    if (vulnerabilities[advisory.package]?.fixAvailable === true) {
      problems.push(
        `${advisory.id} (${advisory.package}) is excepted but npm can now fix it in range — run \`npm audit fix\` and delete the exception`,
      );
      continue;
    }
    excepted.push(advisory);
  }

  for (const [id, exception] of Object.entries(allowed)) {
    const seen = advisories.get(id);
    if (!seen) {
      problems.push(
        `${id} (${exception.package}) is excepted but no longer reported — delete the exception`,
      );
    } else if (!BLOCKING.has(seen.severity)) {
      problems.push(
        `${id} (${exception.package}) is excepted but now rated ${seen.severity}, below the gate — delete the exception`,
      );
    }
  }
  return { problems, excepted };
}

function main() {
  const { problems, excepted } = judge(audit(), exceptions);
  if (problems.length > 0) {
    console.error('✗ npm audit:\n');
    for (const problem of problems) console.error(`  - ${problem}`);
    console.error(
      '\n  Exceptions live in scripts/shared/audit-exceptions.mjs, each with the reason it cannot be fixed.',
    );
    return 1;
  }
  const listed = excepted.map((advisory) => `${advisory.package} ${advisory.id}`).join(', ');
  console.log(
    `✓ npm audit: no high or critical advisory outside the documented exceptions${listed ? ` (${listed})` : ''}`,
  );
  return 0;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  try {
    process.exitCode = main();
  } catch (error) {
    console.error(`✗ ${error.message}`);
    process.exitCode = 1;
  }
}
