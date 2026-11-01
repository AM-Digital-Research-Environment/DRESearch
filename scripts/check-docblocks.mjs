#!/usr/bin/env node
/**
 * Stacked-docblock guard for the PHP sources — `npm run lint:docblocks`, part of
 * `npm run lint`.
 *
 * PHP attaches only the LAST docblock before a symbol to it, and so do PHPStan
 * and every IDE. Two docblocks separated by nothing but blank lines are always a
 * mistake: a method's real documentation stranded on its neighbour after a
 * refactor, a superseded copy left above the current one, or a `@var`/`@param`
 * PHPStan never reads. Nothing else notices — the code runs the same — so this
 * fails on them.
 *
 * Ported from IwacSearch's scripts/check-docblocks.js, whose 3.20 review found a
 * run of them. Covers the module's PHP (Module.php, config, src, bin, view
 * templates), its tests and its PHP tooling.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = join(import.meta.dirname, '..');
const DIRS = ['config', 'src', 'bin', 'view', 'tests/phpunit', 'tests/stubs', 'scripts'];
const SKIP = new Set(['svelte', 'node_modules', 'vendor']);

function phpFiles(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) {
      if (!SKIP.has(name)) out.push(...phpFiles(path));
    } else if (/\.(php|phtml|stub)$/.test(name) || (dir.endsWith('bin') && !name.includes('.'))) {
      out.push(path);
    }
  }
  return out;
}

const files = [
  join(ROOT, 'Module.php'),
  join(ROOT, 'tests', 'bootstrap.php'),
  ...DIRS.flatMap((dir) => phpFiles(join(ROOT, dir))),
];

const problems = [];
for (const file of files) {
  const lines = readFileSync(file, 'utf8').split(/\r?\n/);
  lines.forEach((line, i) => {
    const trimmed = line.trim();
    const endsDocblock = trimmed === '*/' || (trimmed.startsWith('/**') && trimmed.endsWith('*/'));
    if (!endsDocblock) return;
    let next = i + 1;
    while (next < lines.length && lines[next].trim() === '') next += 1;
    if (next < lines.length && lines[next].trim().startsWith('/**')) {
      problems.push(`${relative(ROOT, file)}:${next + 1}`);
    }
  });
}

if (problems.length > 0) {
  console.error('✗ stacked docblocks (only the last one attaches to the symbol):');
  for (const p of problems) console.error(`   ${p}`);
  console.error('\n  Merge the two, or move the stranded one to the symbol it documents.');
  process.exit(1);
}
console.log(`✓ docblocks: no stacked docblocks in ${files.length} PHP files`);
