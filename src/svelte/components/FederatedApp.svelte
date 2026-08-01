<script lang="ts">
  import type { Bootstrap, FederatedBootstrap, ProfileMeta, SearchResponse } from '../lib/types';
  import { searchAll, searchUnion } from '../lib/api';
  import { readFederatedShell, syncFederatedShell, writeUrlState } from '../lib/urlState';
  import { formatNumber, t } from '../lib/i18n';
  import { installSlashFocus } from '../lib/keyboard';
  import { rememberSearch } from '../lib/searchHistory';
  import App from '../App.svelte';
  import MixedResultCard from './MixedResultCard.svelte';
  import Pagination from './Pagination.svelte';
  import ResultSkeleton from './ResultSkeleton.svelte';
  import CopyLinkButton from './CopyLinkButton.svelte';

  interface Props {
    bootstrap: FederatedBootstrap;
  }
  const { bootstrap }: Props = $props();
  // svelte-ignore state_referenced_locally
  const profiles = bootstrap.profiles;
  const ALL = 'all';
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
  let unionPage = $state(1);
  let isLoading = $state(false);
  let error = $state<string | null>(null);
  let inputTimer: number | null = null;
  let controller: AbortController | null = null;
  let inputEl = $state<HTMLInputElement>();
  let cache: Record<string, SearchResponse> = {};
  let cacheQuery: string | null = null;
  let requestId = 0;
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
    const cacheKey = `${profile}:${page}`;
    if (cache[cacheKey]) {
      if (profile === ALL) unionResponse = cache[cacheKey];
      else activeResponse = cache[cacheKey];
      return;
    }
    const id = ++requestId;
    controller?.abort();
    controller = new AbortController();
    isLoading = true;
    error = null;
    try {
      if (profile === ALL) {
        unionResponse = null;
        const unionPromise = searchUnion(
          bootstrap.endpoints.union,
          { q, page, per_page: 20 },
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
                },
                controller.signal,
              );
        const [merged, countResult] = await Promise.all([unionPromise, countsPromise]);
        if (id !== requestId) return;
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
        const result = await searchAll(
          bootstrap.endpoints.search_all,
          {
            profile,
            q,
            sort: meta?.default_sort,
            per_page: meta?.per_page,
            facets: meta?.facets,
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
      if (id === requestId && (reason as Error).name !== 'AbortError')
        error = (reason as Error).message;
    } finally {
      if (id === requestId) isLoading = false;
    }
  }

  $effect(() => {
    if (bootstrap.available) void load(activeProfile, query, activeProfile === ALL ? unionPage : 1);
  });
  $effect(() => {
    const remove = installSlashFocus(() => inputEl);
    return () => {
      remove();
      controller?.abort();
      if (inputTimer !== null) clearTimeout(inputTimer);
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
    if (target) {
      selectTab(target);
      requestAnimationFrame(() => document.getElementById(`dre-fed-tab-${target}`)?.focus());
    }
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
        search: bootstrap.endpoints.search,
        export: bootstrap.endpoints.export,
        suggest: bootstrap.endpoints.suggest,
        map: bootstrap.endpoints.map,
      },
      initial_response: activeResponse ?? undefined,
      initial_query: query,
    };
  }
  const activeMeta = $derived(metaFor(activeProfile));
  const count = (name: string): string =>
    countsQuery === null ? '' : formatNumber(counts[name] ?? 0);
</script>

<div class="dre-fed">
  <div class="dre-fed__search">
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
      tabindex="0"
    >
      {#if error}<div class="dre-fed__error" role="alert">
          <strong>{t('search_unavailable')}</strong><span>{error}</span>
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
            {query ? t('no_results_for_query', { q: query }) : t('corpus_empty')}
          </div>
        {:else}<ol class="dre-fed__mixed">
            {#each unionResponse.hits as doc (`${doc._profile}:${doc.id}`)}<li>
                <MixedResultCard {doc} itemUrlBase={bootstrap.item_url_base} onHandoff={handoff} />
              </li>{/each}
          </ol>
          <Pagination
            found={unionResponse.found}
            page={unionResponse.page}
            perPage={20}
            onPageChange={(next) => {
              unionPage = next;
              document.getElementById('dre-fed-panel')?.scrollIntoView({ block: 'start' });
            }}
          />{/if}
      {:else if activeMeta && activeResponse}{#key activeProfile + '::' + query}<App
            bootstrap={appBootstrap(activeMeta)}
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
    color: var(--ink, #33291f);
  }
  .dre-fed__search {
    position: relative;
    display: flex;
    max-width: 36rem;
  }
  .dre-fed__search input {
    width: 100%;
    height: 2.75rem;
    margin: 0;
    padding-inline: 1rem 3rem;
    border: 1px solid var(--border, #dcd6cb);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfcfa);
    color: var(--ink, #33291f);
    font: inherit;
  }
  .dre-fed__search input:focus {
    outline: 0;
    border-color: var(--primary, #007a50);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .dre-fed__search > button {
    position: absolute;
    inset-inline-end: 0.5rem;
    top: 0.35rem;
    width: 2rem;
    height: 2rem;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: var(--muted, #938979);
    font-size: 1.25rem;
    cursor: pointer;
  }
  .dre-fed__tabs {
    display: flex;
    overflow-x: auto;
    border-bottom: 1px solid var(--border, #dcd6cb);
  }
  .dre-fed__tabs button {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    flex: none;
    margin: 0;
    padding: 0.65rem 0.8rem;
    border: 0;
    border-bottom: 2px solid transparent;
    background: transparent;
    color: var(--muted, #7a7164);
    font: inherit;
    cursor: pointer;
  }
  .dre-fed__tabs button.active {
    border-bottom-color: var(--primary, #007a50);
    color: var(--ink, #33291f);
    font-weight: 700;
  }
  .dre-fed__tabs small {
    padding: 0.08rem 0.35rem;
    border-radius: 999px;
    background: var(--surface-sunken, #f1ede6);
    font-variant-numeric: tabular-nums;
  }
  .dre-fed__panel {
    min-width: 0;
    outline: none;
  }
  .dre-fed__all-summary {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    justify-content: space-between;
    padding-block: 0.65rem;
    border-block: 1px solid var(--border-light, #eae5dd);
    color: var(--muted, #7a7164);
    font-size: 0.9rem;
  }
  .dre-fed__all-summary strong {
    color: var(--ink, #33291f);
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
    border: 1px solid var(--border-light, #eae5dd);
    border-radius: 0.75rem;
    background: var(--surface, #fdfcfa);
  }
  .dre-fed__error {
    border-color: var(--danger, #b42318);
  }
  @media (max-width: 40rem) {
    .dre-fed__all-summary {
      align-items: flex-start;
      flex-direction: column;
    }
  }
</style>
