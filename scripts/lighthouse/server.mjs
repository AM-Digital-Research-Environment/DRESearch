#!/usr/bin/env node
/**
 * The static server the Lighthouse fixtures are measured from.
 *
 *   PORT=4190 node scripts/lighthouse/server.mjs
 *
 * Serves this checkout, with text gzipped as the live site serves it (Apache
 * compresses the module's assets; uncompressed, the gate would measure a page
 * three times heavier than the one readers download), compressed whole at a
 * fixed level so a file is the same byte count on every run and platform.
 *
 * Three things the live site provides are stood in for:
 *
 *   - the module's <head>. A fixture carries `<!-- dre-search:head <surface> -->`
 *     and `<!-- dre-search:entry -->` where the live page had them; they are
 *     expanded on every request from asset/dist/manifest.json, as
 *     src/View/BundleAssets.php::inject() does: the two stylesheets, a script
 *     preload for the entry and its static imports, the surface's chunks and
 *     a stylesheet preload for its CSS, and the entry module script at the end
 *     of <body>. So the gate measures this checkout's build, whatever its
 *     chunks are called. Keep the two in step;
 *   - /files/<tier>/<name>.<ext> — the thumbnails the captured hits name,
 *     answered with a placeholder of that tier's size;
 *   - /dre-search/api/* — answered with what the live endpoint answered at
 *     capture time (tests/lighthouse/fixtures/<page>.api.json, found from the
 *     request's Referer), or, for a request the capture never saw, an empty
 *     page in the shape of every endpoint. scripts/check-lighthouse.mjs counts
 *     the round trips either way.
 */
import { existsSync, readFileSync, stat } from 'node:fs';
import { createServer } from 'node:http';
import { basename, extname, join, resolve, sep } from 'node:path';
import { gzipSync } from 'node:zlib';

const ROOT = resolve(import.meta.dirname, '..', '..');
const FIXTURES = join(ROOT, 'tests', 'lighthouse', 'fixtures');
const PLACEHOLDERS = join(FIXTURES, 'files');
const MANIFEST = join(ROOT, 'asset', 'dist', 'manifest.json');
const ENTRY = 'src/svelte/main.ts';
const HOST = '127.0.0.1';
const PORT = Number(process.env.PORT || 4190);

const MIME = {
  '.css': 'text/css; charset=utf-8',
  '.html': 'text/html; charset=utf-8',
  '.jpg': 'image/jpeg',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.webp': 'image/webp',
};
const COMPRESSIBLE = new Set(['.css', '.html', '.js', '.json', '.svg']);

/**
 * An empty answer that satisfies every endpoint's shape: a search page
 * (search, union), search-all's `active` and `counts`, suggestions, facet
 * values and popular searches.
 */
const EMPTY_PAGE = { available: true, found: 0, page: 1, hits: [], facets: [] };
const EMPTY = {
  ...EMPTY_PAGE,
  active: EMPTY_PAGE,
  counts: [],
  groups: [],
  suggestions: [],
  queries: [],
};

const gzipped = new Map();
/** Answer with `body` as a file of extension `ext`, gzipped when it is text and the client accepts it. */
function send(request, response, status, ext, body) {
  const headers = {
    'Content-Type': MIME[ext] || 'application/octet-stream',
    'Cache-Control': 'no-store',
  };
  if (/\bgzip\b/.test(request.headers['accept-encoding'] || '') && COMPRESSIBLE.has(ext)) {
    let zipped = typeof body === 'string' ? null : gzipped.get(body);
    if (!zipped) {
      zipped = gzipSync(body, { level: 6 });
      if (typeof body !== 'string') gzipped.set(body, zipped);
    }
    response.writeHead(status, { ...headers, 'Content-Encoding': 'gzip', Vary: 'Accept-Encoding' });
    response.end(zipped);
    return;
  }
  response.writeHead(status, headers);
  response.end(body);
}

const files = new Map();
function fileBody(path, size, mtime) {
  const key = `${path}:${size}:${mtime}`;
  if (!files.has(key)) files.set(key, readFileSync(path));
  return files.get(key);
}

/** `key` and the manifest keys it imports statically, depth first (BundleManifest::closure). */
function closure(manifest, key) {
  const seen = [];
  const walk = (k) => {
    if (seen.includes(k) || !manifest[k]) return;
    seen.push(k);
    for (const next of manifest[k].imports ?? []) walk(next);
  };
  walk(key);
  return seen;
}

