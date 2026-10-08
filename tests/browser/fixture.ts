import { readFileSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';
import type { Page, Route } from '@playwright/test';

/**
 * A static stand-in for an Omeka page carrying a DRE Search surface: the shell
 * markup the PHP templates render (view/common/block-layout/
 * dre-search-block.phtml, src/View/Helper/FederatedSearch.php), the BUILT
 * bundle from asset/dist, the theme's tokens, and stubbed JSON for every API
 * endpoint (shapes from src/svelte/lib/types.ts). Everything is served through
 * page.route(), so the tests need no server.
 */

const ROOT = join(import.meta.dirname, '..', '..');

const MIME: Record<string, string> = {
  '.js': 'text/javascript',
  '.css': 'text/css',
  '.json': 'application/json',
  '.html': 'text/html',
};

/**
 * The theme's tokens as CSS, generated from the table vendored from DRE-theme
 * (scripts/lib/dre-tokens-fallback.json): light on :root, dark under the
 * resolved [data-theme="dark"] the theme writes to <html> and <body>.
 */
function tokenCss(): string {
  const table = JSON.parse(
    readFileSync(join(ROOT, 'scripts', 'lib', 'dre-tokens-fallback.json'), 'utf8'),
  ) as Record<string, Record<string, string>>;
  const block = (values: Record<string, string> = {}): string =>
    Object.entries(values)
      .map(([name, value]) => `${name}: ${value};`)
      .join('\n');
  return `:root { ${block(table.shared)}\n${block(table.light)} }
[data-theme="dark"] { ${block(table.dark)} }
body { margin: 0; padding: 1rem; background: var(--background); color: var(--ink);
  font-family: system-ui, sans-serif; }`;
}

export interface Hit {
  id: string;
  title: string;
  [key: string]: unknown;
}

export const HITS: Hit[] = [
  {
    id: '101',
    title: 'Photograph of the Bayreuth market',
    type_s: 'Photograph',
    creator_ss: ['Madore, Frédérick'],
    year: 1932,
    abstract: 'A street scene recorded for the research collection.',
  },
  {
    id: '102',
    title: 'Interview recording, Lagos',
    type_s: 'Audio',
    creator_ss: ['Vierke, Clarissa'],
    year: 2014,
    abstract: 'An interview about Swahili poetry and its audiences.',
  },
];

const FACETS = [
  {
    field: 'type_s',
    label: 'Type',
    counts: [
      { value: 'Photograph', count: 1 },
      { value: 'Audio', count: 1 },
    ],
  },
];

export function searchResponse(hits: Hit[]) {
  return { available: true, found: hits.length, page: 1, hits, facets: FACETS };
}

const PROFILES = [
  {
    name: 'research_items',
    label: 'Research items',
    kind: 'item',
    date_mode: 'single',
    show_year: false,
    year_bounds: null,
    facets: ['type_s'],
    facet_labels: { type_s: 'Type' },
    default_sort: 'relevance',
    sort_options: [
      { value: 'relevance', label: 'Relevance' },
      { value: 'title', label: 'Title (A–Z)' },
    ],
    per_page: 20,
  },
  {
    name: 'research_projects',
    label: 'Research projects',
    kind: 'project',
    date_mode: 'range',
    show_year: false,
    year_bounds: null,
    facets: [],
    facet_labels: {},
    default_sort: 'relevance',
    sort_options: [{ value: 'relevance', label: 'Relevance' }],
    per_page: 20,
  },
];

const ENDPOINTS = {
  facet: '/dre-search/api/facet',
  search: '/dre-search/api/search',
  export: '/dre-search/api/export',
  search_all: '/dre-search/api/search-all',
  union: '/dre-search/api/union',
  map: '/dre-search/api/map',
  suggest: '/dre-search/api/suggest',
  suggest_all: '/dre-search/api/suggest-all',
};

function blockPage(title: string): string {
  const bootstrap = {
    block_id: 5,
    profile: 'research_items',
    card_kind: 'item',
    date_mode: 'single',
    show_year: false,
    year_bounds: null,
    facets: ['type_s'],
    facet_labels: { type_s: 'Type' },
    default_sort: 'relevance',
    sort_options: PROFILES[0]!.sort_options,
    per_page: 20,
    item_url_base: '/s/site/item',
    endpoints: ENDPOINTS,
    initial_response: searchResponse(HITS),
    initial_query: '',
  };
  const heading = title
    ? `<section class="dre-search-block" aria-labelledby="dre-search-title-5">
  <h2 class="dre-search-block__title" id="dre-search-title-5">${title}</h2>`
    : `<section class="dre-search-block" aria-label="Search Research items">`;
  return `${heading}
  <div data-dre-search-root data-dre-block-id="5" data-dre-heading-level="${title ? 3 : 2}"
       class="dre-search-block__root" id="dre-search-root-5">
    <div class="dre-search-block__skeleton" aria-hidden="true"></div>
  </div>
  <script type="application/json" id="dre-search-state-5">${JSON.stringify(bootstrap)}</script>
</section>`;
}

function federatedPage(): string {
  const bootstrap = {
    variant: 'federated',
    available: true,
    item_url_base: '/s/site/item',
    initial_query: '',
    default_profile: 'research_items',
    profiles: PROFILES,
    endpoints: ENDPOINTS,
  };
  return `<section class="dre-federated-page">
  <div class="dre-federated" data-dre-federated-root data-dre-fed-id="main"></div>
  <script type="application/json" id="dre-federated-state-main">${JSON.stringify(bootstrap)}</script>
</section>`;
}

function shell(body: string, h1: string): string {
  return `<!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${h1}</title>
  <link rel="stylesheet" href="/tokens.css">
  <link rel="stylesheet" href="/css/dre-search.css">
  <link rel="stylesheet" href="/dist/dre-search.css">
  <script type="module" src="/dist/dre-search.js"></script>
</head>
<body data-theme="light">
  <main>
    <h1>${h1}</h1>
    ${body}
  </main>
</body>
</html>`;
}

/** What the stubbed API should do; tests flip these between steps. */
export interface ApiStub {
  /** Answer this many search requests with HTTP 500 before recovering. */
  failSearches: number;
  /** Request bodies the search endpoint received, in order. */
  searches: Array<Record<string, unknown>>;
}

function json(route: Route, body: unknown, status = 200): Promise<void> {
  return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
}

/**
 * Serve the fixture origin on `page` and return the API stub. `surface` picks
 * the page: a titled block, an untitled block, or the federated results page.
 */
export async function serve(
  page: Page,
  surface: 'block' | 'titled-block' | 'federated' = 'block',
): Promise<ApiStub> {
  const stub: ApiStub = { failSearches: 0, searches: [] };

  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;

    if (path === '/' || path === '/search') {
      const html =
        surface === 'federated'
          ? shell(federatedPage(), 'Search')
          : shell(blockPage(surface === 'titled-block' ? 'Find research items' : ''), 'Collection');
      return route.fulfill({ contentType: 'text/html', body: html });
    }
    if (path === '/tokens.css') {
      return route.fulfill({ contentType: 'text/css', body: tokenCss() });
    }
    if (path.startsWith('/dist/') || path.startsWith('/css/')) {
      const file = normalize(join(ROOT, 'asset', path));
      if (!file.startsWith(join(ROOT, 'asset'))) return route.abort();
      return route.fulfill({
        contentType: MIME[extname(file)] ?? 'application/octet-stream',
        body: readFileSync(file),
      });
    }
    if (path.startsWith('/s/site/item/')) {
      return route.fulfill({
        contentType: 'text/html',
        body: '<!doctype html><title>Item</title>',
      });
    }

    const body = (route.request().postDataJSON() ?? {}) as Record<string, unknown>;
    switch (path) {
      case '/dre-search/api/search': {
        stub.searches.push(body);
        if (stub.failSearches > 0) {
          stub.failSearches--;
          return json(
            route,
            { error: { code: 'upstream', message: 'Typesense timed out', request_id: 'req-42' } },
            500,
          );
        }
        const q = String(body.q ?? '');
        const filters = (body.filters ?? {}) as Record<string, string[]>;
        let hits = q === 'nothing' ? [] : HITS;
        if (filters.type_s?.length)
          hits = hits.filter((h) => filters.type_s!.includes(String(h.type_s)));
        return json(route, searchResponse(hits));
      }
      case '/dre-search/api/suggest': {
        const q = url.searchParams.get('q') ?? '';
        const suggestions = HITS.filter((h) => h.title.toLowerCase().includes(q.toLowerCase())).map(
          (h) => ({ id: h.id, title: h.title, subtitle: String(h.year) }),
        );
        return json(route, { available: true, suggestions });
      }
      case '/dre-search/api/facet':
        return json(route, { available: true, counts: [] });
      case '/dre-search/api/search-all': {
        const profile = String(body.profile ?? 'research_items');
        const hits =
          profile === 'research_projects'
            ? [{ id: '201', title: 'Lived Religion in West Africa', pi_ss: ['Madore, Frédérick'] }]
            : HITS;
        return json(route, {
          available: true,
          counts: { research_items: HITS.length, research_projects: 1 },
          active: searchResponse(hits),
        });
      }
      case '/dre-search/api/union':
        return json(
          route,
          searchResponse(
            HITS.map((h) => ({
              ...h,
              _profile: 'research_items',
              _profile_label: 'Research items',
              _kind: 'item',
            })),
          ),
        );
      default:
        return route.fulfill({ status: 404, body: '' });
    }
  });

  return stub;
}
