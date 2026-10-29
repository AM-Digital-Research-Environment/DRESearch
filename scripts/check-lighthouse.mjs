#!/usr/bin/env node
/**
 * Lighthouse budgets for the search pages — `npm run check:lighthouse`.
 *
 *   node scripts/check-lighthouse.mjs              # measure, compare, fail on a breach
 *   node scripts/check-lighthouse.mjs --update     # measure, then rewrite the baseline
 *   node scripts/check-lighthouse.mjs --only=block --runs=5
 *
 * Each page in scripts/lighthouse/pages.mjs (tests/lighthouse/fixtures/<page>.html,
 * captured from the live route by scripts/lighthouse/capture.mjs) is loaded by
 * Lighthouse — mobile, simulated slow 4G, its defaults — RUNS times, from
 * scripts/lighthouse/server.mjs, which writes the module's head from this
 * checkout's build, serves text gzipped as the live site does, and stands in
 * for the thumbnails and /dre-search/api/*. Build first (`npm run build`):
 * asset/dist is not tracked. The browser is the Chrome at CHROME_PATH (CI pins
 * a Chrome for Testing build; chrome-launcher finds an installed Chrome when
 * it is unset). The median of those runs is held against
 * tests/lighthouse/baseline.json:
 *
 *   - transfer bytes of script, stylesheet, image and font, counting what the
 *     module ships plus the thumbnails its first page loads (the fixture HTML
 *     under /tests/ is left out), and the page's request count: no more than
 *     BYTES_HEADROOM over the baseline. These are deterministic — every run
 *     must load the same bytes, or the page is reported as unstable — so this
 *     is the part of the gate that cannot flake;
 *   - the /dre-search/api/* round trips the page makes on load: no more than
 *     the baseline, with no headroom. A block's first page is server-rendered
 *     into its bootstrap, so a new call on load is a reader waiting on the
 *     search server for something the server could have rendered;
 *   - the accessibility and best-practices scores: no lower than the baseline.
 *     They repeat run to run on one host (the same DOM, the same audits), but
 *     not across hosts, since fonts size the text;
 *   - the performance score: no lower than the baseline minus
 *     PERFORMANCE_MARGIN. It is timed on the host's CPU, so it is the noisy
 *     one, and its baseline must come from the CI runner.
 *
 * A request to any other host fails the page: the fixtures must not depend on
 * the network. SEO is not measured: a fixture's <head> is the harness's, and
 * the theme and DRE SEO own the live one.
 *
 * The baseline retires its own slack: a page that got BYTES_STALE lighter, made
 * fewer API calls, or whose accessibility or best-practices score rose fails
 * until the baseline is rewritten, so an improvement is locked in rather than
 * left as headroom.
 *
 * THE BASELINE COMES FROM THE CI RUNNER, NOT A LAPTOP. A laptop's CPU times the
 * performance score, and its fonts size the text the accessibility audits
 * measure. Every run writes the baseline it measured to
 * test-results/lighthouse/baseline.json (the CI job's `lighthouse` artifact),
 * beside one HTML report per page and details.json (each run's score and the
 * host's benchmark index). So:
 *
 *   - bootstrap: while tests/lighthouse/baseline.json does not exist, a run
 *     measures, writes that artifact and passes. Commit the artifact's
 *     baseline.json at tests/lighthouse/baseline.json — from several CI
 *     attempts' median for the performance score, not one (see
 *     PERFORMANCE_MARGIN) — and the gate is on;
 *   - re-baseline after a deliberate change: `--update` rewrites the committed
 *     file. Outside CI it keeps the committed scores and rewrites only the byte,
 *     request and API counts, which do not depend on the host; to move a
 *     score, take it from the CI run's artifact. With no committed baseline it
 *     refuses outside CI rather than write a laptop's scores.
 *
 * Ported from IwacSearch's scripts/check-lighthouse.js (itself from
 * IwacVisualizations'); the bootstrap mode is this repository's.
 */
