import type { ActiveFilters, SortKey } from './types';

/**
 * URL ↔ search-state codec for the DRE Search surfaces.
 *
 * Two encodings share this module, distinguished by `prefix`:
 *
 *   - Federated "Search all" page (prefix '') → bare, shareable params:
 *       ?q=ramadan&profile=research_items&sort=newest&f.subject_ss=Islam&date.from=1990
 *
 *   - Embedded page blocks (prefix `b{block_id}.`) → every key namespaced by the
 *     block id, so several search blocks on one page never clobber each other (or
 *     the host page's own ?page=/?q=):
 *       ?b42.q=ramadan&b42.f.subject_ss=Islam&b7.f.country_ss=Niger
 *
 * Multi-value facets use repeated query params (URLSearchParams.getAll() handles
 * them natively). Defaults are omitted so a pristine surface keeps a clean URL.
 *
 * The key invariant for coexistence: {@link syncToUrl} MERGES. It clears and
 * rewrites only the keys it owns (this prefix's scalars + `${prefix}f.*`) and
 * leaves every other param (sibling blocks, the federated shell's q/profile, the
 * host page's own params, tracking params) untouched.
 *
 * The federated page splits ownership: its shell ({@link readFederatedShell} /
 * {@link syncFederatedShell}) owns the bare `q` + `profile`, while the reused
 * per-corpus App owns the bare facet/sort/page/year keys via `includeQuery:
 * false` — so the two never fight over `q`.
 */

const FILTER_PREFIX = 'f.';
const PROFILE_KEY = 'profile';
const DEFAULT_SORT: SortKey = 'relevance';

/** The slice of search state that round-trips through the URL. */
export interface UrlSearchState {
  q: string;
  page: number;
  sort: SortKey;
  filters: ActiveFilters;
  yearFrom: number | null;
  yearTo: number | null;
}

export interface UrlSyncOptions {
  /** '' for the federated page, `b{block_id}.` for an embedded block. */
  prefix?: string;
  /** This corpus's default sort — omitted from the URL, restored when absent. */
  defaultSort?: SortKey;
  /**
   * Whether this surface owns the `q` key. The federated shell owns the shared
   * query, so its reused per-corpus App passes `false` and never reads, writes,
   * or clears `q` (leaving it for {@link syncFederatedShell}).
   */
  includeQuery?: boolean;
}

interface ResolvedOptions {
  prefix: string;
  defaultSort: SortKey;
  includeQuery: boolean;
}

function resolve(options: UrlSyncOptions): ResolvedOptions {
  return {
    prefix: options.prefix ?? '',
    defaultSort: options.defaultSort ?? DEFAULT_SORT,
    includeQuery: options.includeQuery ?? true,
  };
}

/** Parse the URL into this prefix's search state. */
export function readUrlState(
  href: string = window.location.href,
  options: UrlSyncOptions = {},
): UrlSearchState {
  const { prefix, defaultSort, includeQuery } = resolve(options);
  const params = new URL(href).searchParams;
  const filterKey = `${prefix}${FILTER_PREFIX}`;

  const filters: ActiveFilters = {};
  for (const key of new Set(params.keys())) {
    if (!key.startsWith(filterKey)) continue;
    const field = key.slice(filterKey.length);
    if (!isValidFieldName(field)) continue;
    // getAll() handles repeated keys; comma-separated values are split too so a
    // hand-edited ?f.subject_ss=a,b still works.
    const raw = params.getAll(key).flatMap((v) => v.split(',').map((s) => s.trim()));
    const values = Array.from(new Set(raw.filter((v) => v !== '')));
    if (values.length > 0) {
      filters[field] = values;
    }
  }

  return {
    q: includeQuery ? (params.get(`${prefix}q`) ?? '') : '',
    page: clampInt(params.get(`${prefix}page`), 1, 100000, 1),
    sort: (params.get(`${prefix}sort`) as SortKey | null) ?? defaultSort,
    filters,
    yearFrom: parseYearOrNull(params.get(`${prefix}date.from`)),
    yearTo: parseYearOrNull(params.get(`${prefix}date.to`)),
  };
}

