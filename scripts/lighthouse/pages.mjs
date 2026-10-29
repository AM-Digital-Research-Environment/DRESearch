/**
 * The pages the Lighthouse gate measures, shared by the capture
 * (scripts/lighthouse/capture.mjs), the fixture server and the check
 * (scripts/check-lighthouse.mjs).
 *
 * Each is a live AMIRA route that mounts one of the client's two page
 * surfaces, named by its key in the build manifest — the surface
 * src/View/BundleAssets.php preloads for that page:
 *
 *   block      a page carrying a Research items search block (the largest
 *              corpus, with thumbnails), whose first page of results the server
 *              renders into the block's bootstrap JSON;
 *   federated  the site-wide results page for a query typed into the header
 *              bar: the client asks /dre-search/api/search-all for the
 *              default corpus tab and the counts.
 *
 * The query string is part of the page: the fixture is loaded with it, as the
 * live route was, because the client reads its state from the URL.
 */
export const ORIGIN = 'https://data.africamultiple.uni-bayreuth.de';

export const PAGES = {
  block: { route: '/s/amira/page/research-items', surface: 'src/svelte/App.svelte' },
  federated: {
    route: '/s/amira/dre-search?q=music',
    surface: 'src/svelte/components/FederatedApp.svelte',
  },
};

/** The query string a page is captured and measured with ('' or '?…'). */
export const queryOf = (page) => new URL(PAGES[page].route, ORIGIN).search;
