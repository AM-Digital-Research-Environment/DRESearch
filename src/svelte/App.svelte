<script lang="ts">
  import type { ActiveFilters, Bootstrap, SearchResponse, SortKey, SortOption } from './lib/types';
  import { SearchApi } from './lib/api';
  import {
    onUrlPop,
    readUrlState,
    syncToUrl,
    type UrlSearchState,
    type UrlSyncOptions,
  } from './lib/urlState';
  import { t } from './lib/i18n';
  import SearchBox from './components/SearchBox.svelte';
  import SortSelect from './components/SortSelect.svelte';
  import FacetPanel from './components/FacetPanel.svelte';
  import YearRangeFacet from './components/YearRangeFacet.svelte';
  import ResultsList from './components/ResultsList.svelte';

  /**
   * One instance per mounted block. Owns the search state (query, page, sort,
   * filters). Seeds from the server-rendered first page so it paints instantly,
   * and — on surfaces that sync — mirrors its state to the URL so a search is
   * shareable and back/forward works (see {@link syncUrl} / {@link urlOptions}).
   */

  interface Props {
    bootstrap: Bootstrap;
    /** Hide the built-in search box (the federated page owns a shared box). */
    showSearchBox?: boolean;
    /**
     * Mirror this block's state (query, sort, facets, page, year) to the URL.
     * Defaults to on for any block that owns its search box; several blocks on a
     * page stay clash-free by namespacing under their own `b{block_id}.` prefix.
     * The federated page passes explicit values: it keeps its reused per-corpus
     * App in URL sync but with a bare prefix and `includeQuery=false`, since the
     * shell owns the shared ?q/?profile.
     */
    syncUrl?: boolean;
    /** URL key namespace. Bare ('') on the federated page; `b{block_id}.` per block. */
    urlPrefix?: string;
    /** Whether this App owns the `q` key (false on the federated page — the shell does). */
    includeQuery?: boolean;
  }

  const {
    bootstrap,
    showSearchBox = true,
    syncUrl: syncUrlProp,
    urlPrefix: urlPrefixProp,
    includeQuery: includeQueryProp,
  }: Props = $props();

  const api = $derived.by(() => new SearchApi(bootstrap.endpoints, bootstrap.profile));

  // URL ↔ state sync config. A block that owns its search box syncs by default;
  // the federated page overrides these so its reused per-corpus App writes the
  // corpus's facets/sort/page/year while the shell keeps ?q/?profile.
  const syncUrl = $derived(syncUrlProp ?? showSearchBox);
  const urlPrefix = $derived(urlPrefixProp ?? `b${bootstrap.block_id}.`);
  const includeQuery = $derived(includeQueryProp ?? true);
  const urlOptions = $derived<UrlSyncOptions>({
    prefix: urlPrefix,
    defaultSort: bootstrap.default_sort ?? 'relevance',
    includeQuery,
  });

  // Year range slider (range profiles only). null at either end = no constraint.
  const showYear = $derived(bootstrap.show_year && bootstrap.year_bounds != null);
  const hasSidebar = $derived(bootstrap.facets.length > 0 || showYear);

  // Compact two-up corpora whose cards vary in height pack better as a masonry
  // than a row-aligned grid (which leaves ragged gaps under short cards). People
  // and organisations always qualify (their role/affiliation chips vary); a term
  // corpus qualifies when it has facets — those Type/role chips are what render on
  // the card, so faceted terms (locations, subjects) are ragged while facet-less
  // ones (genres, languages) are uniform and stay on the grid.
  const masonryLayout = $derived(
    bootstrap.card_kind === 'person' ||
      bootstrap.card_kind === 'organisation' ||
      (bootstrap.card_kind === 'term' && bootstrap.facets.length > 0),
  );
  // A corpus may set its own placeholder (e.g. the authority-term corpora, which
  // share a card kind); otherwise fall back to a kind-derived default.
  const placeholder = $derived(
    bootstrap.search_placeholder?.trim()
      ? bootstrap.search_placeholder
      : bootstrap.card_kind === 'project'
        ? t('search_placeholder_project')
        : bootstrap.card_kind === 'publication'
          ? t('search_placeholder_publication')
          : bootstrap.card_kind === 'podcast'
            ? t('search_placeholder_podcast')
            : bootstrap.card_kind === 'video'
              ? t('search_placeholder_video')
              : bootstrap.card_kind === 'person'
                ? t('search_placeholder_person')
                : bootstrap.card_kind === 'section'
                  ? t('search_placeholder_section')
                  : bootstrap.card_kind === 'organisation'
                    ? t('search_placeholder_organisation')
                    : bootstrap.card_kind === 'term'
                      ? t('search_placeholder_term')
                      : t('search_placeholder'),
  );

  // svelte-ignore state_referenced_locally
  const initialResponse =
    bootstrap.initial_response && Array.isArray(bootstrap.initial_response.hits)
      ? bootstrap.initial_response
      : null;

  // Hydrate initial state from the URL on surfaces that sync; otherwise seed from
  // the bootstrap (the federated page passes its shared query via initial_query).
  // svelte-ignore state_referenced_locally
  const urlInitial = syncUrl ? readUrlState(window.location.href, urlOptions) : null;
  // svelte-ignore state_referenced_locally
  const defaultSort: SortKey = bootstrap.default_sort ?? 'relevance';
  // A URL-seeded sort is validated against this corpus's offered sorts so a stale
  // or hand-edited ?sort= can't wedge the dropdown on an unsupported value.
  // svelte-ignore state_referenced_locally
  const validSorts = new Set((bootstrap.sort_options ?? []).map((o) => o.value));
  // svelte-ignore state_referenced_locally
  const seedQuery =
    syncUrl && includeQuery
      ? urlInitial?.q || (bootstrap.initial_query ?? '')
      : (bootstrap.initial_query ?? '');
  const seedSort: SortKey =
    urlInitial && validSorts.size > 0 && validSorts.has(urlInitial.sort)
      ? urlInitial.sort
      : defaultSort;

  let query = $state(seedQuery);
  let page = $state(urlInitial?.page ?? 1);
  let sort = $state<SortKey>(seedSort);
  let filters = $state<ActiveFilters>(urlInitial?.filters ?? {});
  let yearFrom = $state<number | null>(urlInitial?.yearFrom ?? null);
  let yearTo = $state<number | null>(urlInitial?.yearTo ?? null);

  let response = $state<SearchResponse | null>(initialResponse);
  let isLoading = $state(false);
  let error = $state<string | null>(null);

  // Mobile only: the sidebar is collapsed by default and toggled open. Ignored
  // on wider viewports, where the sidebar is always shown (see styles).
  let facetsOpen = $state(false);

  // Root element, so paging can scroll back to the top of this block's results.
  let rootEl = $state<HTMLElement | undefined>(undefined);

  // Skip the first reactive fetch only when the seed response already matches the
  // seeded state. A URL-hydrated non-pristine state (a shared link with facets, a
  // sort, page 2 or a year) must fetch so the user sees what they asked for, not
  // the "browse everything" snapshot.
  const corpusPristine =
    (urlInitial?.page ?? 1) === 1 &&
    seedSort === defaultSort &&
    Object.keys(urlInitial?.filters ?? {}).length === 0 &&
    (urlInitial?.yearFrom ?? null) === null &&
    (urlInitial?.yearTo ?? null) === null;
  // An own-query block's seed response was rendered for initial_query, so the
  // query must match it too; the federated App's seed is for its shared query
  // (includeQuery=false), which seedQuery already equals.
  // svelte-ignore state_referenced_locally
  const queryMatchesSeed = !includeQuery || seedQuery === (bootstrap.initial_query ?? '');
  let skipNextFetch =
    initialResponse != null && initialResponse.available && corpusPristine && queryMatchesSeed;
  let reqId = 0;

  // Previous URL snapshot, so the sync can choose pushState vs replaceState.
  let prevUrlState: UrlSearchState | null = null;

  // Mirror state → URL whenever anything observable changes. The first run is a
  // no-op (the URL already reflects the seeded state); pagination-only changes
  // replace history, everything else pushes a back-button-able step.
  $effect(() => {
    if (!syncUrl) return;
    const next: UrlSearchState = { q: query, page, sort, filters, yearFrom, yearTo };
    syncToUrl(next, prevUrlState, urlOptions);
    // Plain, proxy-free deep copy for the next diff (filters is a Svelte proxy).
    prevUrlState = {
      q: next.q,
      page: next.page,
      sort: next.sort,
      filters: Object.fromEntries(Object.entries(next.filters).map(([k, v]) => [k, [...v]])),
      yearFrom: next.yearFrom,
      yearTo: next.yearTo,
    };
  });

  // Back / forward → re-hydrate state from the URL. The federated App (includeQuery
  // =false) leaves the shared query to the shell, which remounts it if ?q changed.
  $effect(() => {
    if (!syncUrl) return;
    return onUrlPop((s) => {
      if (includeQuery) query = s.q;
      page = s.page;
      sort = s.sort;
      filters = s.filters;
      yearFrom = s.yearFrom;
      yearTo = s.yearTo;
    }, urlOptions);
  });

  $effect(() => {
    const q = query;
    const p = page;
    const s = sort;
    const f = filters;
    const yf = yearFrom;
    const yt = yearTo;

    if (skipNextFetch) {
      skipNextFetch = false;
      return;
    }

    // Always request counts for the configured facets plus any currently
    // selected field, so a selected value never vanishes from the sidebar.
    const facetFields = Array.from(new Set([...bootstrap.facets, ...Object.keys(f)]));
    const myId = ++reqId;
    isLoading = true;
    error = null;

    api
      .search({
        profile: bootstrap.profile,
        q,
        page: p,
        per_page: bootstrap.per_page,
        sort: s,
        filters: f,
        facets: facetFields,
        locked_filter: bootstrap.locked_filter,
        year_from: yf,
        year_to: yt,
      })
      .then((r) => {
        if (myId !== reqId) {
          return; // a newer search has superseded this one
        }
        response = r;
      })
      .catch((e: Error) => {
        if (myId !== reqId) {
          return;
        }
        console.error('[dre-search] search failed', e);
        error = e.message;
        response = null;
      })
      .finally(() => {
        if (myId === reqId) {
          isLoading = false;
        }
      });
  });

  const facets = $derived(response?.facets ?? []);

  // Sort choices come from the server (they vary by corpus). Fall back to a
  // minimal set for any older bootstrap blob that predates sort_options.
  const sortOptions = $derived<SortOption[]>(
    bootstrap.sort_options && bootstrap.sort_options.length > 0
      ? bootstrap.sort_options
      : [
          { value: 'relevance', label: t('sort_relevance') },
          { value: 'title', label: t('sort_title') },
        ],
  );

  const activeCount = $derived(
    Object.values(filters).reduce((n, values) => n + (values?.length ?? 0), 0) +
      (yearFrom != null || yearTo != null ? 1 : 0),
  );

  function handleQueryChange(next: string): void {
    query = next;
    page = 1;
  }

  function handleSortChange(next: SortKey): void {
    sort = next;
    page = 1;
  }

  function handleFacetToggle(field: string, value: string, checked: boolean): void {
    const current = filters[field] ?? [];
    if (checked) {
      if (!current.includes(value)) {
        filters = { ...filters, [field]: [...current, value] };
      }
    } else {
      const kept = current.filter((v) => v !== value);
      if (kept.length === 0) {
        const next = { ...filters };
        delete next[field];
        filters = next;
      } else {
        filters = { ...filters, [field]: kept };
      }
    }
    page = 1;
  }

  function handleClearAll(): void {
    filters = {};
    yearFrom = null;
    yearTo = null;
    page = 1;
  }

  function handleYearChange(from: number, to: number): void {
    const bounds = bootstrap.year_bounds;
    yearFrom = bounds && from <= bounds.min ? null : from;
    yearTo = bounds && to >= bounds.max ? null : to;
    page = 1;
  }

  function handleAddFilter(field: string, value: string): void {
    handleFacetToggle(field, value, true);
  }

  function handlePageChange(next: number): void {
    page = next;
    // Jump back to the top of this block so the new page starts from the first
    // result instead of leaving the viewport down at the pager.
    if (rootEl) {
      const reduce =
        typeof window !== 'undefined' &&
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
      rootEl.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    }
  }