function parseYearOrNull(raw: string | null): number | null {
  if (!raw) return null;
  const n = Number(raw);
  // Sanity bounds — generous enough for the corpus's early outliers, but reject
  // garbage like ?date.from=999999. The proxy clamps to the real data anyway.
  if (!Number.isFinite(n) || n < 1 || n > 3000) return null;
  return Math.trunc(n);
}

/**
 * Remove every key this prefix owns from `params`, in place. Scalar keys match
 * exactly (so a block's `b42.page` never deletes the bare `page`) and filter
 * keys by the `${prefix}f.` stem. `q` is left alone when `includeQuery` is false.
 */
function clearOwnedKeys(params: URLSearchParams, prefix: string, includeQuery: boolean): void {
  const filterKey = `${prefix}${FILTER_PREFIX}`;
  const scalars = new Set([
    `${prefix}page`,
    `${prefix}sort`,
    `${prefix}date.from`,
    `${prefix}date.to`,
  ]);
  if (includeQuery) scalars.add(`${prefix}q`);
  // Collect first, then delete — mutating while iterating keys() is unsafe.
  const toDelete: string[] = [];
  for (const key of params.keys()) {
    if (scalars.has(key) || key.startsWith(filterKey)) {
      toDelete.push(key);
    }
  }
  for (const key of toDelete) params.delete(key);
}

/** Write this prefix's keys into `params`, in place. Defaults are omitted. */
function applyState(params: URLSearchParams, state: UrlSearchState, o: ResolvedOptions): void {
  const { prefix, defaultSort, includeQuery } = o;
  if (includeQuery && state.q.trim() !== '') {
    params.set(`${prefix}q`, state.q);
  }
  if (state.page > 1) {
    params.set(`${prefix}page`, String(state.page));
  }
  if (state.sort && state.sort !== defaultSort) {
    params.set(`${prefix}sort`, state.sort);
  }
  for (const [field, values] of Object.entries(state.filters)) {
    if (!isValidFieldName(field)) continue;
    for (const v of values) {
      if (v) params.append(`${prefix}${FILTER_PREFIX}${field}`, v);
    }
  }
  if (state.yearFrom != null) {
    params.set(`${prefix}date.from`, String(state.yearFrom));
  }
  if (state.yearTo != null) {
    params.set(`${prefix}date.to`, String(state.yearTo));
  }
}

/**
 * Merge `state` into `baseSearch` (a `window.location.search` string) under this
 * prefix and return the resulting query string (leading `?`, or "" when empty).
 * Only this prefix's keys are touched; everything else in baseSearch is
 * preserved — that's what keeps sibling blocks and the federated shell intact.
 */
export function writeUrlState(
  state: UrlSearchState,
  options: UrlSyncOptions = {},
  baseSearch = '',
): string {
  const o = resolve(options);
  const params = new URLSearchParams(baseSearch);
  clearOwnedKeys(params, o.prefix, o.includeQuery);
  applyState(params, state, o);
  const qs = params.toString();
  return qs ? `?${qs}` : '';
}

/**
 * Push or replace history depending on what changed:
 *   - new query / sort / filter / year → pushState (a back-button-able step)
 *   - only the page changed            → replaceState (don't spam history)
 *
 * The first call after mount (`prev === null`) is always replaceState — we're
 * synchronising URL ↔ memory, not navigating. Reads the LIVE
 * window.location.search each call so a block merges against whatever the
 * federated shell or sibling blocks have already written this tick.
 */
