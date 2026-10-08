<script lang="ts">
  import type { Bootstrap, FederatedBootstrap, ProfileMeta, SearchResponse } from '../lib/types';
  import { untrack } from 'svelte';
  import { searchAll, searchUnion } from '../lib/api';
  import {
    MAX_PAGE,
    readFederatedShell,
    readUnionPage,
    readUrlState,
    syncFederatedShell,
    syncUnionPage,
    writeUrlState,
  } from '../lib/urlState';
  import { formatNumber, t } from '../lib/i18n';
  import { installSlashFocus } from '../lib/keyboard';
  import { rememberSearch } from '../lib/searchHistory';
  import App from '../App.svelte';
  import MixedResultCard from './MixedResultCard.svelte';
  import Pagination from './Pagination.svelte';
  import ResultSkeleton from './ResultSkeleton.svelte';
  import CopyLinkButton from './CopyLinkButton.svelte';
  import '../styles/buttons.css';
  import { COPY_FEEDBACK_MS, provideAnnouncer } from '../lib/announce';

  interface Props {
    bootstrap: FederatedBootstrap;
  }
  const { bootstrap }: Props = $props();
  // svelte-ignore state_referenced_locally
  const profiles = bootstrap.profiles;
  const ALL = 'all';
  const UNION_PER_PAGE = 20;
  const metaFor = (name: string): ProfileMeta | undefined =>
    profiles.find((profile) => profile.name === name);
  const shell = readFederatedShell();
  const pinned =
    shell.profile && (shell.profile === ALL || profiles.some((p) => p.name === shell.profile))
      ? shell.profile
      : null;
  // svelte-ignore state_referenced_locally
  const seedQuery = shell.q || (bootstrap.initial_query ?? '');
  let query = $state(seedQuery);
  let inputValue = $state(seedQuery);
  // svelte-ignore state_referenced_locally
  let activeProfile = $state(pinned || bootstrap.default_profile || profiles[0]?.name || ALL);
  let counts = $state<Record<string, number>>({});
  let countsQuery = $state<string | null>(null);
  let activeResponse = $state<SearchResponse | null>(null);
  let unionResponse = $state<SearchResponse | null>(null);
  // A shared link to page 3 of "All results" opens on page 3.
  // svelte-ignore state_referenced_locally
  let unionPage = $state(activeProfile === ALL ? readUnionPage() : 1);
  let isLoading = $state(false);
  // A failed request shows a translated message and "Try again"; the detail
  // is logged (api.ts), never shown.
  let failed = $state(false);
  let inputTimer: number | null = null;
  let controller: AbortController | null = null;
  let inputEl = $state<HTMLInputElement>();
  let cache: Record<string, SearchResponse> = {};
  let cacheQuery: string | null = null;
  let requestId = 0;
  // A widget's brief message ("Copied") without the theme: spoken by this
  // surface's status node. An embedded corpus App provides its own.
  let notice = $state('');
  let noticeTimer: number | undefined;
  provideAnnouncer((message) => {
    notice = message;
    window.clearTimeout(noticeTimer);
    noticeTimer = window.setTimeout(() => (notice = ''), COPY_FEEDBACK_MS);
  });
  const tabs = $derived([
    { name: ALL, label: t('all_results') },
    ...profiles.map((p) => ({ name: p.name, label: p.label })),
  ]);

  function commitQuery(next: string): void {
    if (next === query) return;
    syncFederatedShell({ q: next, profile: activeProfile }, false);
    query = next;
    unionPage = 1;
  }
  function onInput(event: Event): void {
    inputValue = (event.currentTarget as HTMLInputElement).value;
    if (inputTimer !== null) clearTimeout(inputTimer);
    inputTimer = window.setTimeout(() => {
      inputTimer = null;
      commitQuery(inputValue.trim());
    }, 300);
  }
  function clearQuery(): void {
    if (inputTimer !== null) clearTimeout(inputTimer);
    inputTimer = null;
    inputValue = '';
    commitQuery('');
  }
  function keySearch(event: KeyboardEvent): void {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    if (inputTimer !== null) clearTimeout(inputTimer);
    inputTimer = null;
    commitQuery(inputValue.trim());
  }

  async function load(profile: string, q: string, page = 1): Promise<void> {
    if (q !== cacheQuery) {
      cache = {};
      cacheQuery = q;
      countsQuery = null;
    }
    const id = ++requestId;
    controller?.abort();
    isLoading = false;
    failed = false;
    const cacheKey = `${profile}:${page}`;
    if (cache[cacheKey]) {
      if (profile === ALL) unionResponse = cache[cacheKey];
      else activeResponse = cache[cacheKey];
      return;
    }
    controller = new AbortController();
    isLoading = true;
    try {
      if (profile === ALL) {
        unionResponse = null;
        const unionPromise = searchUnion(
          bootstrap.endpoints.union,
          { q, page, per_page: UNION_PER_PAGE },
          controller.signal,
        );
        const countsPromise =
          countsQuery === q
            ? Promise.resolve(null)
            : searchAll(
                bootstrap.endpoints.search_all,
                {
                  profile: bootstrap.default_profile || profiles[0]?.name || '',
                  q,
                  per_page: 1,
                  include_counts: true,
                  record_query: false,
                },
                controller.signal,
              );
        const [merged, countResult] = await Promise.all([unionPromise, countsPromise]);
        if (id !== requestId) return;
        // A stale link past the last page lands on the last one instead of an
        // empty list under a non-zero count.
        const lastPage = Math.min(MAX_PAGE, Math.max(1, Math.ceil(merged.found / UNION_PER_PAGE)));
        if (page > lastPage) {
          syncUnionPage(lastPage);
          unionPage = lastPage;
          return;
        }
        unionResponse = merged;
        cache[cacheKey] = merged;
        if (q.trim() && merged.found > 0) rememberSearch(q);
        if (countResult) {
          counts = countResult.counts;
          countsQuery = q;
        }
      } else {
        activeResponse = null;
        const meta = metaFor(profile);
        // Ask for the corpus state already in the URL (a deep link, or a card
        // chip's hand-off): the embedded App then starts from this response
        // instead of discarding it and searching again with the filters.
        const corpus = readUrlState(window.location.href, {
          includeQuery: false,
          defaultSort: meta?.default_sort ?? 'relevance',
        });
        const validSort = meta?.sort_options?.some((o) => o.value === corpus.sort)
          ? corpus.sort
          : meta?.default_sort;
        const result = await searchAll(
          bootstrap.endpoints.search_all,
          {
            profile,
            q,
            sort: validSort,
            page: corpus.page,
            per_page: meta?.per_page,
            facets: meta?.facets,
            filters: corpus.filters,
            year_from: corpus.yearFrom,
            year_to: corpus.yearTo,
            include_counts: countsQuery !== q,
          },
          controller.signal,
        );
        if (id !== requestId) return;
        activeResponse = result.active;
        cache[cacheKey] = result.active;
        if (Object.keys(result.counts).length) {
          counts = result.counts;
          countsQuery = q;
        }
      }
    } catch (reason) {
      if (id === requestId && (reason as Error).name !== 'AbortError') {
        console.error('[dre-search] federated search failed', reason);
        failed = true;
      }
    } finally {
      if (id === requestId) isLoading = false;
    }
  }

  // Track only what selects the request. load() reads countsQuery (and writes
  // state) before its first await; tracking that re-ran this effect when the
  // counts arrived and re-fed the embedded App a "new" initial response.
  $effect(() => {
    const profile = activeProfile;
    const q = query;
    const page = activeProfile === ALL ? unionPage : 1;
    if (bootstrap.available) untrack(() => void load(profile, q, page));
  });
  $effect(() => {
    const remove = installSlashFocus(() => inputEl);
    return () => {
      remove();
      controller?.abort();
      if (inputTimer !== null) clearTimeout(inputTimer);
      window.clearTimeout(noticeTimer);
    };
  });
  $effect(() => {
    const onPop = (): void => {
      const value = readFederatedShell();
      query = value.q;
      inputValue = value.q;
      activeProfile =
        value.profile && (value.profile === ALL || profiles.some((p) => p.name === value.profile))
          ? value.profile
          : bootstrap.default_profile || profiles[0]?.name || ALL;
      unionPage = activeProfile === ALL ? readUnionPage() : 1;
    };
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  });

  function selectTab(name: string): void {
    if (name === activeProfile) return;
    syncFederatedShell({ q: query, profile: name }, true);
    activeProfile = name;
    unionPage = 1;
  }
  /**
   * MANUAL ACTIVATION (WAI-ARIA tabs), as the shared contract asks of tabs
   * whose switch costs a network request (DRE-theme docs/DESIGN-INTEGRATION.md,
   * "Shared widgets"): arrow keys, Home and End move focus between the
   * thirteen tabs, Enter or Space selects. Selecting on every arrow press
   * pushed a history entry and fired a federated search per key.
   */
  function tabKey(event: KeyboardEvent, name: string): void {
    const index = tabs.findIndex((tab) => tab.name === name);
    let next: number;
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % tabs.length;
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp')
      next = (index - 1 + tabs.length) % tabs.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = tabs.length - 1;
    else return;
    event.preventDefault();
    const target = tabs[next]?.name;
    if (target) document.getElementById(`dre-fed-tab-${target}`)?.focus();
  }
  function handoff(profile: string, field?: string, value?: string): void {
    const meta = metaFor(profile);
    if (!meta) return;
    syncFederatedShell({ q: query, profile }, true);
    if (field && value) {
      const search = writeUrlState(
        {
          q: '',
          page: 1,
          sort: meta.default_sort,
          filters: { [field]: [value] },
          yearFrom: null,
          yearTo: null,
          view: null,
        },
        { includeQuery: false, defaultSort: meta.default_sort },
        window.location.search,
      );
      window.history.replaceState(window.history.state, '', `${window.location.pathname}${search}`);
    }
    activeProfile = profile;
  }
  function appBootstrap(meta: ProfileMeta): Bootstrap {
    return {
      block_id: null,
      profile: meta.name,
      card_kind: meta.kind,
      search_placeholder: meta.placeholder ?? null,
      date_mode: meta.date_mode,
      show_year: meta.show_year,
      year_bounds: meta.year_bounds,
      facets: meta.facets,
      facet_labels: meta.facet_labels,
      default_sort: meta.default_sort,
      sort_options: meta.sort_options,
      per_page: meta.per_page,
      item_url_base: bootstrap.item_url_base,
      endpoints: {
        facet: bootstrap.endpoints.facet,
        search: bootstrap.endpoints.search,
        export: bootstrap.endpoints.export,
        suggest: bootstrap.endpoints.suggest,
        map: bootstrap.endpoints.map,
      },
      initial_response: activeResponse ?? undefined,
      initial_query: query,
      // load() requested the URL's corpus state, so the App need not refetch.
      initial_state_applied: true,
    };
  }
  const activeMeta = $derived(metaFor(activeProfile));
  function retry(): void {
    void load(activeProfile, query, activeProfile === ALL ? unionPage : 1);
  }
  // This surface's one persistent status node: it speaks while the shell owns
  // the panel (loading, the merged list, a failure). Once a corpus's App is
  // mounted, that App's own status node takes over and this one falls silent.
  const announcement = $derived(
    notice
      ? notice
      : isLoading
        ? t('loading')
        : failed
          ? t('search_unavailable')
          : activeProfile === ALL && unionResponse
            ? unionResponse.found === 0
              ? query
                ? t('no_results_title')
                : t('corpus_empty')
              : `${formatNumber(unionResponse.found)} ${unionResponse.found === 1 ? t('result_one') : t('result_other')}`
            : '',
  );
  const count = (name: string): string =>
    countsQuery === null ? '' : formatNumber(counts[name] ?? 0);