</script>

<div class="dre-search" bind:this={rootEl}>
  {#if showSearchBox}
    <SearchBox
      value={query}
      {placeholder}
      {api}
      itemUrlBase={bootstrap.item_url_base}
      onQueryChange={handleQueryChange}
    />
  {/if}

  {#if error}
    <div class="dre-search__error" role="alert">
      <strong>{t('search_unavailable')}</strong>
      <span>{error}</span>
    </div>
  {/if}

  {#if response && !response.available}
    <div class="dre-search__notice" role="status">
      <strong>{t('search_unavailable')}</strong>
      <p>{t('search_unavailable_hint')}</p>
    </div>
  {:else}
    {#snippet yearSlider()}
      {#if showYear && bootstrap.year_bounds}
        <YearRangeFacet
          min={bootstrap.year_bounds.min}
          max={bootstrap.year_bounds.max}
          from={yearFrom ?? bootstrap.year_bounds.min}
          to={yearTo ?? bootstrap.year_bounds.max}
          onChange={handleYearChange}
        />
      {/if}
    {/snippet}

    {#if hasSidebar}
      <button
        type="button"
        class="dre-search__facets-toggle"
        aria-expanded={facetsOpen}
        aria-controls="dre-facets-{bootstrap.block_id}"
        onclick={() => (facetsOpen = !facetsOpen)}
      >
        <span>{facetsOpen ? t('hide_filters') : t('show_filters')}</span>
        {#if activeCount > 0}
          <span class="dre-search__facets-toggle-badge">{activeCount}</span>
        {/if}
      </button>
    {/if}

    <div class="dre-search__layout" class:dre-search__layout--no-facets={!hasSidebar}>
      {#if hasSidebar}
        <aside
          id="dre-facets-{bootstrap.block_id}"
          class="dre-search__facets"
          class:dre-search__facets--open={facetsOpen}
          aria-label={t('filters')}
        >
          <FacetPanel
            {facets}
            order={bootstrap.facets}
            labels={bootstrap.facet_labels}
            selected={filters}
            {activeCount}
            onToggle={handleFacetToggle}
            onClearAll={handleClearAll}
            prepend={showYear ? yearSlider : undefined}
          />
        </aside>
      {/if}

      <div class="dre-search__results" aria-busy={isLoading}>
        {#if response}
          <header class="dre-search__toolbar" aria-live="polite">
            <span class="dre-search__count">
              {#if response.found > 0}
                <strong>{response.found.toLocaleString()}</strong>
                {response.found === 1 ? t('result_one') : t('result_other')}
              {:else}
                {t('no_results_title')}
              {/if}
            </span>
            <SortSelect value={sort} options={sortOptions} onChange={handleSortChange} />
          </header>
        {/if}

        {#if isLoading && !response}
          <p class="dre-search__status">{t('searching')}</p>
        {:else if response && response.found === 0}
          <div class="dre-search__empty" role="status">
            <strong>{t('no_results_title')}</strong>
            {#if activeCount > 0}
              <p>{t('try_removing_filter')}</p>
              <button type="button" class="dre-search__clear-link" onclick={handleClearAll}>
                {t('clear_all_filters')}
              </button>
            {:else if query.trim() !== ''}
              <p>{t('try_broader_query')}</p>
            {:else}
              <p>{t('corpus_empty')}</p>
            {/if}
          </div>
        {:else if response}
          <ResultsList
            hits={response.hits}
            found={response.found}
            page={response.page}
            perPage={bootstrap.per_page}
            itemUrlBase={bootstrap.item_url_base}
            cardKind={bootstrap.card_kind}
            masonry={masonryLayout}
            onPageChange={handlePageChange}
            onAddFilter={handleAddFilter}
          />
        {/if}
      </div>
    </div>
  {/if}
</div>

<style>
  .dre-search {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
    color: var(--ink, #33291f);
    font-size: var(--text-base, 1rem);
  }

  .dre-search__layout {
    display: grid;
    /* The min track on each column is 0, not the default `auto` (≈ content
       min-content): without it a long facet label or a wide result card would
       expand its track and overflow the page horizontally. */
    grid-template-columns: minmax(14rem, 17rem) minmax(0, 1fr);
    gap: var(--space-xl, 2rem);
    align-items: start;
  }
  .dre-search__layout--no-facets {
    grid-template-columns: 1fr;
  }

  /* Mobile-only filters toggle — hidden on wider viewports where the sidebar is
     always visible. */
  .dre-search__facets-toggle {
    display: none;
    align-items: center;
    justify-content: center;
    gap: var(--space-xs, 0.5rem);
    width: 100%;
    padding: 0.6rem 0.9rem;
    border: 1px solid var(--border, #dcd6cb);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfcfa);
    color: var(--ink-strong, var(--ink, #33291f));
    font: inherit;
    font-size: var(--text-sm, 0.9rem);
    font-weight: 600;
    cursor: pointer;
    /* Host theme styles every <button> as a filled primary button. */
    box-shadow: none !important;
    transform: none !important;
  }
  .dre-search__facets-toggle:hover {
    border-color: var(--primary, #007a50);
    /* Stay an outline button — the host would fill it green with a white label. */
    background: var(--surface, #fdfcfa) !important;
    color: var(--primary, #007a50) !important;
  }
  .dre-search__facets-toggle-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.25rem;
    height: 1.25rem;
    padding: 0 0.4rem;
    border-radius: var(--radius-full, 9999px);
    background: var(--primary, #007a50);
    color: var(--primary-contrast, #fdfcfa);
    font-size: var(--text-xs, 0.7rem);
    font-weight: 600;
  }

  .dre-search__facets {
    position: sticky;
    top: var(--space-md, 1rem);
    align-self: start;
    /* Let the grid item shrink below its content's min-content width so its
       contents (which truncate internally) can never widen the column. */
    min-width: 0;
    max-height: calc(100vh - var(--space-xl, 2rem));
    overflow-y: auto;
    scrollbar-width: thin;
    /* A left gutter so the rail's controls aren't glued to the page edge; it
       lands the headings on the search box's text edge (both = --space-md). */
    padding-inline: var(--space-md, 1rem);
    border-inline-end: 1px solid var(--border-light, #eae5dd);
  }

  .dre-search__results {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
    min-width: 0;
    transition: opacity var(--transition-base, 200ms ease);
  }
  .dre-search__results[aria-busy='true'] {
    opacity: 0.65;
  }

  .dre-search__toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-md, 1rem);
    flex-wrap: wrap;
    padding-block-end: var(--space-sm, 0.5rem);
    border-bottom: 1px solid var(--border-light, #eae5dd);
  }
  .dre-search__count {
    color: var(--muted, #7a7164);
    font-size: var(--text-sm, 0.9rem);
    font-variant-numeric: tabular-nums;
  }
  .dre-search__count strong {
    color: var(--ink-strong, var(--ink, #33291f));
    font-size: var(--text-lg, 1.125rem);
  }

  .dre-search__error,
  .dre-search__notice {
    border-radius: var(--radius-md, 0.5rem);
    padding: var(--space-md, 1rem);
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-search__error {
    background: color-mix(in srgb, var(--error, #c0392b) 12%, var(--surface, #fdfcfa));
    border: 1px solid color-mix(in srgb, var(--error, #c0392b) 35%, transparent);
    color: var(--ink-strong, var(--ink, #33291f));
  }
  .dre-search__notice {
    background: var(--surface-sunken, #f1ede6);
    border: 1px dashed var(--border, #dcd6cb);
    color: var(--muted, #6c6357);
    text-align: center;
  }
  .dre-search__notice p {
    margin: 0;
  }

  .dre-search__status {
    color: var(--muted, #7a7164);
    font-size: var(--text-sm, 0.9rem);
    margin: 0;
  }

  .dre-search__empty {
    background: var(--surface-sunken, #f6f2eb);
    border: 1px dashed var(--border, #dcd6cb);
    border-radius: var(--radius-md, 0.5rem);
    padding: var(--space-2xl, 3rem) var(--space-lg, 1.5rem);
    text-align: center;
    color: var(--muted, #7a7164);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
  }
  .dre-search__empty strong {
    color: var(--ink-strong, var(--ink, #33291f));
    font-size: var(--text-lg, 1.125rem);
  }
  .dre-search__empty p {
    margin: 0;
  }
  .dre-search__clear-link {
    background: none;
    border: 1px solid var(--primary, #007a50);
    color: var(--primary, #007a50);
    border-radius: var(--radius-md, 0.5rem);
    padding: 0.4rem 0.75rem;
    font-size: var(--text-sm, 0.9rem);
    cursor: pointer;
    margin-top: var(--space-xs, 0.25rem);
    /* Suppress the host primary-button hover lift + glow (the fill is intended). */
    box-shadow: none !important;
    transform: none !important;
  }
  .dre-search__clear-link:hover {
    background: var(--primary, #007a50);
    color: var(--primary-contrast, #fdfcfa);
  }

  @media (max-width: 48rem) {
    .dre-search__layout {
      /* minmax(0, …) again here — the single mobile column must be allowed to
         shrink below content width, or the facet panel overflows the screen. */
      grid-template-columns: minmax(0, 1fr);
      gap: var(--space-md, 1rem);
    }
    .dre-search__facets-toggle {
      display: flex;
    }
    /* Collapsed by default; the toggle reveals it as an inline panel. */
    .dre-search__facets {
      display: none;
      position: static;
      max-height: none;
      overflow: visible;
      padding-inline: 0;
      border-inline-end: none;
      border-bottom: 1px solid var(--border-light, #eae5dd);
      padding-block-end: var(--space-md, 1rem);
    }
    .dre-search__facets--open {
      display: block;
    }
  }
</style>
