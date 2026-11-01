<script lang="ts">
  import type { SearchApi } from '../lib/api';
  import type { Suggestion } from '../lib/types';
  import { t } from '../lib/i18n';
  import { recentSearches } from '../lib/searchHistory';
  import { installSlashFocus } from '../lib/keyboard';
  import { createTypeahead, type Typeahead } from '../lib/typeahead.svelte';

  /**
   * Search input with a debounced query and an autocomplete dropdown.
   *
   * - Typing (250 ms still) calls onQueryChange → the parent runs a search.
   *   Those commits are flagged `typed`, and the box says when the typing is
   *   over (onTypingEnd: Enter, leaving the box), so the parent can make the
   *   burst one history step (lib/urlState.ts createTypingBurst).
   * - In parallel it fetches title suggestions from /suggest (abortable).
   * - A suggestion is a shortcut straight to that item's page. Pressing Enter
   *   (with no suggestion highlighted) commits the typed text as a free-text
   *   search right away and closes the dropdown — picking a suggestion is never
   *   required.
   *
   * The combobox itself (text, request races, keys, blur) is shared with the
   * header's SearchBar: lib/typeahead.svelte.ts.
   */

  interface Props {
    value: string;
    placeholder?: string;
    api: SearchApi;
    itemUrlBase: string;
    instanceId: string;
    /** `typed`: a debounced commit of typed text, not Enter, a pick or a clear. */
    onQueryChange: (next: string, typed: boolean) => void;
    /** The visitor stopped typing: Enter, or focus left the box. */
    onTypingEnd?: () => void;
  }

  const {
    value,
    placeholder = '',
    api,
    itemUrlBase,
    instanceId,
    onQueryChange,
    onTypingEnd,
  }: Props = $props();
  const safeId = $derived(instanceId.replace(/[^A-Za-z0-9_-]/g, '-'));
  const listboxId = $derived(`dre-suggest-${safeId}`);

  let inputEl = $state<HTMLInputElement>();
  let recent = $state<string[]>([]);
  let popular = $state<string[]>([]);
  // Last query we handed to the parent. The parent echoes it back through
  // `value`, and that echo must not be mistaken for an outside change — see the
  // sync effect at the bottom of this script.
  // svelte-ignore state_referenced_locally
  let emitted = value;

  // svelte-ignore state_referenced_locally
  const box: Typeahead<Suggestion> = createTypeahead<Suggestion>({
    initial: value,
    fetch: (q, signal) => api.suggest(q, signal),
    optionCount: () => optionCount,
    // Typing commits the query once the box is still; the suggestions follow.
    onSettle: (text) => emit(text, true),
    onEnter: (index) => {
      const starter = index >= 0 && showingStarters ? starters[index] : undefined;
      const suggestion = index >= 0 && !showingStarters ? box.items[index] : undefined;
      if (starter !== undefined) reuseRecent(starter);
      else if (suggestion)
        go(suggestion); // jump to the highlighted item's page
      else submitQuery(); // search the typed text
    },
  });

  function itemUrl(id: string): string {
    return `${itemUrlBase}/${encodeURIComponent(id)}`;
  }

  /** Commit a query upward, remembering it so the echo can't clobber the box. */
  function emit(next: string, typed = false): void {
    emitted = next;
    onQueryChange(next, typed);
  }

  function handleClear(): void {
    box.clear();
    emit('');
    // The clear button removes itself; keep the visitor in the field.
    inputEl?.focus();
  }

  // One option list for the arrow keys: search starters (this visitor's recent
  // searches, then the corpus's popular ones) while the field is empty, title
  // suggestions once there is text. Recent searches used to be plain buttons
  // outside the listbox's keyboard model (mouse-only).
  const popularOnly = $derived(
    popular.filter((q) => !recent.some((r) => r.toLowerCase() === q.toLowerCase())),
  );
  const starters = $derived([...recent, ...popularOnly]);
  const starterGroups = $derived(
    [
      { kind: 'recent', label: t('recent_searches'), items: recent, offset: 0 },
      { kind: 'popular', label: t('popular_searches'), items: popularOnly, offset: recent.length },
    ].filter((group) => group.items.length > 0),
  );
  const showingStarters: boolean = $derived(
    box.items.length === 0 && box.text.trim() === '' && starters.length > 0,
  );
  const optionCount: number = $derived(showingStarters ? starters.length : box.items.length);

  function handleFocus(): void {
    recent = recentSearches();
    box.focus();
    const firstLoad = popular.length === 0;
    void api.popular().then((queries) => {
      popular = queries;
      // The first fetch resolves after focus: show the list it just filled.
      if (firstLoad) box.refresh();
    });
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
    box.cancel();
    onTypingEnd?.();
    emit(box.text);
  }

  function reuseRecent(query: string): void {
    box.setText(query);
    emit(query);
  }

  /*
   * Accept a query pushed from OUTSIDE the box — a removed query chip, a "did
   * you mean" correction, a back/forward navigation.
   *
   * This must depend on `value` ALONE. Reading the box's text here would re-run
   * the effect on every keystroke and reset the field to the parent's copy,
   * which is a debounce behind — characters vanish as they are typed.
   * Comparing against `emitted` (a plain let, so not a dependency) also ignores
   * the parent echoing back our own committed query, which would otherwise
   * rewind whatever was typed during that round trip.
   */
  $effect(() => {
    if (value === emitted) return;
    emitted = value;
    box.setText(value);
  });

  // Keep the highlighted option visible: the list scrolls (max-height).
  $effect(() => {
    if (box.activeIndex < 0) return;
    document
      .getElementById(`${listboxId}-option-${box.activeIndex}`)
      ?.scrollIntoView?.({ block: 'nearest' });
  });

  $effect(() => {
    const removeShortcut = installSlashFocus(() => inputEl);
    return () => {
      removeShortcut();
      box.destroy();
    };
  });
