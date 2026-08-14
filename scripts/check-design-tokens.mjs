#!/usr/bin/env node
/**
 * Design-token contract lint — this client's CONFIG for the shared rule set.
 *
 *   node scripts/check-design-tokens.mjs                    (npm run lint:tokens)
 *   node scripts/check-design-tokens.mjs --update-allowlist
 *
 * The rules themselves are in scripts/lib/token-rules.mjs, vendored verbatim
 * from DRE-theme (refresh with `npm run vendor:lint` over there). This file used
 * to be a hand-written port with four rules and no rem check — which is how
 * `font-size: 1.25rem` came to sit in the search box, and why nothing ever
 * noticed that this client's `var(--token, literal)` fallbacks had drifted into
 * a second palette and a second type scale (--muted appeared as four different
 * greys, none of them the theme's).
 *
 * The scale values are read from scripts/lib/dre-tokens-fallback.json — also
 * vendored from the theme, generated from its OKLCH source — so "off-scale" and
 * "wrong fallback" mean measured against the theme's actual values.
 *
 * Exit code 1 on any finding; prints file:line for each.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { runRules, report, parseAllowlist, formatAllowlist } from './lib/token-rules.mjs';

const ROOT = join(import.meta.dirname, '..');
const ALLOWLIST = join(ROOT, 'scripts', 'design-token-allowlist.txt');
const table = JSON.parse(
  readFileSync(join(ROOT, 'scripts', 'lib', 'dre-tokens-fallback.json'), 'utf8'),
);
const updateAllowlist = process.argv.includes('--update-allowlist');

const SCAN = {
  root: ROOT,
  dirs: ['src/svelte', 'asset/css'],
  extensions: ['.svelte', '.css', '.ts'],
  table,
  // asset/dist holds the built bundle; linting generated output would report
  // every finding twice and could never be fixed there.
  skipDirs: ['node_modules', '.git', 'dist', 'vendor', 'build'],
};

// Exempt BY DESIGN, as opposed to the ratchet in design-token-allowlist.txt,
// which is work not yet done.
const BASE_RULES = {
  // The client has no canvas palette of its own: since the map moved onto the
  // token bridge (src/svelte/lib/tokenBridge.ts) every colour it paints comes
  // from a token. The bridge itself names '#000' as the last-resort argument
  // default, which is the one place a literal is correct.
  hex: { allow: ['src/svelte/lib/tokenBridge.ts'] },
};

if (updateAllowlist) {
  const all = runRules({ ...SCAN, rules: BASE_RULES });
  writeFileSync(
    ALLOWLIST,
    formatAllowlist(
      all,
      `# GENERATED baseline for scripts/check-design-tokens.mjs — the RATCHET.\n` +
        `#\n` +
        `# Each line exempts one rule in one file. This is a backlog, not a set of\n` +
        `# exemptions: every line is a conversion someone still owes. Lines should\n` +
        `# only ever be REMOVED. Regenerate with --update-allowlist after a pass,\n` +
        `# and check the diff — a new line means new drift got in.`,
    ),
  );
  console.log(`Wrote ${all.length} allowlist entr(ies) to scripts/design-token-allowlist.txt`);
  process.exit(0);
}

const findings = runRules({
  ...SCAN,
  rules: parseAllowlist(readFileSync(ALLOWLIST, 'utf8'), BASE_RULES),
});

report('Design-token contract', findings);
