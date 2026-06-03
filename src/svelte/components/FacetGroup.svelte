<script lang="ts">
  import type { FacetCount } from '../lib/types';
  import { t } from '../lib/i18n';
  import { foldAccents } from '../lib/text';

  interface Props {
    field: string;
    label: string;
    counts: FacetCount[];
    selected: string[];
    onToggle: (field: string, value: string, checked: boolean) => void;
  }

  const { field, label, counts, selected, onToggle }: Props = $props();

  const COLLAPSED = 8;
  let open = $state(true);
  let query = $state('');

  // Once a facet has more values than fit comfortably, offer a type-to-filter box
  // and scroll the list — no "show N more" expander.
  const searchable = $derived(counts.length > COLLAPSED);

  const searching = $derived(query.trim() !== '');
  // Match accent-insensitively (fold diacritics + lowercase on both sides), so
  // "Rudiger" finds "Rüdiger" — same behaviour as the server-side search bar.
  const visible = $derived.by(() => {
    const q = foldAccents(query.trim());
    if (q === '') {
      return counts;
    }
    return counts.filter((c) => foldAccents(c.value).includes(q));
  });
</script>

<section class="dre-facet">
  <button
    type="button"
    class="dre-facet__heading"
    aria-expanded={open}
    onclick={() => (open = !open)}
  >
    <span class="dre-facet__label">{label}</span>
    {#if selected.length > 0}
      <span class="dre-facet__badge">{selected.length}</span>
    {/if}
    <span class="dre-facet__chevron" aria-hidden="true">{open ? '▾' : '▸'}</span>
  </button>

  {#if open}
    {#if searchable}
      <div class="dre-facet__search">
        <input
          type="search"
          class="dre-facet__search-input"
          placeholder={t('facet_search_placeholder', { label })}
          aria-label={t('facet_search_placeholder', { label })}
          bind:value={query}
        />
      </div>
    {/if}

    {#if visible.length > 0}
      <ul class="dre-facet__list">
        {#each visible as c (c.value)}
          <li>
            <label class="dre-facet__option">
              <input
                type="checkbox"
                checked={selected.includes(c.value)}
                onchange={(e) =>
                  onToggle(field, c.value, (e.currentTarget as HTMLInputElement).checked)}
              />
              <span class="dre-facet__value">{c.value}</span>
              <span class="dre-facet__count">{c.count.toLocaleString()}</span>
            </label>
          </li>
        {/each}
      </ul>
    {:else if searching}
      <p class="dre-facet__nomatch">{t('facet_no_matches')}</p>
    {/if}
  {/if}
</section>

<style>
  .dre-facet {
    padding-block: var(--space-md, 1rem);
    border-bottom: 1px solid var(--border-light, #eee);
  }
  .dre-facet:last-child {
    border-bottom: none;
  }
  .dre-facet__heading {
    display: flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    width: 100%;
    padding: 0;
    /* Host theme styles every <button> as a filled primary button; without
       these the toggle fills with green on hover (!important beats the host's
       higher-specificity :hover/:active). */
    background: none !important;
    box-shadow: none !important;
    transform: none !important;
    border: none;
    cursor: pointer;
    font: inherit;
    color: var(--ink-strong, var(--ink, #222));
    font-size: var(--text-xs, 0.75rem);
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    text-align: start;
  }
  .dre-facet__heading:hover {
    color: var(--primary, #2a4d8f) !important;
  }
  .dre-facet__label {
    flex: 1;
  }
  .dre-facet__badge {
    background: var(--primary, #2a4d8f);
    color: var(--primary-contrast, #fff);
    border-radius: var(--radius-full, 9999px);
    min-width: 1.25rem;
    height: 1.25rem;
    padding: 0 0.4rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: var(--text-xs, 0.7rem);
    font-weight: 600;
    letter-spacing: 0;
  }
  .dre-facet__chevron {
    color: var(--muted, #888);
    font-size: var(--text-xs, 0.75rem);
  }

  .dre-facet__search {
    margin-top: var(--space-sm, 0.5rem);
  }
  /* Reset the host theme's generic input chrome and apply a compact field. */
  .dre-facet__search-input {
    box-sizing: border-box;
    width: 100%;
    margin: 0;
    padding: 0.3rem 0.5rem;
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-sm, 0.375rem);
    background: var(--surface, #fff);
    color: var(--ink, #222);
    font: inherit;
    font-size: var(--text-sm, 0.85rem);
    box-shadow: none;
    -webkit-appearance: none;
    appearance: none;
  }
  .dre-facet__search-input::placeholder {
    color: var(--muted, #999);
  }
  .dre-facet__search-input:focus,
  .dre-facet__search-input:focus-visible {
    outline: none;
    border-color: var(--primary, #2a4d8f);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(42, 77, 143, 0.18));
  }

  .dre-facet__list {
    list-style: none;
    margin: var(--space-sm, 0.5rem) 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    /* Keep a long (searched) list from dominating the sidebar; scroll within. */
    max-height: 22rem;
    overflow-y: auto;
    scrollbar-width: thin;
  }
  .dre-facet__option {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    padding: 0.2rem 0.25rem;
    border-radius: var(--radius-sm, 0.375rem);
    cursor: pointer;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink, #222);
  }
  .dre-facet__option:hover {
    background: var(--surface-sunken, #f3f3f3);
  }
  .dre-facet__value {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .dre-facet__count {
    color: var(--muted, #888);
    font-size: var(--text-xs, 0.75rem);
    font-variant-numeric: tabular-nums;
  }
  .dre-facet__nomatch {
    margin: var(--space-sm, 0.5rem) 0 0;
    padding: 0.2rem 0.25rem;
    color: var(--muted, #888);
    font-size: var(--text-sm, 0.85rem);
  }
</style>
