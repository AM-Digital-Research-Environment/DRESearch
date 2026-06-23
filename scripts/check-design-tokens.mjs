#!/usr/bin/env node
/**
 * Design-token contract lint — the DRE-theme check, ported to this module so the
 * token contract DRE-Search already follows (it is the reference token consumer:
 * every colour/radius/shadow is `var(--token, on-brand-fallback)`) cannot
 * quietly regress. Mirrors DRE-theme/scripts/check-design-tokens.mjs.
 *
 *   node scripts/check-design-tokens.mjs        (also: npm run lint:tokens)
 *
 * Scans the Svelte client's styles — the `<style>` block of every .svelte
 * component under src/svelte, plus the server-rendered shell
 * asset/css/dre-search.css (the compiled bundle in asset/dist is generated and
 * exempt) — for:
 *   1. Raw hex colours outside var(--x, #hex) fallback position.
 *   2. Coloured border-left/right wider than 1px (the "accent side-stripe" tell).
 *   3. Gradient text (background-clip: text).
 *   4. px-valued font-size (the type scale is rem-only).
 *
 * Exit code 1 on any finding; prints file:line for each.
 */
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

const ROOT = join(import.meta.dirname, '..');
const SVELTE = join(ROOT, 'src', 'svelte');
const CSS = join(ROOT, 'asset', 'css');

// Files allowed to hold raw colour values, with the reason on record.
const HEX_ALLOW = [];
// border-left/right >1px that are construction, not decoration.
const STRIPE_ALLOW = [];

const findings = [];

function* filesUnder(dir, ext) {
  if (!existsSync(dir)) return;
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) yield* filesUnder(p, ext);
    else if (name.endsWith(ext)) yield p;
  }
}

function stripComments(line) {
  return line.replace(/\/\/.*$/, '').replace(/\/\*.*?\*\//g, '');
}

// Lines to check: for .css, every line; for .svelte, only those inside a
// <style> block (markup/script hex — e.g. an href="#…" or a JS colour — is not
// a styling concern). Original 1-based line numbers are preserved.
function styleLines(content, isSvelte) {
  const lines = content.split(/\r?\n/);
  if (!isSvelte) return lines.map((text, i) => ({ n: i + 1, text }));
  const out = [];
  let inStyle = false;
  lines.forEach((text, i) => {
    if (!inStyle) {
      if (/<style\b[^>]*>/.test(text)) {
        inStyle = true;
        const after = text.replace(/^[\s\S]*<style\b[^>]*>/, '');
        if (after.trim()) out.push({ n: i + 1, text: after });
      }
      return;
    }
    if (/<\/style>/.test(text)) {
      const before = text.replace(/<\/style>[\s\S]*$/, '');
      if (before.trim()) out.push({ n: i + 1, text: before });
      inStyle = false;
      return;
    }
    out.push({ n: i + 1, text });
  });
  return out;
}

function lint(file, isSvelte) {
  const rel = relative(ROOT, file).split(sep).join('/');
  for (const { n, text: raw } of styleLines(readFileSync(file, 'utf8'), isSvelte)) {
    const line = stripComments(raw);
    const loc = `${rel}:${n}`;

    // 1. Raw hex outside allowlist and outside var() fallback position.
    if (!HEX_ALLOW.includes(rel)) {
      const noFallbacks = line.replace(/var\(\s*--[\w-]+\s*,[^)]*\)/g, '');
      const noDataUri = noFallbacks.replace(/url\([^)]*\)/g, '').replace(/%23[0-9a-fA-F]{3,6}/g, '');
      const hex = noDataUri.match(/#[0-9a-fA-F]{3,8}\b/);
      if (hex) findings.push(`${loc}  raw hex outside fallback position: ${hex[0]}`);
    }

    // 2. Side-stripe accents.
    if (!STRIPE_ALLOW.includes(rel)) {
      if (/border-(left|right)\s*:\s*([2-9]|\d{2,})px\s+\w+\s+(var\(|#|oklch|rgb|color-mix)/.test(line)) {
        findings.push(`${loc}  coloured side-stripe border: ${line.trim()}`);
      }
    }

    // 3. Gradient text.
    if (/background-clip\s*:\s*text/.test(line)) {
      findings.push(`${loc}  gradient text (background-clip: text)`);
    }

    // 4. px type.
    if (/font-size\s*:\s*\d+px/.test(line)) {
      findings.push(`${loc}  px font-size (the type scale is rem-only): ${line.trim()}`);
    }
  }
}

for (const f of filesUnder(SVELTE, '.svelte')) lint(f, true);
for (const f of filesUnder(CSS, '.css')) lint(f, false);

if (findings.length) {
  console.error(`Design-token contract: ${findings.length} finding(s)\n`);
  for (const f of findings) console.error('  ' + f);
  process.exit(1);
} else {
  console.log('Design-token contract: clean.');
}
