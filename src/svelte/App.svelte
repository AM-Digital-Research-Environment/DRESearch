<script lang="ts">
  import type { ActiveFilters, Bootstrap, SearchResponse, SortKey, SortOption } from './lib/types';
  import { SearchApi } from './lib/api';
  import { t } from './lib/i18n';
  import SearchBox from './components/SearchBox.svelte';
  import SortSelect from './components/SortSelect.svelte';
  import FacetPanel from './components/FacetPanel.svelte';
  import YearRangeFacet from './components/YearRangeFacet.svelte';
  import ResultsList from './components/ResultsList.svelte';

  /**
   * One instance per mounted block. Owns the search state (query, page, sort,
   * filters) in memory — there's no URL sync because a page may host several
   * blocks that would otherwise fight over the address bar. Seeds from the
   * server-rendered first page so it paints instantly.
   */

  interface Props {
    bootstrap: Bootstrap;
  }

  const { bootstrap }: Props = $props();

  const api = $derived.by(() => new SearchApi(bootstrap.endpoints, bootstrap.profile));

  // Year range slider (range profiles only). null at either end = no constraint.
  const showYear = $derived(bootstrap.show_year && bootstrap.year_bounds != null);
  const hasSidebar = $derived(bootstrap.facets.length > 0 || showYear);
  // A corpus may set its own placeholder (e.g. the authority-term corpora, which
  // share a card kind); otherwise fall back to a kind-derived default.
  const placeholder = $derived(
    bootstrap.search_placeholder?.trim()
      ? bootstrap.search_placeholder
      : bootstrap.card_kind === 'project'
        ? t('search_placeholder_project')
        : bootstrap.card_kind === 'publication'
          ? t('search_placeholder_publication')
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

  let query = $state('');
  let page = $state(1);
  // svelte-ignore state_referenced_locally
  let sort = $state<SortKey>(bootstrap.default_sort ?? 'relevance');
  let filters = $state<ActiveFilters>({});
  let yearFrom = $state<number | null>(null);
  let yearTo = $state<number | null>(null);

  let response = $state<SearchResponse | null>(initialResponse);
  let isLoading = $state(false);
  let error = $state<string | null>(null);

  // Mobile only: the sidebar is collapsed by default and toggled open. Ignored
  // on wider viewports, where the sidebar is always shown (see styles).
  let facetsOpen = $state(false);

  // Root element, so paging can scroll back to the top of this block's results.
  let rootEl = $state<HTMLElement | undefined>(undefined);

  // The SSR snapshot already reflects the pristine browse state, so skip the
  // first reactive fetch when we have it.
  let skipNextFetch = initialResponse != null && initialResponse.available;
  let reqId = 0;

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
  <SearchBox
    value={query}
    {placeholder}
    {api}
    itemUrlBase={bootstrap.item_url_base}
    onQueryChange={handleQueryChange}
  />

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
    color: var(--ink, #222);
    font-size: var(--text-base, 1rem);
  }

  .dre-search__layout {
    display: grid;
    grid-template-columns: minmax(14rem, 17rem) 1fr;
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
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fff);
    color: var(--ink-strong, var(--ink, #222));
    font: inherit;
    font-size: var(--text-sm, 0.9rem);
    font-weight: 600;
    cursor: pointer;
  }
  .dre-search__facets-toggle:hover {
    border-color: var(--primary, #2a4d8f);
    color: var(--primary, #2a4d8f);
  }
  .dre-search__facets-toggle-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.25rem;
    height: 1.25rem;
    padding: 0 0.4rem;
    border-radius: var(--radius-full, 9999px);
    background: var(--primary, #2a4d8f);
    color: var(--primary-contrast, #fff);
    font-size: var(--text-xs, 0.7rem);
    font-weight: 600;
  }

  .dre-search__facets {
    position: sticky;
    top: var(--space-md, 1rem);
    align-self: start;
    max-height: calc(100vh - var(--space-xl, 2rem));
    overflow-y: auto;
    scrollbar-width: thin;
    padding-inline-end: var(--space-md, 1rem);
    border-inline-end: 1px solid var(--border-light, #eee);
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
    border-bottom: 1px solid var(--border-light, #eee);
  }
  .dre-search__count {
    color: var(--muted, #666);
    font-size: var(--text-sm, 0.9rem);
    font-variant-numeric: tabular-nums;
  }
  .dre-search__count strong {
    color: var(--ink-strong, var(--ink, #222));
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
    background: color-mix(in srgb, var(--error, #c0392b) 12%, var(--surface, #fff));
    border: 1px solid color-mix(in srgb, var(--error, #c0392b) 35%, transparent);
    color: var(--ink-strong, var(--ink, #222));
  }
  .dre-search__notice {
    background: var(--surface-sunken, #f6f6f6);
    border: 1px dashed var(--border, #ccc);
    color: var(--muted, #555);
    text-align: center;
  }
  .dre-search__notice p {
    margin: 0;
  }

  .dre-search__status {
    color: var(--muted, #666);
    font-size: var(--text-sm, 0.9rem);
    margin: 0;
  }

  .dre-search__empty {
    background: var(--surface-sunken, #f9f9f9);
    border: 1px dashed var(--border, #ccc);
    border-radius: var(--radius-md, 0.5rem);
    padding: var(--space-2xl, 3rem) var(--space-lg, 1.5rem);
    text-align: center;
    color: var(--muted, #666);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
  }
  .dre-search__empty strong {
    color: var(--ink-strong, var(--ink, #222));
    font-size: var(--text-lg, 1.125rem);
  }
  .dre-search__empty p {
    margin: 0;
  }
  .dre-search__clear-link {
    background: none;
    border: 1px solid var(--primary, #2a4d8f);
    color: var(--primary, #2a4d8f);
    border-radius: var(--radius-md, 0.5rem);
    padding: 0.4rem 0.75rem;
    font-size: var(--text-sm, 0.9rem);
    cursor: pointer;
    margin-top: var(--space-xs, 0.25rem);
  }
  .dre-search__clear-link:hover {
    background: var(--primary, #2a4d8f);
    color: var(--primary-contrast, #fff);
  }

  @media (max-width: 48rem) {
    .dre-search__layout {
      grid-template-columns: 1fr;
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
      padding-inline-end: 0;
      border-inline-end: none;
      border-bottom: 1px solid var(--border-light, #eee);
      padding-block-end: var(--space-md, 1rem);
    }
    .dre-search__facets--open {
      display: block;
    }
  }
</style>
