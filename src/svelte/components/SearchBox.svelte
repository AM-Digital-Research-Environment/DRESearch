<script lang="ts">
  import type { SearchApi } from '../lib/api';
  import type { Suggestion } from '../lib/types';
  import { t } from '../lib/i18n';
  import { recentSearches } from '../lib/searchHistory';
  import { installSlashFocus } from '../lib/keyboard';

  /**
   * Search input with a debounced query and an autocomplete dropdown.
   *
   * - Typing (250 ms still) calls onQueryChange → the parent runs a search.
   * - In parallel it fetches title suggestions from /suggest (abortable).
   * - A suggestion is a shortcut straight to that item's page. Pressing Enter
   *   (with no suggestion highlighted) commits the typed text as a free-text
   *   search right away and closes the dropdown — picking a suggestion is never
   *   required.
   */

  interface Props {
    value: string;
    placeholder?: string;
    api: SearchApi;
    itemUrlBase: string;
    instanceId: string;
    onQueryChange: (next: string) => void;
  }

  const { value, placeholder = '', api, itemUrlBase, instanceId, onQueryChange }: Props = $props();
  const safeId = $derived(instanceId.replace(/[^A-Za-z0-9_-]/g, '-'));
  const listboxId = $derived(`dre-suggest-${safeId}`);

  // svelte-ignore state_referenced_locally
  let local = $state(value);
  let suggestions = $state<Suggestion[]>([]);
  let open = $state(false);
  let focused = $state(false);
  let activeIndex = $state(-1);
  let timer: number | null = null;
  let controller: AbortController | null = null;
  let inputEl = $state<HTMLInputElement>();
  let recent = $state<string[]>([]);

  function itemUrl(id: string): string {
    return `${itemUrlBase}/${encodeURIComponent(id)}`;
  }

  function scheduleQuery(next: string): void {
    if (timer !== null) {
      clearTimeout(timer);
    }
    timer = window.setTimeout(() => {
      timer = null;
      onQueryChange(next);
      void fetchSuggestions(next);
    }, 250);
  }

  async function fetchSuggestions(q: string): Promise<void> {
    if (q.trim().length < 2) {
      suggestions = [];
      open = false;
      return;
    }
    controller?.abort();
    controller = new AbortController();
    const results = await api.suggest(q, controller.signal);
    suggestions = results;
    activeIndex = -1;
    open = focused && results.length > 0;
  }

  function handleInput(e: Event): void {
    local = (e.target as HTMLInputElement).value;
    scheduleQuery(local);
  }

  function handleClear(): void {
    local = '';
    suggestions = [];
    open = false;
    if (timer !== null) {
      clearTimeout(timer);
      timer = null;
    }
    onQueryChange('');
  }

  function handleFocus(): void {
    focused = true;
    recent = recentSearches();
    open = suggestions.length > 0 || (local.trim() === '' && recent.length > 0);
  }

  function handleBlur(): void {
    focused = false;
    // Defer so a click on a suggestion lands before the list unmounts.
    window.setTimeout(() => {
      open = false;
    }, 150);
  }

  function go(suggestion: Suggestion): void {
    window.location.href = itemUrl(suggestion.id);
  }

  /**
   * Commit the typed text as a free-text search now — skip the pending debounce
   * and close the dropdown — so Enter searches what you typed without forcing a
   * suggestion to be selected.
   */
  function submitQuery(): void {
    if (timer !== null) {
      clearTimeout(timer);
      timer = null;
    }
    open = false;
    activeIndex = -1;
    onQueryChange(local);
  }

  function reuseRecent(query: string): void {
    local = query;
    open = false;
    onQueryChange(query);
  }

  function handleKeydown(e: KeyboardEvent): void {
    const hasSuggestions = open && suggestions.length > 0;
    switch (e.key) {
      case 'ArrowDown':
        if (hasSuggestions) {
          e.preventDefault();
          activeIndex = (activeIndex + 1) % suggestions.length;
        }
        break;
      case 'ArrowUp':
        if (hasSuggestions) {
          e.preventDefault();
          activeIndex = (activeIndex - 1 + suggestions.length) % suggestions.length;
        }
        break;
      case 'Enter':
        e.preventDefault();
        if (hasSuggestions && activeIndex >= 0 && activeIndex < suggestions.length) {
          go(suggestions[activeIndex]); // jump to the highlighted item's page
        } else {
          submitQuery(); // search the typed text
        }
        break;
      case 'Escape':
        open = false;
        activeIndex = -1;
        break;
    }
  }

  $effect(() => {
    if (value !== local) local = value;
  });

  $effect(() => {
    const removeShortcut = installSlashFocus(() => inputEl);
    return () => {
      removeShortcut();
      controller?.abort();
      if (timer !== null) clearTimeout(timer);
    };
  });
</script>