</script>

<div class="dre-search-box" role="search">
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
      aria-expanded={box.open}
      aria-controls={listboxId}
      aria-activedescendant={box.activeIndex >= 0
        ? `${listboxId}-option-${box.activeIndex}`
        : undefined}
      {placeholder}
      value={box.text}
      oninput={(e) => box.input(e.currentTarget.value)}
      onfocus={handleFocus}
      onblur={() => {
        box.blur();
        onTypingEnd?.();
      }}
      onkeydown={(e) => box.keydown(e)}
    />
    {#if box.text !== ''}
      <button
        type="button"
        class="dre-search-box__clear"
        aria-label={t('clear_search')}
        onclick={handleClear}>×</button
      >
    {/if}
  </div>

  {#if box.open && (box.items.length > 0 || showingStarters)}
    <ul
      class="dre-search-box__suggest"
      id={listboxId}
      role="listbox"
      aria-label={showingStarters && starterGroups.length === 1
        ? starterGroups[0]?.label
        : t('suggestions')}
    >
      {#if showingStarters}
        {#each starterGroups as group (group.kind)}
          <li role="presentation">
            <ul
              class="dre-search-box__group"
              role="group"
              aria-labelledby="{listboxId}-{group.kind}"
            >
              <li
                id="{listboxId}-{group.kind}"
                class="dre-search-box__recent-label"
                role="presentation"
              >
                {group.label}
              </li>
              {#each group.items as query, j (query)}
                <!-- svelte-ignore a11y_click_events_have_key_events -->
                <li
                  id="{listboxId}-option-{group.offset + j}"
                  class="dre-search-box__recent"
                  class:dre-search-box__suggestion--active={group.offset + j === box.activeIndex}
                  role="option"
                  aria-selected={group.offset + j === box.activeIndex}
                  onmousedown={(e) => e.preventDefault()}
                  onclick={() => reuseRecent(query)}
                >
                  {query}
                </li>
              {/each}
            </ul>
          </li>
        {/each}
      {/if}
      {#each box.items as s, i (s.id)}
        <li role="presentation">
          <a
            id="{listboxId}-option-{i}"
            class="dre-search-box__suggestion"
            class:dre-search-box__suggestion--active={i === box.activeIndex}
            href={itemUrl(s.id)}
            role="option"
            aria-selected={i === box.activeIndex}
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
  @media print {
    .dre-search-box {
      display: none;
    }
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
    border: 1px solid var(--field-border, #8b857f);
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
    /* The theme's field focus (DRE-theme base/elements/_fields.scss): the ring is
       a box-shadow, which forced-colors mode drops, so the outline stays —
       transparent — and is painted in the system focus colour there. */
    outline: 2px solid transparent;
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
    outline: 2px solid var(--focus-color, #007a50);
    outline-offset: 2px;
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
    gap: 0;
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
  .dre-search-box__group {
    margin: 0;
    padding: 0;
    list-style: none;
  }
  .dre-search-box__recent-label {
    padding: var(--space-2, 0.5rem) var(--space-2, 0.5rem) var(--space-1, 0.25rem);
    color: var(--muted, #716a66);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: var(--tracking-wide, 0.04em);
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
