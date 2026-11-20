#!/usr/bin/env node
/**
 * Bundle-size budget — the last step of `npm run build`.
 *
 *   node scripts/check-bundle-size.mjs               (after vite build; npm run check:size)
 *   node scripts/check-bundle-size.mjs --dist <dir>  (another build, for the tests)
 *
 * The build is an ESM entry that every page loads (the header search bar),
 * plus the chunks it imports statically, plus two page chunks it imports on
 * demand: `App` (a search block, the item-page Project records block, a
 * federated tab) and `FederatedApp` (the federated results page). Their
 * hashed file names change on every build, so this reads
 * asset/dist/manifest.json — the same file src/View/BundleAssets.php reads to
 * preload them — and budgets each by its role, gzipped as Apache serves it
 * (level 9, so a file is the same byte count on every run and platform):
 *
 *   entry          dre-search.js — on every public page
 *   entry imports  the chunks it imports statically (the shared Svelte
 *                  runtime) — also on every page
 *   entry css      dre-search.css
 *   App            the App chunk and the chunks only it pulls in, its CSS
 *   FederatedApp   the same for the federated page (App is counted above)
 *
 * The budgets sit about 10 % above the sizes of 1.32.2, so ordinary work
 * passes and a real jump does not. A deliberate one raises the number below
 * in the same commit, saying why; a page that got lighter can lower it.
 *
 * Two structural rules come with the bytes, because they would cost far more
 * than 10 % and nothing else notices them: App and FederatedApp stay lazy
 * (a static import would put the whole search app on every page that only
 * shows the header bar), and every chunk the manifest names has a budget, so a
 * new lazy chunk cannot grow unwatched.
 */
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { gzipSync } from 'node:zlib';

const distAt = process.argv.indexOf('--dist');
const DIST =
  distAt === -1 ? join(import.meta.dirname, '..', 'asset', 'dist') : process.argv[distAt + 1];

/** Gzipped budgets in bytes (level 9); sizes of 1.32.2 in the comments. */
const BUDGETS = {
  entry: 4_400, //          3,969
  'entry imports': 24_300, // 22,057
  'entry css': 1_350, //    1,222
  App: 36_500, //          33,108
  'App css': 8_300, //      7,529
  FederatedApp: 4_700, //   4,236
  'FederatedApp css': 1_400, // 1,276
};

/** The page chunks, by the `name` Vite gives a dynamic import's chunk. */
const PAGES = ['App', 'FederatedApp'];

const problems = [];

const manifestPath = join(DIST, 'manifest.json');
if (!existsSync(manifestPath)) {
  console.error(`✗ bundle size: no manifest.json in ${DIST} — run \`vite build\` first`);
  process.exit(1);
}
const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));

/** Keys reachable from `start` through static imports (start included). */
function closure(start) {
  const seen = new Set();
  const walk = (key) => {
    if (seen.has(key) || !manifest[key]) return;
    seen.add(key);
    for (const next of manifest[key].imports ?? []) walk(next);
  };
  walk(start);
  return seen;
}

const minus = (set, ...others) => new Set([...set].filter((k) => !others.some((o) => o.has(k))));

const entries = Object.keys(manifest).filter((key) => manifest[key].isEntry);
if (entries.length !== 1) {
  console.error(`✗ bundle size: expected one entry in the manifest, found ${entries.length}`);
  process.exit(1);
}
const [entry] = entries;
const entryClosure = closure(entry);

const pageKey = {};
for (const name of PAGES) {
  const key = Object.keys(manifest).find((k) => manifest[k].name === name);
  if (!key) problems.push(`no ${name} chunk in the manifest — was it renamed?`);
  else if (entryClosure.has(key)) {
    problems.push(
      `${name} is imported statically by the entry: every page with only the header bar now ` +
        `downloads it. Keep it behind import() (src/svelte/main.ts).`,
    );
  } else pageKey[name] = key;
}

/** Role → the manifest keys whose files it counts. */
const roles = { entry: new Set([entry]), 'entry imports': minus(entryClosure, new Set([entry])) };
let counted = entryClosure;
for (const name of PAGES) {
  if (!pageKey[name]) continue;
  roles[name] = minus(closure(pageKey[name]), counted);
  counted = new Set([...counted, ...roles[name]]);
}

const gz = (file) => {
  const path = join(DIST, file);
  if (!existsSync(path)) {
    problems.push(`${file} is named by manifest.json but missing from asset/dist`);
    return { raw: 0, gz: 0 };
  }
  const bytes = readFileSync(path);
  return { raw: bytes.length, gz: gzipSync(bytes, { level: 9 }).length };
};

const rows = [];
const measure = (role, files) => {
  const sizes = files.map(gz);
  const total = sizes.reduce((sum, s) => sum + s.gz, 0);
  const raw = sizes.reduce((sum, s) => sum + s.raw, 0);
  const budget = BUDGETS[role];
  rows.push({ role, files, raw, total, budget });
  if (total > budget) {
    problems.push(
      `${role} is ${total} B gzipped, over its budget of ${budget} B (${files.join(', ')}). ` +
        'If the growth is deliberate, raise BUDGETS in scripts/check-bundle-size.mjs in the same ' +
        'commit and say why.',
    );
  }
};

const jsOf = (keys) => [...keys].map((k) => manifest[k].file);
const cssOf = (keys) => [...new Set([...keys].flatMap((k) => manifest[k].css ?? []))];

measure('entry', jsOf(roles.entry));
measure('entry imports', jsOf(roles['entry imports']));
measure('entry css', cssOf(entryClosure));
for (const name of PAGES) {
  if (!roles[name]) continue;
  measure(name, jsOf(roles[name]));
  measure(`${name} css`, cssOf(roles[name]));
}

// Anything the manifest builds that no role counts is a chunk without a budget.
for (const key of Object.keys(manifest)) {
  if (!counted.has(key)) {
    problems.push(
      `${manifest[key].file} (${manifest[key].name ?? key}) has no budget: a new lazily loaded ` +
        'chunk. Add it to BUDGETS in scripts/check-bundle-size.mjs.',
    );
  }
}

for (const { role, files, raw, total, budget } of rows) {
  console.log(
    `${total <= budget ? '✓' : '✗'} ${role.padEnd(17)} ${String(raw).padStart(7)} B raw ` +
      `${String(total).padStart(6)} B gz  (budget ${budget} B)  ${files.join(', ')}`,
  );
}

if (problems.length) {
  console.error(`\n✗ bundle size: ${problems.length} problem(s)`);
  for (const p of problems) console.error(`  - ${p}`);
  process.exit(1);
}
console.log('✓ bundle size: every chunk within its gzipped budget; App and FederatedApp stay lazy');
