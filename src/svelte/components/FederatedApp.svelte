<script lang="ts">
  import type { Bootstrap, FederatedBootstrap, ProfileMeta, SearchResponse } from '../lib/types';
  import { searchAll } from '../lib/api';
  import { readFederatedShell, syncFederatedShell } from '../lib/urlState';
  import { formatNumber, t } from '../lib/i18n';
  import App from '../App.svelte';

  /**
   * The federated results page. One instance per page.
   *
   * Owns a shared query + the active corpus. A single /search-all round-trip
   * gives per-corpus counts (the type tabs) plus the active corpus's full
   * faceted response, which seeds a reused per-corpus {@link App} (its own search
   * box suppressed). Switching tabs / changing the query re-runs search-all;
   * within a corpus, the reused App handles facets, sort and paging itself.
   */

  interface Props {
    bootstrap: FederatedBootstrap;
  }

  const { bootstrap }: Props = $props();

  // svelte-ignore state_referenced_locally
  const profiles = bootstrap.profiles;
  const metaFor = (name: string): ProfileMeta | undefined => profiles.find((p) => p.name === name);

  // Hydrate the shared query + active corpus from the URL so a federated search is
  // shareable / bookmarkable. The reused per-corpus App reads its own facets/sort/
  // page/year from the (bare) URL; the shell owns ?q + ?profile.
  const shell = readFederatedShell();
  const pinnedProfile =
    shell.profile && profiles.some((p) => p.name === shell.profile) ? shell.profile : null;
  // svelte-ignore state_referenced_locally
  const seedQuery = shell.q || (bootstrap.initial_query ?? '');

  let query = $state(seedQuery);
  let inputValue = $state(seedQuery);
  // svelte-ignore state_referenced_locally
  let activeProfile = $state(
    pinnedProfile || bootstrap.default_profile || (profiles[0]?.name ?? ''),
  );
  let counts = $state<Record<string, number>>({});
  let activeResponse = $state<SearchResponse | null>(null);
  let isLoading = $state(false);
  let error = $state<string | null>(null);

  // Per-query cache of corpus responses so revisiting a tab is instant.
  let cache: Record<string, SearchResponse> = {};
  let cacheQuery: string | null = null;
  // $state because the tab count labels (countLabel) read it in the template.
  let countsQuery = $state<string | null>(null);
  // A URL-pinned profile counts as a deliberate choice — don't auto-jump off it.
  let autoSelected = pinnedProfile !== null;
  let reqId = 0;
  let inputTimer: number | null = null;
  let controller: AbortController | null = null;

  function onInput(e: Event): void {
    inputValue = (e.target as HTMLInputElement).value;
    if (inputTimer !== null) {
      clearTimeout(inputTimer);
    }
    inputTimer = window.setTimeout(() => {
      inputTimer = null;
      commitQuery(inputValue.trim());
    }, 300);
  }

  function clearQuery(): void {
    if (inputTimer !== null) {
      clearTimeout(inputTimer);
      inputTimer = null;
    }
    inputValue = '';
    commitQuery('');
  }

  function handleSearchKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    if (inputTimer !== null) {
      clearTimeout(inputTimer);
      inputTimer = null;
    }
    commitQuery(inputValue.trim());
  }

  /**
   * Commit a new shared query. Update the URL first — which also resets the active
   * corpus's facets/sort/page (a fresh query starts clean) — so the per-corpus App
   * re-seeds from a clean URL when the query change remounts it. Replace, not push:
   * typing isn't a back-button-able step.
   */
  function commitQuery(next: string): void {
    if (next === query) return;
    syncFederatedShell({ q: next, profile: activeProfile }, false);
    query = next;
  }

  async function load(profile: string, q: string): Promise<void> {
    if (q !== cacheQuery) {
      cache = {};
      cacheQuery = q;
    }
    if (cache[profile]) {
      activeResponse = cache[profile];
      isLoading = false;
      return;
    }
    const myId = ++reqId;
    controller?.abort();
    controller = new AbortController();
    activeResponse = null; // hide the stale corpus; show "searching"
    isLoading = true;
    error = null;
    try {
      // Ask for the corpus's own default sort / page size / facets so the
      // returned `active` page matches the state the reused App seeds with (it
      // skips its first fetch when seeded), and so its facet sidebar is populated.
      const meta = metaFor(profile);
      const res = await searchAll(
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
      if (myId !== reqId) {
        return;
      }
      if (countsQuery !== q && Object.keys(res.counts).length > 0) {
        counts = res.counts;
        countsQuery = q;
        maybeAutoSelect();
      }
      cache[profile] = res.active;
      activeResponse = res.active;
    } catch (e) {
      if (myId !== reqId) {
        return;
      }
      if ((e as Error).name === 'AbortError') return;
      error = (e as Error).message;
      activeResponse = null;
    } finally {
      if (myId === reqId) {
        isLoading = false;
      }
    }
  }

  /** On first counts, if the default corpus is empty, land on the richest one. */
  function maybeAutoSelect(): void {
    if (autoSelected) {
      return;
    }
    autoSelected = true;
    if ((counts[activeProfile] ?? 0) > 0) {
      return;
    }
    let best = activeProfile;
    let bestN = counts[activeProfile] ?? 0;
    for (const p of profiles) {
      const n = counts[p.name] ?? 0;
      if (n > bestN) {
        best = p.name;
        bestN = n;
      }
    }
    if (best !== activeProfile && bestN > 0) {
      // Reflect the auto-chosen corpus in the URL (replace — not a user nav).
      syncFederatedShell({ q: query, profile: best }, false);
      activeProfile = best; // re-triggers the effect → load(best)
    }
  }

  $effect(() => {
    const p = activeProfile;
    const q = query;
    if (bootstrap.available) {
      void load(p, q);
    }
  });

  $effect(() => {
    return () => {
      controller?.abort();
      if (inputTimer !== null) clearTimeout(inputTimer);
    };
  });

  // Back / forward → re-hydrate the shared query + active corpus from the URL. The
  // per-corpus App restores its own facets (via its own popstate handler, or a
  // remount when the query/profile changed here).
  $effect(() => {
    const onPop = (): void => {
      const s = readFederatedShell();
      const profile =
        s.profile && profiles.some((p) => p.name === s.profile)
          ? s.profile
          : bootstrap.default_profile || (profiles[0]?.name ?? '');
      query = s.q;
      inputValue = s.q;
      activeProfile = profile;
    };
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  });

  function selectTab(name: string): void {
    if (name === activeProfile) {
      return;
    }
    autoSelected = true; // a manual choice disables the one-shot auto-jump
    // Push a back-button-able step and reset the previous corpus's facet/sort/page
    // (facet fields don't carry across corpora) before the remount re-seeds.
    syncFederatedShell({ q: query, profile: name }, true);
    activeProfile = name;
  }

  function handleTabKeydown(event: KeyboardEvent, name: string): void {
    const index = profiles.findIndex((profile) => profile.name === name);
    if (index < 0) return;
    let next: number;
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown')
      next = (index + 1) % profiles.length;
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp')
      next = (index - 1 + profiles.length) % profiles.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = profiles.length - 1;
    else return;
    event.preventDefault();
    const nextName = profiles[next]?.name;
    if (!nextName) return;
    selectTab(nextName);
    requestAnimationFrame(() => document.getElementById(`dre-fed-tab-${nextName}`)?.focus());
  }

  /** Synthesise a per-corpus Bootstrap so the reused App renders that corpus. */
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
      },
      initial_response: activeResponse ?? undefined,
      initial_query: query,
    };
  }

  const activeMeta = $derived(metaFor(activeProfile));
  const countLabel = (name: string): string =>
    countsQuery === null ? '' : formatNumber(counts[name] ?? 0);