import { spawn } from 'node:child_process';
import { appendFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { join, relative } from 'node:path';

import * as chromeLauncher from 'chrome-launcher';
import lighthouse from 'lighthouse';

import { PAGES as PAGE_SOURCES, queryOf } from './lighthouse/pages.mjs';

const ROOT = join(import.meta.dirname, '..');
const BASELINE = join(ROOT, 'tests', 'lighthouse', 'baseline.json');
const OUT = join(ROOT, 'test-results', 'lighthouse');

const PAGES = Object.keys(PAGE_SOURCES);
const CATEGORIES = ['performance', 'accessibility', 'best-practices'];
const BYTE_TYPES = ['script', 'stylesheet', 'image', 'font'];
/** Counted per page, and held to the baseline exactly: they never vary by run. */
const COUNTS = ['requests', 'api'];
const API = /^\/dre-search\/api\//;

/** Bytes and requests may grow by this factor before the gate fails. */
const BYTES_HEADROOM = 1.1;
/** A page this much lighter than its baseline fails until it is re-baselined. */
const BYTES_STALE = 0.8;
/**
 * How far a page's median performance score may sit below its baseline.
 * IwacVisualizations measured its block pages on 2026-10-27: three attempts
 * of each CI job on three runner VMs, nine runs per page; no page's median of
 * three fell more than 0.01 below its nine-run median, so 0.05 is five times
 * the worst drop seen. Revisit it once this repository's own CI history shows
 * its spread (details.json lists each run). A performance baseline is the
 * median of several CI attempts' runs, never a single attempt's.
 */
const PERFORMANCE_MARGIN = 0.05;

function parseArgs(argv) {
  const args = { update: false, runs: 3, only: null };
  for (const arg of argv) {
    if (arg === '--update') args.update = true;
    else if (arg.startsWith('--runs=')) args.runs = Number(arg.slice(7));
    else if (arg.startsWith('--only=')) args.only = arg.slice(7).split(',').filter(Boolean);
    else throw new Error(`unknown argument: ${arg}`);
  }
  if (!Number.isInteger(args.runs) || args.runs < 1) {
    throw new Error('--runs must be a positive integer');
  }
  const unknown = (args.only || []).filter((page) => !PAGES.includes(page));
  if (unknown.length) throw new Error(`not in PAGES: ${unknown.join(', ')}`);
  return args;
}

function freePort() {
  return new Promise((resolve, reject) => {
    const probe = createServer();
    probe.on('error', reject);
    probe.listen(0, '127.0.0.1', () => {
      const { port } = probe.address();
      probe.close(() => resolve(port));
    });
  });
}

/** Start the fixture server on a free port; resolves once it is listening. */
async function startServer() {
  const port = await freePort();
  const child = spawn(process.execPath, [join(ROOT, 'scripts', 'lighthouse', 'server.mjs')], {
    env: { ...process.env, PORT: String(port) },
    stdio: ['ignore', 'pipe', 'inherit'],
  });
  await new Promise((resolve, reject) => {
    child.once('error', reject);
    child.once('exit', (code) => reject(new Error(`fixture server exited (${code})`)));
    child.stdout.once('data', resolve);
  });
  return { origin: `http://127.0.0.1:${port}`, stop: () => child.kill() };
}

const median = (values) => {
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
};

/** The figures the gate reads from one Lighthouse result. */
function measure(lhr, origin) {
  const bytes = Object.fromEntries(BYTE_TYPES.map((type) => [type, 0]));
  const broken = [];
  let requests = 0;
  let api = 0;
  for (const request of lhr.audits['network-requests'].details.items) {
    if (!/^https?:/.test(request.url)) continue;
    requests++;
    const url = new URL(request.url);
    if (url.origin !== origin) broken.push(`external request ${request.url}`);
    if (request.statusCode >= 400) broken.push(`${request.statusCode} ${request.url}`);
    if (API.test(url.pathname)) api++;
    if (url.pathname.startsWith('/tests/')) continue;
    const type = String(request.resourceType || '').toLowerCase();
    if (type in bytes) bytes[type] += request.transferSize || 0;
  }
  const scores = Object.fromEntries(CATEGORIES.map((id) => [id, lhr.categories[id].score]));
  return { ...scores, ...bytes, requests, api, broken };
}

async function runPage(origin, page, runs) {
  const url = `${origin}/tests/lighthouse/fixtures/${page}.html${queryOf(page)}`;
  const results = [];
  for (let i = 0; i < runs; i++) {
    const chrome = await chromeLauncher.launch({ chromeFlags: ['--headless=new', '--no-sandbox'] });
    try {
      const result = await lighthouse(url, {
        port: chrome.port,
        output: 'html',
        logLevel: 'error',
        onlyCategories: CATEGORIES,
      });
      if (result.lhr.runtimeError) {
        throw new Error(`${page}: ${result.lhr.runtimeError.message}`);
      }
      results.push({ lhr: result.lhr, html: result.report, figures: measure(result.lhr, origin) });
    } finally {
      await chrome.kill();
    }
  }
  return results;
}

/** Collapse a page's runs to one row; report anything that should not vary. */
function summarise(page, results, problems) {
  const first = results[0].figures;
  for (const key of [...BYTE_TYPES, ...COUNTS]) {
    const seen = [...new Set(results.map((r) => r.figures[key]))];
    if (seen.length > 1) {
      problems.push(
        `${page}: ${key} differs between runs (${seen.join(', ')}) — the page does not load the same thing twice`,
      );
    }
  }
  for (const line of new Set(results.flatMap((r) => r.figures.broken))) {
    problems.push(`${page}: ${line}`);
  }
  const row = {};
  for (const id of CATEGORIES) {
    const values = results.map((r) => r.figures[id]);
    if (values.some((v) => typeof v !== 'number')) {
      problems.push(`${page}: no ${id} score in at least one run`);
      row[id] = null;
    } else {
      row[id] = Math.round(median(values) * 100) / 100;
    }
  }
  for (const key of [...BYTE_TYPES, ...COUNTS]) row[key] = first[key];
  // The report kept is the run whose performance score is the median one.
  const perf = results.map((r) => r.figures.performance);
  const kept = results[perf.indexOf([...perf].sort((a, b) => a - b)[Math.floor(perf.length / 2)])];
  return {
    row,
    runs: perf,
    benchmarkIndex: results.map((r) => r.lhr.environment.benchmarkIndex),
    html: kept.html,
    meta: { lighthouse: kept.lhr.lighthouseVersion, userAgent: kept.lhr.environment.hostUserAgent },
  };
}

const kb = (bytes) => `${(bytes / 1024).toFixed(1)} KB`;
const limitOf = (base) => Math.ceil(base * BYTES_HEADROOM);

/** Compare a measured row with its baseline; returns the cells to print. */
function compare(page, row, base, problems) {
  const cells = [];
  for (const id of CATEGORIES) {
    if (row[id] === null) {
      cells.push(`${id} —`);
      continue;
    }
    const floor =
      id === 'performance' ? Math.round((base[id] - PERFORMANCE_MARGIN) * 100) / 100 : base[id];
    cells.push(`${id} ${row[id].toFixed(2)} (≥ ${floor.toFixed(2)})`);
    if (row[id] < floor) {
      problems.push(
        `${page}: ${id} ${row[id].toFixed(2)} is below its floor of ${floor.toFixed(2)} (baseline ${base[id].toFixed(2)})`,
      );
    } else if (id !== 'performance' && row[id] > base[id]) {
      problems.push(
        `${page}: ${id} rose to ${row[id].toFixed(2)} from ${base[id].toFixed(2)} — lock it in with --update`,
      );
    }
  }
  for (const key of [...BYTE_TYPES, 'requests']) {
    const show = key === 'requests' ? String : kb;
    const limit = limitOf(base[key]);
    if (row[key] || base[key]) cells.push(`${key} ${show(row[key])} (≤ ${show(limit)})`);
    if (row[key] > limit) {
      problems.push(
        `${page}: ${key} ${show(row[key])} is over its budget of ${show(limit)} (baseline ${show(base[key])})`,
      );
    } else if (row[key] < base[key] * BYTES_STALE) {
      problems.push(
        `${page}: ${key} fell to ${show(row[key])} from ${show(base[key])} — lock it in with --update`,
      );
    }
  }
  cells.push(`api ${row.api} (= ${base.api})`);
  if (row.api > base.api) {
    problems.push(
      `${page}: ${row.api} /dre-search/api round trips on load, ${base.api} in the baseline — render it server-side, or re-baseline a deliberate one`,
    );
  } else if (row.api < base.api) {
    problems.push(
      `${page}: /dre-search/api round trips on load fell to ${row.api} from ${base.api} — lock it in with --update`,
    );
  }
  return cells;
}

function summaryTable(pages, measured, runs, problems) {
  const head = ['page', ...CATEGORIES, ...BYTE_TYPES, ...COUNTS];
  const table = [
    `| ${head.join(' | ')} |`,
    `|${head.map(() => ' --- ').join('|')}|`,
    ...pages.map((page) => {
      const row = measured[page];
      const scores = CATEGORIES.map((id) => row[id]).join(' | ');
      const bytes = BYTE_TYPES.map((t) => kb(row[t])).join(' | ');
      return `| ${page} | ${scores} | ${bytes} | ${COUNTS.map((c) => row[c]).join(' | ')} |`;
    }),
  ];
  return `## Lighthouse (median of ${runs})\n\n${table.join('\n')}\n\n${problems}\n`;
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const pages = args.only || PAGES;
  const hasBaseline = existsSync(BASELINE);
  const committed = hasBaseline ? JSON.parse(readFileSync(BASELINE, 'utf8')) : { pages: {} };
  if (!existsSync(join(ROOT, 'asset', 'dist', 'manifest.json'))) {
    throw new Error('asset/dist is not built — run `npm run build` first');
  }
  if (args.update && !hasBaseline && !process.env.CI) {
    console.error(
      "✗ lighthouse: no committed baseline, and this is not CI. A baseline's scores must come " +
        "from the runner: push, then commit the `lighthouse` artifact's baseline.json.",
    );
    return 1;
  }
  mkdirSync(OUT, { recursive: true });

  const server = await startServer();
  const problems = [];
  const measured = {};
  const details = {};
  try {
    for (const page of pages) {
      const started = Date.now();
      const summary = summarise(page, await runPage(server.origin, page, args.runs), problems);
      writeFileSync(join(OUT, `${page}.html`), summary.html);
      measured[page] = summary.row;
      details[page] = {
        performanceRuns: summary.runs,
        benchmarkIndex: summary.benchmarkIndex,
        ...summary.meta,
      };
      const seconds = ((Date.now() - started) / 1000).toFixed(0);
      console.log(
        `  measured ${page} in ${seconds} s — performance runs ${summary.runs.join(', ')}`,
      );
    }
  } finally {
    server.stop();
  }

  const meta = Object.values(details)[0];
  const candidate = {
    _: 'Written by scripts/check-lighthouse.mjs on the CI runner (see its docblock), never by hand.',
    lighthouse: meta.lighthouse,
    userAgent: meta.userAgent,
    runs: args.runs,
    pages: { ...committed.pages, ...measured },
  };
  writeFileSync(join(OUT, 'baseline.json'), `${JSON.stringify(candidate, null, 2)}\n`);
  writeFileSync(join(OUT, 'details.json'), `${JSON.stringify(details, null, 2)}\n`);

  // A baseline taken from a page that broke or would not hold still would make
  // the next run's comparison meaningless.
  const refuse = (what) => {
    console.error(`✗ lighthouse: not ${what} over ${problems.length} problem(s)\n`);
    for (const problem of problems) console.error(`  ${problem}`);
    return 1;
  };

  if (!hasBaseline && !args.update) {
    if (problems.length) return refuse('bootstrapping a baseline');
    const where = relative(ROOT, join(OUT, 'baseline.json'));
    console.log(
      `\nℹ lighthouse: BOOTSTRAP — ${relative(ROOT, BASELINE)} does not exist, so nothing was ` +
        `compared.\n  Wrote ${where}. Commit it (from the CI run's \`lighthouse\` artifact, never a ` +
        `laptop's) as ${relative(ROOT, BASELINE)} to turn the gate on.`,
    );
    if (process.env.GITHUB_STEP_SUMMARY) {
      appendFileSync(
        process.env.GITHUB_STEP_SUMMARY,
        summaryTable(
          pages,
          measured,
          args.runs,
          "**Bootstrap:** no committed baseline. Commit `baseline.json` from this run's `lighthouse` artifact as `tests/lighthouse/baseline.json`.",
        ),
      );
    }
    return 0;
  }

  if (args.update) {
    if (problems.length) return refuse('re-baselining');
    if (!process.env.CI) {
      for (const page of pages) {
        for (const id of CATEGORIES) {
          const kept = committed.pages[page]?.[id];
          if (typeof kept === 'number') candidate.pages[page][id] = kept;
          else {
            console.warn(
              `  ! ${page}: no committed ${id} baseline; wrote this machine's ${measured[page][id]} — replace it with the CI run's`,
            );
          }
        }
      }
    }
    writeFileSync(BASELINE, `${JSON.stringify(candidate, null, 2)}\n`);
    const note = process.env.CI ? '' : ' (scores kept from the committed baseline)';
    console.log(
      `✓ lighthouse: wrote ${relative(ROOT, BASELINE)} for ${pages.length} page(s)${note}`,
    );
    return 0;
  }

  const lines = [];
  for (const page of pages) {
    const base = committed.pages[page];
    if (!base) {
      problems.push(`${page}: no baseline — run with --update (scores from CI)`);
      continue;
    }
    lines.push(`${page.padEnd(10)} ${compare(page, measured[page], base, problems).join('  ')}`);
  }
  if (!args.only) {
    for (const page of Object.keys(committed.pages)) {
      if (!PAGES.includes(page)) {
        problems.push(`${page}: has a baseline but is not in PAGES — run with --update`);
      }
    }
  }
  console.log(`\n${lines.join('\n')}\n`);

  if (process.env.GITHUB_STEP_SUMMARY) {
    const verdict = problems.length
      ? `**${problems.length} budget problem(s):**\n\n${problems.map((p) => `- ${p}`).join('\n')}`
      : 'Every page within budget.';
    appendFileSync(
      process.env.GITHUB_STEP_SUMMARY,
      summaryTable(pages, measured, args.runs, verdict),
    );
  }

  if (problems.length) {
    console.error(
      `✗ lighthouse: ${problems.length} budget problem(s) over ${pages.length} page(s)\n`,
    );
    for (const problem of problems) console.error(`  ${problem}`);
    console.error(
      `\nReports: ${relative(ROOT, OUT)}/<page>.html. A deliberate change re-baselines with --update;\nscores come from the CI run's baseline.json artifact.\n`,
    );
    return 1;
  }
  console.log(`✓ lighthouse: ${pages.length} page(s) within budget (median of ${args.runs} runs)`);
  return 0;
}

main().then(
  (code) => process.exit(code),
  (error) => {
    console.error(`✗ lighthouse: ${error.stack || error}`);
    process.exit(1);
  },
);