</script>

<div class="dre-fed">
  <div class="dre-fed__search" role="search">
    <input
      bind:this={inputEl}
      name="q"
      type="search"
      autocomplete="off"
      spellcheck="false"
      inputmode="search"
      aria-label={t('search_all_placeholder')}
      placeholder={t('search_all_placeholder')}
      value={inputValue}
      oninput={onInput}
      onkeydown={keySearch}
    />{#if inputValue}<button type="button" aria-label={t('clear_search')} onclick={clearQuery}
        >×</button
      >{/if}
  </div>
  {#if !bootstrap.available}<div class="dre-fed__notice" role="status">
      <strong>{t('search_unavailable')}</strong>
      <p>{t('search_unavailable_hint')}</p>
    </div>
  {:else}
    <label class="dre-fed__chooser">
      <span>{t('result_types')}</span>
      <select value={activeProfile} onchange={(event) => selectTab(event.currentTarget.value)}>
        {#each tabs as tab (tab.name)}
          <option value={tab.name}
            >{tab.label}{tab.name === ALL
              ? unionResponse
                ? ' (' + formatNumber(unionResponse.found) + ')'
                : ''
              : count(tab.name)
                ? ' (' + count(tab.name) + ')'
                : ''}</option
          >
        {/each}
      </select>
    </label>
    <!-- Print only: the theme's print sheet hides every <button>, which would
         drop the one label that says which corpus these results come from. -->
    <p class="dre-fed__print-label">
      {t('result_types')}: {tabs.find((tab) => tab.name === activeProfile)?.label}
    </p>
    <div class="dre-fed__tabs" role="tablist" aria-label={t('result_types')}>
      {#each tabs as tab (tab.name)}<button
          type="button"
          role="tab"
          id="dre-fed-tab-{tab.name}"
          aria-selected={tab.name === activeProfile}
          aria-controls="dre-fed-panel"
          class:active={tab.name === activeProfile}
          tabindex={tab.name === activeProfile ? 0 : -1}
          onclick={() => selectTab(tab.name)}
          onkeydown={(event) => tabKey(event, tab.name)}
          ><span>{tab.label}</span>{#if tab.name === ALL && unionResponse}<small
              >{formatNumber(unionResponse.found)}</small
            >{:else if tab.name !== ALL && count(tab.name)}<small>{count(tab.name)}</small
            >{/if}</button
        >{/each}
    </div>
    <div
      class="dre-fed__panel"
      id="dre-fed-panel"
      role="tabpanel"
      aria-labelledby="dre-fed-tab-{activeProfile}"
      aria-busy={isLoading}
      tabindex="0"
    >
      <p class="dre-fed__sr-only" role="status" aria-live="polite" aria-atomic="true">
        {announcement}
      </p>
      {#if failed}<div class="dre-fed__error">
          <strong>{t('search_unavailable')}</strong>
          <button type="button" class="dre-button-secondary" onclick={retry}
            >{t('try_again')}</button
          >
        </div>
      {:else if isLoading}<ResultSkeleton count={activeProfile === ALL ? 8 : 6} />
      {:else if activeProfile === ALL && unionResponse}
        <header class="dre-fed__all-summary">
          <span
            ><strong>{formatNumber(unionResponse.found)}</strong>
            {unionResponse.found === 1 ? t('result_one') : t('result_other')}</span
          ><span>{t('all_no_facets')}</span><CopyLinkButton />
        </header>
        {#if unionResponse.found === 0}<div class="dre-fed__empty">
            {query ? t('no_results_title') : t('corpus_empty')}
          </div>
        {:else}<ol class="dre-fed__mixed">
            {#each unionResponse.hits as doc (`${doc._profile}:${doc.id}`)}<li>
                <MixedResultCard {doc} itemUrlBase={bootstrap.item_url_base} onHandoff={handoff} />
              </li>{/each}
          </ol>
          <Pagination
            found={unionResponse.found}
            page={unionResponse.page}
            perPage={UNION_PER_PAGE}
            onPageChange={(next) => {
              syncUnionPage(next);
              unionPage = next;
              document.getElementById('dre-fed-panel')?.scrollIntoView({ block: 'start' });
            }}
          />{/if}
      {:else if activeMeta && activeResponse}{#key activeProfile + '::' + query}<App
            bootstrap={untrack(() => appBootstrap(activeMeta))}
            showSearchBox={false}
            syncUrl={true}
            urlPrefix=""
            includeQuery={false}
          />{/key}{/if}
    </div>
  {/if}
</div>

<style>
  .dre-fed {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
    color: var(--ink, #3c342d);
  }
  .dre-fed__search {
    position: relative;
    display: flex;
    max-width: 36rem;
  }
  .dre-fed__search input {
    width: 100%;
    height: var(--size-control-lg, 2.75rem);
    margin: 0;
    padding-inline: 1rem 3rem;
    border: 1px solid var(--field-border, #8b857f);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfcf9);
    color: var(--ink, #3c342d);
    font: inherit;
  }
  .dre-fed__search input:focus {
    /* The theme's field focus (DRE-theme base/elements/_fields.scss): the ring is
       a box-shadow, which forced-colors mode drops, so the outline stays —
       transparent — and is painted in the system focus colour there. */
    outline: 2px solid transparent;
    border-color: var(--primary, #007a50);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }
  .dre-fed__search > button {
    position: absolute;
    inset-inline-end: 0;
    top: 0;
    width: var(--size-control-lg, 2.75rem);
    height: var(--size-control-lg, 2.75rem);
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: var(--muted, #716a66);
    font-size: var(--text-lg, 1.1875rem);
    cursor: pointer;
  }
  .dre-fed__search > button:focus-visible {
    outline: 2px solid var(--focus-color, #007a50);
    outline-offset: 2px;
  }
  /* Desktop exposes corpus tabs; narrow screens use the labelled chooser. */
  .dre-fed__chooser {
    display: none;
  }
  @media (max-width: 48rem) {
    .dre-fed__chooser {
      display: grid;
      gap: var(--space-1, 0.25rem);
    }
    .dre-fed__chooser select {
      width: 100%;
      min-height: var(--size-control-lg, 2.75rem);
      margin: 0;
      padding: var(--space-2, 0.5rem);
      font: inherit;
      color: var(--ink, #3c342d);
      background: var(--surface, #fdfcf9);
      border: 1px solid var(--field-border, #8b857f);
      border-radius: var(--radius-md, 0.5rem);
    }
    .dre-fed__chooser select:focus {
      outline: 2px solid transparent;
      border-color: var(--primary, #007a50);
      box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
    }
    /* Doubled class, not !important: it must outrank the flex rule below. */
    .dre-fed__tabs.dre-fed__tabs {
      display: none;
    }
  }
  .dre-fed__tabs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-1, 0.25rem);
    padding-block-end: var(--space-2, 0.5rem);
    border-bottom: 1px solid var(--border, #dbd7d1);
  }
  /* The thirteen corpus tabs are a dense, repeated control: they use the WCAG
     2.2 spacing exception instead of 44px boxes (DRE-theme integration
     contract, "Touch-target contract"). No !important: the theme stopped
     painting bare <button>s (DRE-theme base/elements/_buttons.scss), so these
     single-class rules hold on their own. */
  .dre-fed__tabs button {
    display: flex;
    align-items: center;
    gap: var(--space-1, 0.25rem);
    flex: none;
    margin: 0;
    padding: var(--space-1, 0.25rem) var(--space-3, 0.75rem);
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-full, 9999px);
    background: var(--surface, #fdfcf9);
    box-shadow: none;
    transform: none;
    color: var(--muted, #716a66);
    font: inherit;
    /* em, not rem: the host theme's body face runs at 17px, and a chip strip this
       dense wants to sit just under it rather than at an unrelated absolute size. */
    font-size: 0.9em;
    line-height: var(--leading-snug, 1.25);
    cursor: pointer;
  }
  .dre-fed__tabs button:hover {
    border-color: var(--primary, #007a50);
    color: var(--primary, #007a50);
  }
  .dre-fed__tabs button.active,
  .dre-fed__tabs button.active:hover {
    border-color: var(--primary, #007a50);
    background: var(--primary, #007a50);
    color: var(--primary-contrast, #fcfcf9);
    font-weight: 600;
  }
  .dre-fed__tabs button:focus-visible {
    outline: 2px solid var(--focus-color, #007a50);
    outline-offset: 2px;
  }
  .dre-fed__tabs small {
    padding: 0 var(--space-1, 0.25rem);
    border-radius: var(--radius-full, 9999px);
    background: var(--surface-sunken, #f3f0eb);
    color: var(--muted, #716a66);
    font-size: var(--text-xs, 0.8125rem);
    line-height: var(--leading-snug, 1.25);
    font-variant-numeric: tabular-nums;
  }
  /* Outline, not fill. The badge sits on the filled pill, so any tint pulls its
     background toward the label's own colour and eats the contrast the number
     needs: a 26% mix measured 4.0:1 in dark mode and 3.3:1 in light, and even 10%
     stayed under AA in light. Unfilled, the count keeps the label's full ratio
     (6.5:1 dark / 5.2:1 light) and the ring still reads as a chip. */
  .dre-fed__tabs button.active small {
    background: none;
    border: 1px solid color-mix(in srgb, currentColor 45%, transparent);
    color: inherit;
  }
  .dre-fed__panel {
    min-width: 0;
  }
  /* The panel is focusable (tabindex=0, per the tabs pattern), so it keeps a
     visible focus indicator rather than `outline: none`. */
  .dre-fed__panel:focus-visible {
    outline: 2px solid var(--focus-color, #007a50);
    outline-offset: 2px;
  }
  .dre-fed__all-summary {
    display: flex;
    align-items: center;
    gap: var(--space-3, 0.75rem);
    flex-wrap: wrap;
    justify-content: space-between;
    padding-block: var(--space-3, 0.75rem);
    border-block: 1px solid var(--border-light, #eae8e3);
    color: var(--muted, #716a66);
    font-size: var(--text-sm, 0.9375rem);
  }
  .dre-fed__all-summary strong {
    color: var(--ink, #3c342d);
  }
  .dre-fed__mixed {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    list-style: none;
    margin: 1rem 0 0;
    padding: 0;
  }
  .dre-fed__empty,
  .dre-fed__notice,
  .dre-fed__error {
    padding: 1rem;
    border: 1px solid var(--border-light, #eae8e3);
    border-radius: 0.75rem;
    background: var(--surface, #fdfcf9);
  }
  .dre-fed__error {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2, 0.5rem);
    /* --error, not --danger: the theme has never defined --danger, so this was
       permanently on its fallback and painted the same cold red in both modes. */
    border-color: var(--error, #cc272e);
  }
  .dre-fed__sr-only {
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
  .dre-fed__print-label {
    display: none;
  }
  /* Print: the results and the corpus they come from, not the search field,
     the chooser, the tab strip or the paging (DRE-theme integration contract,
     "Print"). */
  @media print {
    .dre-fed__search,
    .dre-fed__chooser,
    .dre-fed__tabs,
    .dre-fed__error {
      display: none;
    }
    .dre-fed__print-label {
      display: block;
      margin: 0;
      font-weight: 600;
    }
  }
  @media (max-width: 37.5rem) {
    .dre-fed__all-summary {
      align-items: flex-start;
      flex-direction: column;
    }
  }
</style>
