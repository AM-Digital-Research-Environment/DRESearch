#!/usr/bin/env node
/**
 * Rebuild the Lighthouse fixtures from the live site — `npm run capture:lighthouse`.
 *
 * For each page in scripts/lighthouse/pages.mjs this writes
 *
 *   tests/lighthouse/fixtures/<page>.html      the module's own rendered markup
 *   tests/lighthouse/fixtures/<page>.api.json  what /dre-search/api/* answered
 *
 * The HTML is lifted from the live page, not restated, so a fixture cannot
 * describe markup the module never printed: the header search bars the theme
 * prints on every page (each with its state JSON) and the surface's own
 * fragment — a block's <section>, mount root, skeleton and the bootstrap JSON
 * carrying its server-rendered first page; the federated page's <section> and
 * bootstrap. The live <head> is dropped: the fixture server writes the
 * module's head in its place from THIS checkout's build manifest, the way
 * src/View/BundleAssets.php does (see server.mjs), so the gate measures the
 * tree under review, hashed chunk names and all. The theme's own CSS and JS are
 * not part of it; a short harness stylesheet stands in for the two display
 * rules the header bars depend on.
 *
 * The API responses are recorded by loading the live page in Chromium
 * (Playwright's, already a dev dependency) and keeping every
 * /dre-search/api/* response it makes on load, keyed by method and path. The
 * fixture server replays them, so the federated page renders its real first
 * page of results with no Omeka or Typesense behind it; check-lighthouse.mjs
 * counts those round trips.
 *
 * Thumbnails stay `/files/<tier>/…` paths (absolute ones on the live host are
 * made relative); the fixture server answers them with the placeholders in
 * tests/lighthouse/fixtures/files/, so no AMIRA image is copied here.
 *
 * Re-capture deliberately — the hits are a snapshot of the live index, and a
 * new snapshot moves the baseline — then re-baseline from CI (see
 * scripts/check-lighthouse.mjs).
 */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

import { chromium } from '@playwright/test';

import { ORIGIN, PAGES } from './pages.mjs';

const ROOT = join(import.meta.dirname, '..', '..');
const FIXTURES = join(ROOT, 'tests', 'lighthouse', 'fixtures');

/** The opening marker of each surface's fragment in the live page. */
const FRAGMENT = {
  'src/svelte/App.svelte': 'class="dre-search-block"',
  'src/svelte/components/FederatedApp.svelte': 'class="dre-federated-page"',
};

/**
 * The harness's stand-in for the theme: one header bar per breakpoint (the
 * collapsible one below 1200px). Both mount, as they do live.
 */
const HARNESS_CSS = [
  '.d-none { display: none !important; }',
  '.d-block { display: block !important; }',
  '.main-header__search-field { display: none; }',
  '@media (min-width: 1200px) {',
  '    .d-xl-none { display: none !important; }',
  '    .d-xl-block, .main-header__search-field { display: block !important; }',
  '}',
];

/** Undo the escaping Laminas' escapeHtmlAttr applies to attribute values. */
function unescapeAttr(value) {
  return value.replace(/&#x([0-9a-f]+);/gi, (_, hex) => String.fromCodePoint(parseInt(hex, 16)));
}

/** Absolute thumbnail URLs on the live host, made local to the fixture server. */
function localFiles(text) {
  return text.replaceAll(`${ORIGIN}/files/`, '/files/');
}

/** The substring from `start` through the end of the element that opens there. */
function elementAt(html, start, tag) {
  const open = new RegExp(`<${tag}\\b|</${tag}>`, 'g');
  open.lastIndex = start;
  let depth = 0;
  for (let m = open.exec(html); m; m = open.exec(html)) {
    depth += m[0].startsWith('</') ? -1 : 1;
    if (depth === 0) return html.slice(start, m.index + m[0].length);
  }
  throw new Error(`unclosed <${tag}> at ${start}`);
}

/** Every element carrying `marker`, whole. */
function elements(html, marker, tag) {
  const found = [];
  for (let at = html.indexOf(marker); at !== -1; at = html.indexOf(marker, at + 1)) {
    found.push(elementAt(html, html.lastIndexOf(`<${tag}`, at), tag));
  }
  return found;
}

/** The header bars, each followed by its state JSON (printed after it). */
function headerBars(html, route) {
  const bars = elements(html, 'data-dre-search-bar ', 'div').map((bar) => {
    const id = /data-dre-bar-id="([^"]+)"/.exec(bar)?.[1];
    const state = new RegExp(
      `<script type="application/json" id="dre-search-bar-state-${id}">[^<]*</script>`,
    ).exec(html)?.[0];
    if (!state) throw new Error(`${route}: header bar "${id}" has no state JSON`);
    return `${bar}\n${state}`;
  });
  if (bars.length === 0) throw new Error(`${route}: no header search bar`);
  return bars;
}

