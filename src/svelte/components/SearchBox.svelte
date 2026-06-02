<script lang="ts">
  import type { SearchApi } from '../lib/api';
  import type { Suggestion } from '../lib/types';
  import { t } from '../lib/i18n';

  /**
   * Search input with a debounced query and an autocomplete dropdown.
   *
   * - Typing (250 ms still) calls onQueryChange → the parent runs a search.
   * - In parallel it fetches title suggestions from /suggest (abortable).
   * - A suggestion is a shortcut straight to that item's page; Enter with no
   *   active suggestion just lets the running query stand.
   */

  interface Props {
    value: string;
    placeholder?: string;
    api: SearchApi;
    itemUrlBase: string;
    onQueryChange: (next: string) => void;
  }

  const { value, placeholder = '', api, itemUrlBase, onQueryChange }: Props = $props();

  // svelte-ignore state_referenced_locally
  let local = $state(value);
  let suggestions = $state<Suggestion[]>([]);
  let open = $state(false);
  let focused = $state(false);
  let activeIndex = $state(-1);
  let timer: number | null = null;
  let controller: AbortController | null = null;

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
    if (suggestions.length > 0) {
      open = true;
    }
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

  function handleKeydown(e: KeyboardEvent): void {
    if (!open || suggestions.length === 0) {
      if (e.key === 'Escape') {
        open = false;
      }
      return;
    }
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        activeIndex = (activeIndex + 1) % suggestions.length;
        break;
      case 'ArrowUp':
        e.preventDefault();
        activeIndex = (activeIndex - 1 + suggestions.length) % suggestions.length;
        break;
      case 'Enter':
        if (activeIndex >= 0 && activeIndex < suggestions.length) {
          e.preventDefault();
          go(suggestions[activeIndex]);
        }
        break;
      case 'Escape':
        open = false;
        activeIndex = -1;
        break;
    }
  }
</script>

<div class="dre-search-box">
  <div class="dre-search-box__input-wrap">
    <input
      class="dre-search-box__input"
      type="search"
      autocomplete="off"
      autocapitalize="off"
      spellcheck="false"
      inputmode="search"
      role="combobox"
      aria-label={placeholder || 'Search'}
      aria-autocomplete="list"
      aria-expanded={open}
      aria-controls="dre-suggest"
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

  {#if open && suggestions.length > 0}
    <ul
      class="dre-search-box__suggest"
      id="dre-suggest"
      role="listbox"
      aria-label={t('suggestions')}
    >
      {#each suggestions as s, i (s.id)}
        <li role="presentation">
          <a
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
    font-size: var(--text-base, 1rem);
    color: var(--ink, #222);
    background: var(--surface, #fff);
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-md, 0.5rem);
    box-shadow: var(--shadow-xs, 0 1px 2px rgba(0, 0, 0, 0.04));
    transition:
      border-color var(--transition-fast, 150ms ease),
      box-shadow var(--transition-fast, 150ms ease);
  }
  .dre-search-box__input::placeholder {
    color: var(--muted, #888);
  }
  .dre-search-box__input::-webkit-search-cancel-button {
    -webkit-appearance: none;
    appearance: none;
    display: none;
  }
  .dre-search-box__input:focus {
    outline: none;
    border-color: var(--primary, #2a4d8f);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .dre-search-box__clear {
    position: absolute;
    inset-inline-end: var(--space-sm, 0.5rem);
    width: var(--size-control-sm, 2.25rem);
    height: var(--size-control-sm, 2.25rem);
    padding: 0;
    border: none;
    background: transparent;
    color: var(--muted, #666);
    font-size: var(--text-xl, 1.5rem);
    line-height: 1;
    cursor: pointer;
    border-radius: var(--radius-full, 9999px);
  }
  .dre-search-box__clear:hover {
    background: var(--surface-sunken, #f0f0f0);
    color: var(--ink, #222);
  }

  .dre-search-box__suggest {
    position: absolute;
    z-index: var(--z-dropdown, 100);
    inset-inline: 0;
    top: calc(100% + 0.25rem);
    margin: 0;
    padding: 0.25rem;
    list-style: none;
    background: var(--surface, #fff);
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-md, 0.5rem);
    box-shadow: var(--shadow-lg, 0 10px 15px -3px rgba(0, 0, 0, 0.1));
    max-height: 22rem;
    overflow-y: auto;
  }
  .dre-search-box__suggestion {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    padding: var(--space-sm, 0.5rem) var(--space-sm, 0.5rem);
    border-radius: var(--radius-sm, 0.375rem);
    color: var(--ink, #222);
    text-decoration: none;
  }
  .dre-search-box__suggestion--active,
  .dre-search-box__suggestion:hover {
    background: var(--surface-sunken, #f3f3f3);
  }
  .dre-search-box__suggestion-title {
    font-size: var(--text-sm, 0.9rem);
    font-weight: 600;
    line-height: 1.3;
  }
  .dre-search-box__suggestion-meta {
    font-size: var(--text-xs, 0.75rem);
    color: var(--muted, #666);
  }
</style>