export function syncToUrl(
  next: UrlSearchState,
  prev: UrlSearchState | null,
  options: UrlSyncOptions = {},
  pathname: string = window.location.pathname,
): void {
  const qs = writeUrlState(next, options, window.location.search);
  const newUrl = `${pathname}${qs}`;
  if (newUrl === window.location.pathname + window.location.search) {
    return;
  }
  const onlyPaginationChanged =
    prev !== null &&
    prev.q === next.q &&
    prev.sort === next.sort &&
    prev.yearFrom === next.yearFrom &&
    prev.yearTo === next.yearTo &&
    sameFilters(prev.filters, next.filters);

  if (prev === null || onlyPaginationChanged) {
    window.history.replaceState(window.history.state, '', newUrl);
  } else {
    window.history.pushState(window.history.state, '', newUrl);
  }
}

/**
 * Listen for popstate (back/forward) and re-hydrate state from the URL. Returns
 * a cleanup function suitable for an $effect destructor.
 */
export function onUrlPop(
  handler: (state: UrlSearchState) => void,
  options: UrlSyncOptions = {},
): () => void {
  const listener = (): void => handler(readUrlState(window.location.href, options));
  window.addEventListener('popstate', listener);
  return () => window.removeEventListener('popstate', listener);
}

// ── Federated shell (the "Search all" page) ──────────────────────────────────

export interface FederatedShellState {
  q: string;
  /** The active corpus/tab, or null when the URL doesn't pin one. */
  profile: string | null;
}

/** Read the federated shell's shared state (bare `q` + active `profile`). */
export function readFederatedShell(href: string = window.location.href): FederatedShellState {
  const params = new URL(href).searchParams;
  return { q: params.get('q') ?? '', profile: params.get(PROFILE_KEY) };
}

/**
 * Write the federated shell's `q` + `profile` and reset the active corpus's
 * facet/sort/page/year keys, which the reused per-corpus App owns. Both a query
 * change and a tab switch start the corpus clean (its facet fields differ per
 * corpus, and a fresh query starts at page 1), so callers always clear here and
 * let the App re-seed from a pristine URL on remount.
 *
 *   - tab switch  → push  (a back-button-able step between corpora)
 *   - query typed → replace (don't spam history while typing)
 */
export function syncFederatedShell(
  state: FederatedShellState,
  push: boolean,
  pathname: string = window.location.pathname,
): void {
  const params = new URLSearchParams(window.location.search);
  // Clear the bare corpus keys (page/sort/date/f.*) but keep q (handled below).
  clearOwnedKeys(params, '', false);
  if (state.q.trim() !== '') {
    params.set('q', state.q);
  } else {
    params.delete('q');
  }
  if (state.profile) {
    params.set(PROFILE_KEY, state.profile);
  } else {
    params.delete(PROFILE_KEY);
  }
  const qs = params.toString();
  const newUrl = `${pathname}${qs ? `?${qs}` : ''}`;
  if (newUrl === window.location.pathname + window.location.search) {
    return;
  }
  if (push) {
    window.history.pushState(window.history.state, '', newUrl);
  } else {
    window.history.replaceState(window.history.state, '', newUrl);
  }
}

// ── helpers ──────────────────────────────────────────────────────────────────

function sameFilters(a: ActiveFilters, b: ActiveFilters): boolean {
  const ka = Object.keys(a);
  const kb = Object.keys(b);
  if (ka.length !== kb.length) return false;
  for (const k of ka) {
    const va = [...(a[k] ?? [])].sort();
    const vb = [...(b[k] ?? [])].sort();
    if (va.length !== vb.length) return false;
    for (let i = 0; i < va.length; i++) {
      if (va[i] !== vb[i]) return false;
    }
  }
  return true;
}

function clampInt(raw: string | null, min: number, max: number, fallback: number): number {
  const n = Number(raw);
  if (!Number.isFinite(n)) return fallback;
  return Math.max(min, Math.min(max, Math.floor(n)));
}

/**
 * Allowlist for facet field names — they flow into a Typesense filter_by, so we
 * reject anything a crafted URL might smuggle in (e.g. `f.is_public:=false`).
 * Schema fields are snake_case ending in _ss / _s / _txt etc.
 */
function isValidFieldName(name: string): boolean {
  return /^[a-z][a-z0-9_]{0,40}$/.test(name);
}