/** Load the live page in Chromium and keep its /dre-search/api/* responses. */
async function recordApi(browser, url) {
  const context = await browser.newContext({
    viewport: { width: 412, height: 823 },
    isMobile: true,
    hasTouch: true,
  });
  const page = await context.newPage();
  const pending = [];
  page.on('response', (response) => {
    const { origin, pathname } = new URL(response.url());
    if (origin !== ORIGIN || !pathname.startsWith('/dre-search/api/')) return;
    const key = `${response.request().method()} ${pathname}`;
    pending.push(
      response.text().then((body) => {
        if (!response.ok()) throw new Error(`${url}: ${key} answered HTTP ${response.status()}`);
        return [key, JSON.parse(localFiles(body))];
      }),
    );
  });
  await page.goto(url, { waitUntil: 'networkidle' });
  const recorded = Object.fromEntries(await Promise.all(pending));
  await context.close();
  return recorded;
}

async function capture(browser, name, { route, surface }) {
  const response = await fetch(`${ORIGIN}${route}`);
  if (!response.ok) throw new Error(`${route}: HTTP ${response.status}`);
  const html = unescapeAttr(await response.text());

  const version = /\/modules\/DRESearch\/asset\/dist\/dre-search\.js\?v=([0-9.]+)/.exec(html)?.[1];
  const marker = FRAGMENT[surface];
  if (!marker) throw new Error(`${name}: no fragment marker for surface ${surface}`);
  const [fragment] = elements(html, marker, 'section');
  if (!fragment) throw new Error(`${route}: no ${marker} in the live page`);
  if (!/<script type="application\/json" id="dre-(search|federated)-state-/.test(fragment)) {
    throw new Error(`${route}: the fragment carries no bootstrap JSON`);
  }
  if (marker.includes('dre-search-block') && !fragment.includes('"initial_response"')) {
    throw new Error(`${route}: no server-rendered first page — the block would search on load`);
  }

  const api = await recordApi(browser, `${ORIGIN}${route}`);
  const page = [
    '<!doctype html>',
    '<html lang="en">',
    '<head>',
    '    <meta charset="utf-8">',
    '    <meta name="viewport" content="width=device-width, initial-scale=1">',
    `    <title>DRE Search ${name} fixture</title>`,
    `    <!-- GENERATED by scripts/lighthouse/capture.mjs from ${ORIGIN}${route}`,
    `         (module ${version ?? 'unknown'}); never edit by hand. -->`,
    `    <style>\n        ${HARNESS_CSS.join('\n        ')}\n    </style>`,
    `    <!-- dre-search:head ${surface} -->`,
    '</head>',
    '<body>',
    '    <header>',
    ...headerBars(html, route).map((bar) => localFiles(bar)),
    '    </header>',
    '    <main>',
    localFiles(fragment),
    '    </main>',
    '    <!-- dre-search:entry -->',
    '</body>',
    '</html>',
    '',
  ].join('\n');
  writeFileSync(join(FIXTURES, `${name}.html`), page);
  writeFileSync(join(FIXTURES, `${name}.api.json`), `${JSON.stringify(api, null, 2)}\n`);
  const calls = Object.keys(api);
  console.log(
    `✓ ${name}.html from ${route} (module ${version ?? 'unknown'}, ${page.length} B); ` +
      `API on load: ${calls.length ? calls.join(', ') : 'none'}`,
  );
}

const browser = await chromium.launch();
try {
  for (const [name, page] of Object.entries(PAGES)) await capture(browser, name, page);
} finally {
  await browser.close();
}
