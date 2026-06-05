<script module lang="ts">
  // Per-instance id seed. The theme header mounts this bar more than once
  // (a collapsible mobile one + the desktop one), so every generated id must be
  // unique per instance — otherwise the suggestions listbox and its options
  // collide on duplicate ids and aria-controls/activedescendant break.
  let instanceSeq = 0;
  function nextInstanceId(): number {
    instanceSeq += 1;
    return instanceSeq;
  }
</script>

<script lang="ts">
  import type { SearchBarBootstrap, SuggestGroup } from '../lib/types';
  import { suggestAll } from '../lib/api';
  import { t } from '../lib/i18n';

  /**
   * The theme header's federated search box.
   *
   * - Typing (debounced) fetches title suggestions across EVERY corpus from
   *   /suggest-all; the dropdown groups them by corpus with a type label.
   * - A suggestion is a shortcut straight to that record's Omeka page.
   * - Enter with nothing highlighted (or the "See all results" row) navigates to
   *   the federated results page with the typed query.
   * - `collapsible` (mobile): renders a magnifier button that expands the input
   *   as an overlay, so the box doesn't crowd a narrow header.
   */

  interface Props {
    bootstrap: SearchBarBootstrap;
  }

  const { bootstrap }: Props = $props();

  let local = $state('');
  let groups = $state<SuggestGroup[]>([]);
  let open = $state(false);
  let focused = $state(false);
  // svelte-ignore state_referenced_locally
  let expanded = $state(!bootstrap.collapsible);
  let activeIndex = $state(-1);
  let timer: number | null = null;
  let controller: AbortController | null = null;
  let inputEl = $state<HTMLInputElement | undefined>(undefined);

  // Flat view of all suggestions, for keyboard nav across groups.
  const flat = $derived(groups.flatMap((g) => g.suggestions));
  // Running index of each group's first suggestion in `flat`.
  const offsets = $derived.by(() => {
    const acc: number[] = [];
    let n = 0;
    for (const g of groups) {
      acc.push(n);
      n += g.suggestions.length;
    }
    return acc;
  });

  function itemUrl(id: string): string {
    return `${bootstrap.item_url_base}/${encodeURIComponent(id)}`;
  }

  function resultsUrl(q: string): string {
    const sep = bootstrap.results_url.includes('?') ? '&' : '?';
    return `${bootstrap.results_url}${sep}q=${encodeURIComponent(q)}`;
  }

  // Unique per-instance id namespace (see the module script above).
  const uid = `dre-bar-${nextInstanceId()}`;
  const inputId = `${uid}-input`;
  const suggestId = `${uid}-suggest`;
  const optionId = (i: number): string => `${uid}-opt-${i}`;

  async function fetchSuggestions(q: string): Promise<void> {
    if (q.trim().length < 2) {
      groups = [];
      open = false;
      return;
    }
    controller?.abort();
    controller = new AbortController();
    const res = await suggestAll(bootstrap.endpoints.suggest_all, q, controller.signal);
    groups = res;
    activeIndex = -1;
    open = focused && res.length > 0;
  }

  function schedule(q: string): void {
    if (timer !== null) {
      clearTimeout(timer);
    }
    timer = window.setTimeout(() => {
      timer = null;
      void fetchSuggestions(q);
    }, 250);
  }

  function handleInput(e: Event): void {
    local = (e.target as HTMLInputElement).value;
    schedule(local);
  }

  function handleClear(): void {
    local = '';
    groups = [];
    open = false;
    if (timer !== null) {
      clearTimeout(timer);
      timer = null;
    }
    inputEl?.focus();
  }

  function goItem(id: string): void {
    window.location.href = itemUrl(id);
  }

  function submit(): void {
    const q = local.trim();
    if (q === '') {
      return;
    }
    window.location.href = resultsUrl(q);
  }

  function handleKeydown(e: KeyboardEvent): void {
    const has = open && flat.length > 0;
    switch (e.key) {
      case 'ArrowDown':
        if (has) {
          e.preventDefault();
          activeIndex = (activeIndex + 1) % flat.length;
        }
        break;
      case 'ArrowUp':
        if (has) {
          e.preventDefault();
          activeIndex = (activeIndex - 1 + flat.length) % flat.length;
        }
        break;
      case 'Enter':
        e.preventDefault();
        if (has && activeIndex >= 0 && activeIndex < flat.length) {
          goItem(flat[activeIndex].id);
        } else {
          submit();
        }
        break;
      case 'Escape':
        open = false;
        activeIndex = -1;
        if (bootstrap.collapsible) {
          expanded = false;
        }
        break;
    }
  }

  function handleFocus(): void {
    focused = true;
    if (flat.length > 0) {
      open = true;
    }
  }

  function handleBlur(): void {
    focused = false;
    // Defer so a click on a suggestion lands before the list unmounts.
    window.setTimeout(() => {
      open = false;
      if (bootstrap.collapsible && local.trim() === '') {
        expanded = false;
      }
    }, 150);
  }

  function expand(): void {
    expanded = true;
  }

  // Focus the input when it expands (mobile toggle).
  $effect(() => {
    if (expanded && bootstrap.collapsible) {
      inputEl?.focus();
    }
  });
