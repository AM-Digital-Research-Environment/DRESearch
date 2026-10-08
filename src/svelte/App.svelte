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
  import { untrack } from 'svelte';
  import { SearchApi } from './lib/api';
  import {
    onUrlPop,
    readUrlState,
    syncToUrl,
    type UrlSearchState,
    type UrlSyncOptions,
  } from './lib/urlState';
  import { formatNumber, t } from './lib/i18n';
  import { buildFilterChips, type FilterChipModel as ChipModel } from './lib/filterChips';
  import { rememberSearch } from './lib/searchHistory';
  import { provideHeadingLevel, type HeadingLevel } from './lib/headings';
  import { COPY_FEEDBACK_MS, provideAnnouncer } from './lib/announce';
  import SearchBox from './components/SearchBox.svelte';
  import SortSelect from './components/SortSelect.svelte';
  import ExportMenu from './components/ExportMenu.svelte';
  import FacetPanel from './components/FacetPanel.svelte';
  import YearRangeFacet from './components/YearRangeFacet.svelte';
  import ResultsList from './components/ResultsList.svelte';
  import ResultSummary from './components/ResultSummary.svelte';
  import ResultSkeleton from './components/ResultSkeleton.svelte';
  import ViewToggle from './components/ViewToggle.svelte';
  import ResultActions from './components/ResultActions.svelte';
  import CopyLinkButton from './components/CopyLinkButton.svelte';
  import MapView from './components/MapView.svelte';
  import './styles/buttons.css';

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
    /**
     * Level of this surface's headings: 3 under a titled block's <h2>, else 2
     * (an untitled block, the federated page under its <h1>). Card titles sit
     * one level below. See lib/headings.ts.
     */
    headingLevel?: HeadingLevel;
  }

  const {
    bootstrap,
    showSearchBox = true,
    syncUrl: syncUrlProp,
    urlPrefix: urlPrefixProp,
    includeQuery: includeQueryProp,
    headingLevel = 2,
  }: Props = $props();
  // svelte-ignore state_referenced_locally
  provideHeadingLevel(headingLevel);

  // A widget's brief message ("Copied") when the theme's shared status region
  // is absent: spoken by this surface's one status node, then cleared.
  let notice = $state('');
  let noticeTimer: number | undefined;
  provideAnnouncer((message) => {
    notice = message;
    window.clearTimeout(noticeTimer);
    noticeTimer = window.setTimeout(() => (notice = ''), COPY_FEEDBACK_MS);
  });
  $effect(() => () => window.clearTimeout(noticeTimer));

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
  // Failed requests: the visitor sees a translated message and "Try again",
  // which bumps the matching attempt counter so its effect runs once more.
  // The technical detail is logged by api.ts, never shown.
  let failed = $state(false);
  let attempt = $state(0);
  let mapResponse = $state<MapResponse | null>(null);
  let mapLoading = $state(false);
  let mapFailed = $state(false);
  let mapAttempt = $state(0);
  let correction = $state<string | null>(null);
  // Visually hidden heading the results region is announced by; focus lands
  // here after paging or removing filters, so it never falls back to <body>.
  let resultsHeading = $state<HTMLElement | undefined>(undefined);
  // The scope (everything but the page) of the response on screen: a page-only
  // change keeps its facet counts instead of recounting every facet.
  let shownScope: string | null = null;

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
  // The federated page requests the URL's corpus state itself and says so.
  // svelte-ignore state_referenced_locally
  const seedAppliesUrl = bootstrap.initial_state_applied === true;
  let skipNextFetch =
    initialResponse != null &&
    initialResponse.available &&
    (corpusPristine || seedAppliesUrl) &&
    queryMatchesSeed;
  let reqId = 0;

  // Previous URL snapshot, so the sync can choose pushState vs replaceState.
  let prevUrlState: UrlSearchState | null = null;

  // Facet value search reads the CURRENT scope when it runs. A stable function
  // (not a $derived closure) matters: a new closure on every filter change made
  // each facet group reset its searched list, so ticking a value in a searched
  // list emptied it and dropped focus. Groups watch facetScopeKey instead.
  function searchFacetValues(field: string, value: string, signal: AbortSignal) {
    return api.facet(
      {
        q: query,
        sort,
        filters,
        year_from: yearFrom,
        year_to: yearTo,
        page: 1,
        per_page: 1,
        facets: bootstrap.facets,
      },
      field,
      value,
      signal,
    );
  }
  const facetScopeKey = $derived(JSON.stringify([query, filters, yearFrom, yearTo]));
  // The map draws from /map; the list request then only feeds the facets and
  // the count, so it asks for a single hit. A $derived so that switching
  // between list and gallery (same value) does not refetch.
  const listHits = $derived(view === 'map' ? 1 : bootstrap.per_page);

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
      // Assign only what changed: popstate also fires for a #fragment link or
      // another block's history step, which must not refetch this block.
      const nextSort = validSorts.size === 0 || validSorts.has(s.sort) ? s.sort : defaultSort;
      // The URL omits the default view, so "no view" means list — otherwise
      // Back from map/gallery kept the old view and wrote it back to the URL.
      const nextView = s.view && viewOptions.includes(s.view) ? s.view : 'list';
      if (includeQuery && s.q !== query) query = s.q;
      if (s.page !== page) page = s.page;
      if (nextSort !== sort) sort = nextSort;
      if (JSON.stringify(s.filters) !== JSON.stringify(filters)) filters = s.filters;
      if (s.yearFrom !== yearFrom) yearFrom = s.yearFrom;
      if (s.yearTo !== yearTo) yearTo = s.yearTo;
      if (nextView !== view) view = nextView;
    }, urlOptions);
  });

  $effect(() => {
    const q = query;
    const p = page;
    const s = sort;
    const f = filters;
    const yf = yearFrom;
    const yt = yearTo;
    const hitsWanted = listHits;
    void attempt;

    if (skipNextFetch) {
      skipNextFetch = false;
      shownScope = JSON.stringify([q, s, f, yf, yt]);
      return;
    }

    const scope = JSON.stringify([q, s, f, yf, yt]);
    const previous = untrack(() => response);
    // Paging through the same scope: the facet counts cannot change.
    const keepFacets = scope === shownScope && previous !== null && previous.available;
    // Card chips may filter display fields that are not sidebar facets.
    const facetFields = keepFacets ? [] : bootstrap.facets;
    const myId = ++reqId;
    const controller = new AbortController();
    isLoading = true;
    failed = false;

    api
      .search(
        {
          q,
          page: p,
          per_page: hitsWanted,
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
        if (p > lastPage && hitsWanted === bootstrap.per_page) {
          page = lastPage;
          return;
        }
        response = keepFacets && previous ? { ...r, facets: previous.facets } : r;
        shownScope = scope;
        if (q.trim() && r.found > 0) rememberSearch(q);
        if (
          !viewExplicit &&
          view === 'list' &&
          bootstrap.profile === 'research_items' &&
          r.hits.length >= 4
        ) {
          const ratio = r.hits.filter((hit) => Boolean(hit.thumbnail_url)).length / r.hits.length;
          if (ratio > 0.6) {
            // The visitor did not ask for this switch: record it as already in
            // the URL snapshot so the sync REPLACES history instead of adding
            // a Back step they never took.
            if (prevUrlState) prevUrlState = { ...prevUrlState, view: 'gallery' };
            view = 'gallery';
          }
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
        failed = true;
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
      mapFailed = false;
      return;
    }
    const scope = { q: query, sort, filters, year_from: yearFrom, year_to: yearTo };
    void mapAttempt;
    const controller = new AbortController();
    mapLoading = true;
    mapFailed = false;
    // Debounced like typing: the map endpoint pulls up to 1,000 documents.
    const timer = window.setTimeout(() => {
      api
        .map(scope, controller.signal)
        .then((result) => {
          if (controller.signal.aborted) return;
          mapResponse = result;
        })
        .catch((reason: Error) => {
          if (reason.name === 'AbortError') return;
          console.error('[dre-search] map request failed', reason);
          mapFailed = true;
        })
        .finally(() => {
          if (!controller.signal.aborted) mapLoading = false;
        });
    }, 300);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  });

  /** Move focus to the results heading without jumping the page. */
  function focusResults(): void {
    requestAnimationFrame(() => resultsHeading?.focus({ preventScroll: true }));
  }

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

  // A search or a filter that matches nothing, as against a corpus that is
  // simply empty.
  const emptyTitle = $derived(
    query.trim() !== '' || activeCount > 0 ? t('no_results_title') : t('corpus_empty'),
  );
  // The one persistent, polite, atomic status node of this surface (DRE-theme
  // integration contract, "Asynchronous states"): "Loading…", the result
  // count, the empty message or the failure — never a stray number or the
  // contents of an open menu. The skeleton and the visible empty/error boxes
  // are not live regions of their own.
  const announcement = $derived(
    notice
      ? notice
      : isLoading
        ? t('loading')
        : failed
          ? t('search_unavailable')
          : response && response.available
            ? response.found === 0
              ? emptyTitle
              : `${formatNumber(response.found)} ${response.found === 1 ? t('result_one') : t('result_other')}`
            : '',
  );

  function retry(): void {
    attempt++;
    // The button that was focused is about to be replaced by the results.
    focusResults();
  }

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
    // The control that was focused just removed itself.
    focusResults();
  }

  function handleRemoveChip(chip: ChipModel): void {
    if (chip.kind === 'query') handleQueryChange('');
    else if (chip.kind === 'year') {
      yearFrom = null;
      yearTo = null;
      page = 1;
    } else handleFacetToggle(chip.field, chip.value, false);
    focusResults();
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
    focusResults();
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
        class="dre-search__facets-toggle dre-button-secondary"
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
            searchValues={bootstrap.endpoints.facet ? searchFacetValues : undefined}
            scopeKey={facetScopeKey}
            {facets}
            order={bootstrap.facets}
            labels={bootstrap.facet_labels}
            selected={filters}
            {activeCount}
            {headingLevel}
            onToggle={handleFacetToggle}
            onClearAll={handleClearAll}
            prepend={showYear ? yearSlider : undefined}
          />
        </aside>
      {/if}

      <div class="dre-search__results" aria-busy={isLoading}>
        <svelte:element
          this={`h${headingLevel}`}
          class="dre-search__sr-only"
          tabindex="-1"
          bind:this={resultsHeading}
        >
          {t('search_results')}
        </svelte:element>
        <p class="dre-search__sr-only" role="status" aria-live="polite" aria-atomic="true">
          {announcement}
        </p>
        {#if failed}
          <div class="dre-search__error">
            <strong>{t('search_unavailable')}</strong>
            <button type="button" class="dre-button-secondary" onclick={retry}
              >{t('try_again')}</button
            >
          </div>
        {/if}
        {#if response}
          {#snippet summaryTools()}
            <SortSelect value={sort} options={sortOptions} onChange={handleSortChange} />
            {#if viewOptions.length > 1}<ViewToggle
                value={view}
                options={viewOptions}
                onChange={handleViewChange}
              />{/if}
            <ResultActions>
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
            </ResultActions>
          {/snippet}
          <ResultSummary
            found={view === 'map' ? (mapResponse?.found ?? response.found) : response.found}
            chips={scopeChips}
            onRemove={handleRemoveChip}
            tools={summaryTools}
          />
        {/if}

        {#if isLoading && !response}
          <ResultSkeleton {view} count={view === 'gallery' ? 8 : 6} />
        {:else if response && response.found === 0 && !isLoading}
          <div class="dre-search__empty">
            <strong>{emptyTitle}</strong>
            {#if activeCount > 0}
              <p>{t('try_removing_filter')}</p>
              <button
                type="button"
                class="dre-search__clear-link dre-button-secondary"
                onclick={handleClearAll}
              >
                {t('clear_all_filters')}
              </button>
            {:else if query.trim() !== ''}
              {#if correction}
                <button
                  type="button"
                  class="dre-search__clear-link dre-button-secondary"
                  onclick={() => handleQueryChange(correction ?? '')}
                >
                  {t('did_you_mean', { q: correction })}
                </button>
              {:else}<p>{t('try_broader_query')}</p>{/if}
            {/if}
          </div>
        {:else if response && view === 'map'}
          {#if mapFailed}
            <div class="dre-search__error">
              <strong>{t('map_error')}</strong>
              <button type="button" class="dre-button-secondary" onclick={() => mapAttempt++}
                >{t('try_again')}</button
              >
            </div>
          {/if}
          <MapView
            docs={mapResponse?.docs ?? []}
            loading={mapLoading}
            capped={mapResponse?.capped ?? false}
            found={mapResponse?.found ?? 0}
            mapped={mapResponse?.mapped ?? 0}
            itemUrlBase={bootstrap.item_url_base}
          />
        {:else if response}
          <!-- Previous results stay visible, dimmed, while the next page or scope
               loads: swapping them for a skeleton dropped focus and jumped the
               page. -->
          <div class="dre-search__stale" class:dre-search__stale--loading={isLoading}>
            <ResultsList
              hits={response.hits}
              found={response.found}
              page={response.page}
              perPage={bootstrap.per_page}
              itemUrlBase={bootstrap.item_url_base}
              cardKind={bootstrap.card_kind}
              profile={bootstrap.profile}
              masonry={masonryLayout}
              {view}
              onPageChange={handlePageChange}
              onAddFilter={handleAddFilter}
            />
          </div>
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
    color: var(--ink, #3c342d);
    font-size: var(--text-base, 1.0625rem);
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
     always visible. The shared secondary button (styles/buttons.css). */
  .dre-search__facets-toggle {
    display: none;
    gap: var(--space-xs, 0.25rem);
    width: 100%;
  }
  .dre-search__facets-toggle-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.25rem;
    height: 1.25rem;
    padding: 0 var(--space-2, 0.5rem);
    border-radius: var(--radius-full, 9999px);
    background: var(--primary, #007a50);
    color: var(--primary-contrast, #fcfcf9);
    font-size: var(--text-xs, 0.8125rem);
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
    scrollbar-color: var(--border-strong, #bfbab3) transparent;
    /* A left gutter so the rail's controls aren't glued to the page edge; it
       lands the headings on the search box's text edge (both = --space-md). */
    padding-inline: var(--space-md, 1rem);
    border-inline-end: 1px solid var(--border-light, #eae8e3);
  }

  .dre-search__results {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
    min-width: 0;
  }
  .dre-search__sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: 0;
    padding: 0;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
  }
  .dre-search__stale {
    transition: opacity 0.15s ease;
  }
  .dre-search__stale--loading {
    opacity: 0.55;
    pointer-events: none;
  }
  @media (prefers-reduced-motion: reduce) {
    .dre-search__stale {
      transition: none;
    }
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
    flex-direction: row;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
  }
  .dre-search__error {
    background: color-mix(in srgb, var(--error, #cc272e) 12%, var(--surface, #fdfcf9));
    border: 1px solid color-mix(in srgb, var(--error, #cc272e) 35%, transparent);
    color: var(--ink-strong, #261d15);
  }
  .dre-search__notice {
    background: var(--surface-sunken, #f3f0eb);
    border: 1px dashed var(--border, #dbd7d1);
    color: var(--muted, #716a66);
    text-align: center;
  }
  .dre-search__notice p {
    margin: 0;
  }

  .dre-search__empty {
    background: var(--surface-sunken, #f3f0eb);
    border: 1px dashed var(--border, #dbd7d1);
    border-radius: var(--radius-md, 0.5rem);
    padding: var(--space-2xl, 3rem) var(--space-lg, 1.5rem);
    text-align: center;
    color: var(--muted, #716a66);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
  }
  .dre-search__empty strong {
    color: var(--ink-strong, #261d15);
    font-size: var(--text-lg, 1.1875rem);
  }
  .dre-search__empty p {
    margin: 0;
  }
  .dre-search__clear-link {
    margin-top: var(--space-xs, 0.25rem);
  }

  /* Print: the results print, their controls do not — without leaning on the
     theme's global print rule that hides every button (DRE-theme integration
     contract, "Print"). */
  @media print {
    .dre-search__facets,
    .dre-search__facets-toggle,
    .dre-search__error,
    .dre-search__clear-link {
      display: none;
    }
    .dre-search__layout {
      grid-template-columns: minmax(0, 1fr);
    }
    .dre-search__stale--loading {
      opacity: 1;
    }
  }

  @media (max-width: 767px) {
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
      border-bottom: 1px solid var(--border-light, #eae8e3);
      padding-block-end: var(--space-md, 1rem);
    }
    .dre-search__facets--open {
      display: block;
    }
  }
</style>