/** The module's head and entry script for `surface`, as BundleAssets::inject() prints them. */
function bundleTags(surface) {
  if (!existsSync(MANIFEST))
    throw new Error('asset/dist/manifest.json is missing — run `npm run build`');
  const manifest = JSON.parse(readFileSync(MANIFEST, 'utf8'));
  if (!manifest[ENTRY]) throw new Error(`the manifest has no ${ENTRY}`);
  if (!manifest[surface])
    throw new Error(`the manifest has no ${surface}, which the fixture mounts`);
  const preload = (as, file) =>
    `    <link rel="preload" as="${as}" crossorigin="anonymous" href="/asset/dist/${file}">`;
  const modules = [...closure(manifest, ENTRY).slice(1), ...closure(manifest, surface)].map(
    (k) => manifest[k].file,
  );
  const styles = closure(manifest, surface).flatMap((k) => manifest[k].css ?? []);
  return {
    head: [
      '    <link rel="stylesheet" href="/asset/css/dre-search.css">',
      '    <link rel="stylesheet" href="/asset/dist/dre-search.css">',
      preload('script', 'dre-search.js'),
      ...[...new Set(modules)].map((file) => preload('script', file)),
      ...[...new Set(styles)].map((file) => preload('style', file)),
    ].join('\n'),
    entry: '    <script type="module" src="/asset/dist/dre-search.js"></script>',
  };
}

function fixtureHtml(path) {
  const html = readFileSync(path, 'utf8');
  const surface = /<!-- dre-search:head (\S+) -->/.exec(html)?.[1];
  if (!surface) return html;
  const tags = bundleTags(surface);
  return html
    .replace(/^\s*<!-- dre-search:head \S+ -->/m, tags.head)
    .replace(/^\s*<!-- dre-search:entry -->/m, tags.entry);
}

/** The recorded answer for an API request, from the page that asked (its Referer). */
function apiAnswer(request, pathname) {
  let page = null;
  try {
    page = basename(new URL(request.headers.referer).pathname, '.html');
  } catch {
    // No Referer: an empty page.
  }
  const recorded = page && join(FIXTURES, `${page}.api.json`);
  if (recorded && existsSync(recorded)) {
    const answers = JSON.parse(readFileSync(recorded, 'utf8'));
    const answer = answers[`${request.method} ${pathname}`];
    if (answer) return answer;
  }
  return EMPTY;
}

const server = createServer((request, response) => {
  let pathname;
  try {
    pathname = decodeURIComponent(new URL(request.url, `http://${HOST}`).pathname);
  } catch {
    response.writeHead(400).end('Bad request');
    return;
  }
  // Every browser asks for one; a 404 is a console error best-practices would count.
  if (pathname === '/favicon.ico') {
    response.writeHead(204).end();
    return;
  }
  if (pathname.startsWith('/dre-search/api/')) {
    // Drain the body (a POST's search request) before answering.
    request.resume();
    request.on('end', () => {
      send(request, response, 200, '.json', JSON.stringify(apiAnswer(request, pathname)));
    });
    return;
  }
  const thumb = /^\/files\/(square|medium|large|original)\/[^/]+\.(?:jpe?g|png|webp)$/.exec(
    pathname,
  );
  const target = thumb
    ? join(PLACEHOLDERS, `${thumb[1] === 'original' ? 'large' : thumb[1]}.jpg`)
    : resolve(ROOT, `.${pathname}`);
  if (!thumb && target !== ROOT && !target.startsWith(ROOT + sep)) {
    response.writeHead(403).end('Forbidden');
    return;
  }
  stat(target, (error, info) => {
    if (error || !info.isFile()) {
      response.writeHead(404).end('Not found');
      return;
    }
    const ext = extname(target);
    if (ext === '.html' && target.startsWith(FIXTURES + sep)) {
      try {
        send(request, response, 200, ext, fixtureHtml(target));
      } catch (failure) {
        response.writeHead(500, { 'Content-Type': 'text/plain' }).end(String(failure.message));
      }
      return;
    }
    send(request, response, 200, ext, fileBody(target, info.size, info.mtimeMs));
  });
});

server.listen(PORT, HOST, () => {
  console.log(`DRE Search Lighthouse fixtures: http://${HOST}:${PORT}`);
});

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => server.close(() => process.exit(0)));
}