</script>

<div
  class="dre-search-bar"
  class:dre-search-bar--collapsible={bootstrap.collapsible}
  class:dre-search-bar--expanded={expanded}
>
  {#if bootstrap.collapsible && !expanded}
    <button
      type="button"
      class="dre-search-bar__toggle"
      aria-label={bootstrap.placeholder || t('search_all_placeholder')}
      aria-expanded="false"
      onclick={expand}
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        width="20"
        height="20"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <circle cx="11" cy="11" r="8" />
        <path d="m21 21-4.3-4.3" />
      </svg>
    </button>
  {/if}

  {#if expanded}
    <div class="dre-search-bar__input-wrap">
      <span class="dre-search-bar__icon" aria-hidden="true">
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="18"
          height="18"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
        >
          <circle cx="11" cy="11" r="8" />
          <path d="m21 21-4.3-4.3" />
        </svg>
      </span>
      <input
        bind:this={inputEl}
        id={inputId}
        name="q"
        class="dre-search-bar__input"
        type="search"
        autocomplete="off"
        autocapitalize="off"
        spellcheck="false"
        inputmode="search"
        role="combobox"
        aria-label={bootstrap.placeholder || t('search_all_placeholder')}
        aria-autocomplete="list"
        aria-expanded={open}
        aria-controls={suggestId}
        aria-activedescendant={open && activeIndex >= 0 ? optionId(activeIndex) : undefined}
        placeholder={bootstrap.placeholder || t('search_all_placeholder')}
        value={local}
        oninput={handleInput}
        onfocus={handleFocus}
        onblur={handleBlur}
        onkeydown={handleKeydown}
      />
      {#if local !== ''}
        <button
          type="button"
          class="dre-search-bar__clear"
          aria-label={t('clear_search')}
          onmousedown={(e) => e.preventDefault()}
          onclick={handleClear}>×</button
        >
      {/if}
    </div>

    {#if open && groups.length > 0}
      <div
        class="dre-search-bar__suggest"
        id={suggestId}
        role="listbox"
        aria-label={t('suggestions')}
      >
        {#if local.trim() !== ''}
          <a
            class="dre-search-bar__see-all"
            href={resultsUrl(local.trim())}
            onmousedown={(e) => e.preventDefault()}
          >
            {t('see_all_results', { q: local.trim() })}
          </a>
        {/if}

        {#each groups as g, gi (g.profile)}
          <div class="dre-search-bar__group" role="group" aria-label={g.label}>
            <div class="dre-search-bar__group-label" aria-hidden="true">{g.label}</div>
            {#each g.suggestions as s, si (s.id)}
              {@const idx = offsets[gi] + si}
              <a
                class="dre-search-bar__option"
                class:dre-search-bar__option--active={idx === activeIndex}
                id={optionId(idx)}
                href={itemUrl(s.id)}
                role="option"
                aria-selected={idx === activeIndex}
                onmousedown={(e) => e.preventDefault()}
                onclick={(e) => {
                  e.preventDefault();
                  goItem(s.id);
                }}
              >
                <span class="dre-search-bar__option-title">{s.title}</span>
                {#if s.subtitle}
                  <span class="dre-search-bar__option-meta">{s.subtitle}</span>
                {/if}
              </a>
            {/each}
          </div>
        {/each}
      </div>
    {/if}
  {/if}
</div>

<style>
  .dre-search-bar {
    position: relative;
    width: 100%;
  }

  /* ── Mobile toggle (collapsed) ───────────────────────────────────────────── */
  .dre-search-bar__toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--size-control-md, 2.5rem);
    height: var(--size-control-md, 2.5rem);
    padding: 0;
    margin: 0;
    border: 0;
    background: transparent !important;
    box-shadow: none !important;
    color: var(--ink, #33291f) !important;
    cursor: pointer;
    border-radius: var(--radius-full, 9999px);
    transform: none !important;
  }
  .dre-search-bar__toggle:hover {
    background: color-mix(in srgb, currentColor 12%, transparent) !important;
    color: var(--ink-strong, var(--ink, #33291f)) !important;
  }
  .dre-search-bar__toggle:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.15)) !important;
  }

  /* When collapsible & expanded, float the input as an overlay so it never
     reflows the header cluster. Desktop (non-collapsible) keeps it in flow. */
  .dre-search-bar--collapsible.dre-search-bar--expanded .dre-search-bar__input-wrap {
    position: absolute;
    inset-inline-end: 0;
    top: 50%;
    transform: translateY(-50%);
    width: min(20rem, 80vw);
    z-index: var(--z-dropdown, 100);
  }

  .dre-search-bar__input-wrap {
    position: relative;
    display: flex;
    align-items: center;
  }
  .dre-search-bar__icon {
    position: absolute;
    inset-inline-start: var(--space-sm, 0.5rem);
    display: inline-flex;
    color: var(--muted, #938979);
    pointer-events: none;
  }
  .dre-search-bar__input {
    width: 100%;
    height: var(--size-control-md, 2.5rem);
    /* leave room for the leading icon and the trailing clear button */
    padding-inline: 2.1rem 2.1rem;
    margin: 0;
    font-size: var(--text-sm, 0.95rem);
    color: var(--ink, #33291f);
    background: var(--surface, #fdfcfa);
    border: 1px solid var(--border, #dcd6cb);
    border-radius: var(--radius-full, 9999px);
    box-shadow: var(--shadow-xs, 0 1px 2px rgba(0, 0, 0, 0.04));
    transition:
      border-color var(--transition-fast, 150ms ease),
      box-shadow var(--transition-fast, 150ms ease);
  }
  .dre-search-bar__input::placeholder {
    color: var(--muted, #938979);
  }
  .dre-search-bar__input::-webkit-search-cancel-button {
    -webkit-appearance: none;
    appearance: none;
    display: none;
  }
  .dre-search-bar__input:focus {
    outline: none;
    border-color: var(--primary, #007a50);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }

  /*
   * Quiet, transparent × — guarded against the host theme's primary-button
   * styling (background/color/box-shadow/transform), exactly like SearchBox.
   */
  .dre-search-bar__clear {
    position: absolute;
    inset-inline-end: var(--space-xs, 0.35rem);
    top: 50%;
    transform: translateY(-50%) !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.6rem;
    height: 1.6rem;
    min-width: 0;
    min-height: 0;
    margin: 0;
    padding: 0;
    border: 0;
    background: transparent !important;
    box-shadow: none !important;
    color: var(--muted, #938979) !important;
    font-size: 1.15rem;
    line-height: 1;
    cursor: pointer;
    border-radius: var(--radius-full, 9999px);
    -webkit-appearance: none;
    appearance: none;
  }
  .dre-search-bar__clear:hover {
    background: color-mix(in srgb, currentColor 16%, transparent) !important;
    color: var(--ink, #33291f) !important;
  }
  .dre-search-bar__clear:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.15)) !important;
  }

  /* ── Suggestions dropdown ────────────────────────────────────────────────── */
  .dre-search-bar__suggest {
    position: absolute;
    z-index: var(--z-dropdown, 100);
    inset-inline-end: 0;
    top: calc(100% + 0.3rem);
    width: min(26rem, 92vw);
    margin: 0;
    padding: 0.25rem;
    background: var(--surface, #fdfcfa);
    border: 1px solid var(--border, #dcd6cb);
    border-radius: var(--radius-md, 0.5rem);
    box-shadow: var(--shadow-lg, 0 10px 15px -3px rgba(0, 0, 0, 0.1));
    max-height: 28rem;
    overflow-y: auto;
  }
  .dre-search-bar__group + .dre-search-bar__group {
    margin-top: 0.15rem;
    border-top: 1px solid var(--border-light, #eae5dd);
    padding-top: 0.15rem;
  }
  .dre-search-bar__group-label {
    padding: 0.35rem var(--space-sm, 0.5rem) 0.15rem;
    font-size: var(--text-xs, 0.7rem);
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted, #938979);
  }
  .dre-search-bar__option {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    padding: var(--space-xs, 0.4rem) var(--space-sm, 0.5rem);
    border-radius: var(--radius-sm, 0.375rem);
    color: var(--ink, #33291f);
    text-decoration: none;
  }
  .dre-search-bar__option--active,
  .dre-search-bar__option:hover {
    background: var(--surface-sunken, #f1ede6);
  }
  .dre-search-bar__option-title {
    font-size: var(--text-sm, 0.9rem);
    font-weight: 600;
    line-height: 1.3;
  }
  .dre-search-bar__option-meta {
    font-size: var(--text-xs, 0.75rem);
    color: var(--muted, #7a7164);
  }
  /* Pinned at the top of the dropdown so it's always reachable without scrolling
     past the grouped suggestions. */
  .dre-search-bar__see-all {
    display: block;
    margin-bottom: 0.15rem;
    padding: var(--space-sm, 0.5rem);
    border-bottom: 1px solid var(--border-light, #eae5dd);
    color: var(--primary, #007a50);
    font-size: var(--text-sm, 0.85rem);
    font-weight: 600;
    text-decoration: none;
    border-radius: var(--radius-sm, 0.375rem) var(--radius-sm, 0.375rem) 0 0;
  }
  .dre-search-bar__see-all:hover {
    background: var(--surface-sunken, #f1ede6);
  }
</style>
