<script lang="ts">
  import type {
    ActiveFilters,
    Bootstrap,
    MapResponse,
    SearchResponse,
    SortKey,
    SortOption,
    ViewMode,
  } from './lib/types';
  import { SearchApi } from './lib/api';
  import {
    onUrlPop,
    readUrlState,
    syncToUrl,
    type UrlSearchState,
    type UrlSyncOptions,
  } from './lib/urlState';
  import { t } from './lib/i18n';
  import { buildFilterChips, type FilterChipModel as ChipModel } from './lib/filterChips';
  import { rememberSearch } from './lib/searchHistory';
  import SearchBox from './components/SearchBox.svelte';
  import SortSelect from './components/SortSelect.svelte';
  import ExportMenu from './components/ExportMenu.svelte';
  import FacetPanel from './components/FacetPanel.svelte';
  import YearRangeFacet from './components/YearRangeFacet.svelte';
  import ResultsList from './components/ResultsList.svelte';
  import ResultSummary from './components/ResultSummary.svelte';
  import ResultSkeleton from './components/ResultSkeleton.svelte';
  import ViewToggle from './components/ViewToggle.svelte';
  import CopyLinkButton from './components/CopyLinkButton.svelte';
  import MapView from './components/MapView.svelte';

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

  const api = $derived.by(
    () => new SearchApi(bootstrap.endpoints, bootstrap.profile, bootstrap.block_id),
  );

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
  const viewOptions = $derived<ViewMode[]>(
    bootstrap.profile === 'research_items'
      ? ['list', 'gallery']
      : bootstrap.profile === 'research_locations'
        ? ['list', 'map']
        : ['list'],
  );
  // svelte-ignore state_referenced_locally
  const viewStorageKey = `dre-search:view:${bootstrap.profile}`;
  const storedView = (() => {
    try {
      const stored = localStorage.getItem(viewStorageKey) as ViewMode | null;
      return stored && viewOptions.includes(stored) ? stored : null;
    } catch {
      return null;
    }
  })();
  // svelte-ignore state_referenced_locally
  const explicitInitialView =
    urlInitial?.view && viewOptions.includes(urlInitial.view) ? urlInitial.view : storedView;
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
  let view = $state<ViewMode>(explicitInitialView ?? 'list');
  let viewExplicit = explicitInitialView !== null;

  let response = $state<SearchResponse | null>(initialResponse);
  let isLoading = $state(false);
  let error = $state<string | null>(null);
  let mapResponse = $state<MapResponse | null>(null);
  let mapLoading = $state(false);
  let correction = $state<string | null>(null);

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
    const next: UrlSearchState = { q: query, page, sort, filters, yearFrom, yearTo, view };
    syncToUrl(next, prevUrlState, urlOptions);
    // Plain, proxy-free deep copy for the next diff (filters is a Svelte proxy).
    prevUrlState = {
      q: next.q,
      page: next.page,
      sort: next.sort,
      filters: Object.fromEntries(Object.entries(next.filters).map(([k, v]) => [k, [...v]])),
      yearFrom: next.yearFrom,
      yearTo: next.yearTo,
      view: next.view,
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
      if (s.view && viewOptions.includes(s.view)) view = s.view;
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
    const controller = new AbortController();
    isLoading = true;
    error = null;

    api
      .search(
        {
          q,
          page: p,
          per_page: bootstrap.per_page,
          sort: s,
          filters: f,
          facets: facetFields,
          year_from: yf,
          year_to: yt,
        },
        controller.signal,
      )
      .then((r) => {
        if (myId !== reqId) {
          return; // a newer search has superseded this one
        }
        const lastPage = Math.max(1, Math.ceil(r.found / bootstrap.per_page));
        if (p > lastPage) {
          page = lastPage;
          return;
        }
        response = r;
        if (q.trim() && r.found > 0) rememberSearch(q);
        if (
          !viewExplicit &&
          view === 'list' &&
          bootstrap.profile === 'research_items' &&
          r.hits.length >= 4
        ) {
          const ratio = r.hits.filter((hit) => Boolean(hit.thumbnail_url)).length / r.hits.length;
          if (ratio > 0.6) view = 'gallery';
          viewExplicit = true; // one suggestion per mount, regardless of outcome
        }
        correction = null;
        if (r.found === 0 && q.trim().length >= 2) {
          void api
            .suggest(q, controller.signal)
            .then((suggestions) => {
              if (myId === reqId) correction = suggestions[0]?.title ?? null;
            })
            .catch(() => undefined);
        }
      })
      .catch((e: Error) => {
        if (myId !== reqId) {
          return;
        }
        if (e.name === 'AbortError') return;
        console.error('[dre-search] search failed', e);
        error = e.message;
        response = null;
      })
      .finally(() => {
        if (myId === reqId) {
          isLoading = false;
        }
      });

    return () => controller.abort();
  });

  $effect(() => {
    if (view !== 'map') {
      mapResponse = null;
      mapLoading = false;
      return;
    }
    const controller = new AbortController();
    mapLoading = true;
    api
      .map({ q: query, sort, filters, year_from: yearFrom, year_to: yearTo }, controller.signal)
      .then((result) => {
        mapResponse = result;
      })
      .catch((reason: Error) => {
        if (reason.name !== 'AbortError') error = reason.message;
      })
      .finally(() => {
        if (!controller.signal.aborted) mapLoading = false;
      });
    return () => controller.abort();
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
  const scopeChips = $derived(
    buildFilterChips(filters, bootstrap.facet_labels, yearFrom, yearTo, query),
  );

  function handleQueryChange(next: string): void {
    query = next;
    page = 1;
  }

  function handleSortChange(next: SortKey): void {
    sort = next;
    page = 1;
  }

  // Export fetch: the CURRENT result set (query + filters + sort + year window),
  // capped server-side. Handed to the ExportMenu, which serializes + downloads.
  function handleExportFetch(): ReturnType<SearchApi['export']> {
    return api.export({
      q: query,
      sort,
      filters,
      year_from: yearFrom,
      year_to: yearTo,
    });
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

  function handleRemoveChip(chip: ChipModel): void {
    if (chip.kind === 'query') handleQueryChange('');
    else if (chip.kind === 'year') {
      yearFrom = null;
      yearTo = null;
      page = 1;
    } else handleFacetToggle(chip.field, chip.value, false);
  }

  function handleViewChange(next: ViewMode): void {
    if (!viewOptions.includes(next)) return;
    view = next;
    viewExplicit = true;
    try {
      localStorage.setItem(viewStorageKey, next);
    } catch {
      /* optional preference */
    }
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

  function toggleFacets(): void {
    facetsOpen = !facetsOpen;
    if (facetsOpen) {
      requestAnimationFrame(() => {
        rootEl
          ?.querySelector<HTMLElement>('.dre-search__facets input, .dre-search__facets button')
          ?.focus();
      });
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
      instanceId={String(bootstrap.block_id ?? bootstrap.profile)}
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
        onclick={toggleFacets}
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
          {#snippet summaryTools()}
            <SortSelect value={sort} options={sortOptions} onChange={handleSortChange} />
            {#if viewOptions.length > 1}<ViewToggle
                value={view}
                options={viewOptions}
                onChange={handleViewChange}
              />{/if}
            <CopyLinkButton />
            {#if (response?.found ?? 0) > 0}
              <ExportMenu
                fetchDocs={handleExportFetch}
                {query}
                found={response?.found ?? 0}
                kind={bootstrap.card_kind}
                itemUrlBase={bootstrap.item_url_base}
                {filters}
                {yearFrom}
                {yearTo}
                facetLabels={bootstrap.facet_labels}
              />
            {/if}
          {/snippet}
          <ResultSummary
            found={view === 'map' ? (mapResponse?.found ?? response.found) : response.found}
            chips={scopeChips}
            onRemove={handleRemoveChip}
            tools={summaryTools}
          />
        {/if}

        {#if isLoading}
          <ResultSkeleton {view} count={view === 'gallery' ? 8 : 6} />
        {:else if response && response.found === 0}
          <div class="dre-search__empty" role="status">
            <strong>{t('no_results_title')}</strong>
            {#if activeCount > 0}
              <p>{t('try_removing_filter')}</p>
              <button type="button" class="dre-search__clear-link" onclick={handleClearAll}>
                {t('clear_all_filters')}
              </button>
            {:else if query.trim() !== ''}
              <p>{t('no_results_for_query', { q: query })}</p>
              {#if correction}
                <button
                  type="button"
                  class="dre-search__clear-link"
                  onclick={() => handleQueryChange(correction ?? '')}
                >
                  {t('did_you_mean', { q: correction })}
                </button>
              {:else}<p>{t('try_broader_query')}</p>{/if}
            {:else}
              <p>{t('corpus_empty')}</p>
            {/if}
          </div>
        {:else if response && view === 'map'}
          <MapView
            docs={mapResponse?.docs ?? []}
            loading={mapLoading}
            capped={mapResponse?.capped ?? false}
            itemUrlBase={bootstrap.item_url_base}
          />
        {:else if response}
          <ResultsList
            hits={response.hits}
            found={response.found}
            page={response.page}
            perPage={bootstrap.per_page}
            itemUrlBase={bootstrap.item_url_base}
            cardKind={bootstrap.card_kind}
            masonry={masonryLayout}
            {view}
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
  }
  .dre-search__facets-toggle:hover {
    border-color: var(--primary, #007a50);
    /* Stay an outline button — the host would fill it green with a white label. */
    background: var(--surface, #fdfcfa);
    color: var(--primary, #007a50);
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