</script>

<div class="dre-fed">
  <div class="dre-fed__search">
    <input
      name="q"
      class="dre-fed__input"
      type="search"
      autocomplete="off"
      spellcheck="false"
      inputmode="search"
      aria-label={t('search_all_placeholder')}
      placeholder={t('search_all_placeholder')}
      value={inputValue}
      oninput={onInput}
      onkeydown={handleSearchKeydown}
    />
    {#if inputValue !== ''}
      <button
        type="button"
        class="dre-fed__clear"
        aria-label={t('clear_search')}
        onclick={clearQuery}>×</button
      >
    {/if}
  </div>

  {#if !bootstrap.available || (activeResponse !== null && !activeResponse.available)}
    <div class="dre-fed__notice" role="status">
      <strong>{t('search_unavailable')}</strong>
      <p>{t('search_unavailable_hint')}</p>
    </div>
  {:else}
    <div class="dre-fed__tabs" role="tablist" aria-label={t('result_types')}>
      {#each profiles as p (p.name)}
        <button
          type="button"
          role="tab"
          id="dre-fed-tab-{p.name}"
          aria-selected={p.name === activeProfile}
          aria-controls="dre-fed-panel"
          class="dre-fed__tab"
          class:dre-fed__tab--active={p.name === activeProfile}
          class:dre-fed__tab--empty={countsQuery !== null && (counts[p.name] ?? 0) === 0}
          tabindex={p.name === activeProfile ? 0 : -1}
          onclick={() => selectTab(p.name)}
          onkeydown={(event) => handleTabKeydown(event, p.name)}
        >
          <span class="dre-fed__tab-label">{p.label}</span>
          {#if countLabel(p.name) !== ''}
            <span class="dre-fed__tab-count">{countLabel(p.name)}</span>
          {/if}
        </button>
      {/each}
    </div>

    <div
      class="dre-fed__panel"
      id="dre-fed-panel"
      role="tabpanel"
      aria-labelledby="dre-fed-tab-{activeProfile}"
      tabindex="0"
    >
      {#if error}
        <div class="dre-fed__error" role="alert">
          <strong>{t('search_unavailable')}</strong>
          <span>{error}</span>
        </div>
      {:else if isLoading && !activeResponse}
        <p class="dre-fed__status">{t('searching')}</p>
      {:else if activeMeta && activeResponse}
        {#key activeProfile + '::' + query}
          <App
            bootstrap={appBootstrap(activeMeta)}
            showSearchBox={false}
            syncUrl={true}
            urlPrefix=""
            includeQuery={false}
          />
        {/key}
      {/if}
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

  /* Shared query box. */
  .dre-fed__search {
    position: relative;
    display: flex;
    align-items: center;
    max-width: 36rem;
  }
  .dre-fed__input {
    width: 100%;
    height: var(--size-control-lg, 2.75rem);
    padding-inline: var(--space-md, 1rem) var(--space-2xl, 3rem);
    margin: 0;
    font-size: var(--text-base, 1rem);
    color: var(--ink, #33291f);
    background: var(--surface, #fdfcfa);
    border: 1px solid var(--border, #dcd6cb);
    border-radius: var(--radius-md, 0.5rem);
  }
  .dre-fed__input:focus {
    outline: none;
    border-color: var(--primary, #007a50);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .dre-fed__input::-webkit-search-cancel-button {
    -webkit-appearance: none;
    appearance: none;
    display: none;
  }
  .dre-fed__clear {
    position: absolute;
    inset-inline-end: var(--space-sm, 0.5rem);
    top: 50%;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--muted, #938979);
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
    border-radius: var(--radius-full, 9999px);
  }
  .dre-fed__clear:hover {
    background: color-mix(in srgb, currentColor 16%, transparent);
    color: var(--ink, #33291f);
  }

  /* Type tabs. */
  .dre-fed__tabs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.4rem);
    padding-block-end: var(--space-sm, 0.5rem);
    border-bottom: 1px solid var(--border-light, #eae5dd);
  }
  /*
   * Tabs read as quiet chips with their own surface/border; the active tab uses
   * the brand colour on purpose.
   */
  .dre-fed__tab {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.8rem;
    margin: 0;
    border: 1px solid var(--border, #dcd6cb);
    border-radius: var(--radius-full, 9999px);
    background: var(--surface, #fdfcfa);
    color: var(--ink, #33291f);
    font: inherit;
    font-size: var(--text-sm, 0.9rem);
    line-height: 1.2;
    cursor: pointer;
  }
  .dre-fed__tab:hover {
    border-color: var(--primary, #007a50);
    color: var(--primary, #007a50);
    background: var(--surface, #fdfcfa);
  }
  .dre-fed__tab--active,
  .dre-fed__tab--active:hover {
    background: var(--primary, #007a50);
    border-color: var(--primary, #007a50);
    color: var(--primary-contrast, #fdfcfa);
    font-weight: 600;
  }
  .dre-fed__tab--empty:not(.dre-fed__tab--active) {
    opacity: 0.55;
  }
  .dre-fed__tab:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.15));
  }
  .dre-fed__tab-count {
    font-variant-numeric: tabular-nums;
    font-size: var(--text-xs, 0.75rem);
    opacity: 0.85;
  }

  .dre-fed__status {
    color: var(--muted, #7a7164);
    font-size: var(--text-sm, 0.9rem);
    margin: 0;
  }
  .dre-fed__notice,
  .dre-fed__error {
    border-radius: var(--radius-md, 0.5rem);
    padding: var(--space-md, 1rem);
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-fed__notice {
    background: var(--surface-sunken, #f1ede6);
    border: 1px dashed var(--border, #dcd6cb);
    color: var(--muted, #6c6357);
    text-align: center;
  }
  .dre-fed__notice p {
    margin: 0;
  }
  .dre-fed__error {
    background: color-mix(in srgb, var(--error, #c0392b) 12%, var(--surface, #fdfcfa));
    border: 1px solid color-mix(in srgb, var(--error, #c0392b) 35%, transparent);
  }
</style>