<div class="dre-search-box">
  <div class="dre-search-box__input-wrap">
    <input
      bind:this={inputEl}
      name="q"
      class="dre-search-box__input"
      type="search"
      autocomplete="off"
      autocapitalize="off"
      spellcheck="false"
      inputmode="search"
      role="combobox"
      aria-label={placeholder || t('search_placeholder')}
      aria-autocomplete="list"
      aria-expanded={open}
      aria-controls={listboxId}
      aria-activedescendant={activeIndex >= 0 ? `${listboxId}-option-${activeIndex}` : undefined}
      {placeholder}
      value={local}
      oninput={handleInput}
      onfocus={handleFocus}
      onblur={handleBlur}
      onkeydown={handleKeydown}
    />
    {#if local !== ''}
      <button
        type="button"
        class="dre-search-box__clear"
        aria-label={t('clear_search')}
        onclick={handleClear}>×</button
      >
    {/if}
  </div>

  {#if open && (suggestions.length > 0 || recent.length > 0)}
    <ul class="dre-search-box__suggest" id={listboxId} role="listbox" aria-label={t('suggestions')}>
      {#if suggestions.length === 0 && local.trim() === ''}
        <li class="dre-search-box__recent-label">{t('recent_searches')}</li>
        {#each recent as query (query)}
          <li>
            <button
              type="button"
              class="dre-search-box__recent"
              onmousedown={(e) => e.preventDefault()}
              onclick={() => reuseRecent(query)}>{query}</button
            >
          </li>
        {/each}
      {/if}
      {#each suggestions as s, i (s.id)}
        <li role="presentation">
          <a
            id="{listboxId}-option-{i}"
            class="dre-search-box__suggestion"
            class:dre-search-box__suggestion--active={i === activeIndex}
            href={itemUrl(s.id)}
            role="option"
            aria-selected={i === activeIndex}
            onmousedown={(e) => e.preventDefault()}
            onclick={(e) => {
              e.preventDefault();
              go(s);
            }}
          >
            <span class="dre-search-box__suggestion-title">{s.title}</span>
            {#if s.subtitle}
              <span class="dre-search-box__suggestion-meta">{s.subtitle}</span>
            {/if}
          </a>
        </li>
      {/each}
    </ul>
  {/if}
</div>

<style>
  .dre-search-box {
    position: relative;
  }
  .dre-search-box__input-wrap {
    position: relative;
    display: flex;
    align-items: center;
  }
  .dre-search-box__input {
    width: 100%;
    height: var(--size-control-lg, 2.75rem);
    padding-inline: var(--space-md, 1rem) var(--space-2xl, 3rem);
    font-size: var(--text-base, 1.0625rem);
    color: var(--ink, #3c342d);
    background: var(--surface, #fdfcf9);
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-md, 0.5rem);
    box-shadow: var(--shadow-xs, 0 1px 2px 0 rgba(52, 37, 26, 0.07));
    transition:
      border-color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      box-shadow var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-search-box__input::placeholder {
    color: var(--muted, #716a66);
  }
  .dre-search-box__input::-webkit-search-cancel-button {
    -webkit-appearance: none;
    appearance: none;
    display: none;
  }
  .dre-search-box__input:focus {
    outline: none;
    border-color: var(--primary, #007a50);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }
  /*
   * The clear control is a quiet, transparent × — its own background/color reset
   * the native button chrome (the host theme no longer styles bare <button>s).
   *
   * Match the input's full 44px height so the quiet × has a reliable touch target.
   */
  .dre-search-box__clear {
    position: absolute;
    inset-inline-end: 0;
    top: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--size-control-lg, 2.75rem);
    height: var(--size-control-lg, 2.75rem);
    margin: 0;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--muted, #716a66);
    font-size: var(--text-lg, 1.1875rem);
    line-height: 1;
    cursor: pointer;
    border-radius: var(--radius-full, 9999px);
    -webkit-appearance: none;
    appearance: none;
  }
  .dre-search-box__clear:hover {
    /* Faint neutral wash + a readable label — never the theme's green-on-green. */
    background: color-mix(in srgb, currentColor 16%, transparent);
    color: var(--ink, #3c342d);
  }
  .dre-search-box__clear:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }

  .dre-search-box__suggest {
    position: absolute;
    z-index: var(--z-dropdown, 100);
    inset-inline: 0;
    top: calc(100% + 0.25rem);
    margin: 0;
    padding: 0.25rem;
    list-style: none;
    background: var(--surface, #fdfcf9);
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-md, 0.5rem);
    box-shadow: var(
      --shadow-lg,
      0 10px 15px -3px rgba(42, 28, 16, 0.14),
      0 4px 6px -4px rgba(52, 37, 26, 0.07)
    );
    max-height: 22rem;
    overflow-y: auto;
  }
  .dre-search-box__suggestion {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    padding: var(--space-sm, 0.5rem) var(--space-sm, 0.5rem);
    border-radius: var(--radius-sm, 0.375rem);
    color: var(--ink, #3c342d);
    text-decoration: none;
  }
  .dre-search-box__suggestion--active,
  .dre-search-box__suggestion:hover {
    background: var(--surface-sunken, #f3f0eb);
  }
  .dre-search-box__suggestion-title {
    font-size: var(--text-sm, 0.9375rem);
    font-weight: 600;
    line-height: var(--leading-snug, 1.25);
  }
  .dre-search-box__suggestion-meta {
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #716a66);
  }
  .dre-search-box__recent-label {
    padding: 0.4rem 0.5rem 0.2rem;
    color: var(--muted, #716a66);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }
  .dre-search-box__recent {
    display: block;
    width: 100%;
    margin: 0;
    padding: 0.5rem;
    border: 0;
    border-radius: var(--radius-sm, 0.375rem);
    background: transparent;
    color: var(--ink, #3c342d);
    font: inherit;
    text-align: start;
    cursor: pointer;
  }
  .dre-search-box__recent:hover {
    background: var(--surface-sunken, #f3f0eb);
  }
</style>
