<script lang="ts">
  import type { Snippet } from 'svelte';
  import type { ActiveFilters, Facet } from '../lib/types';
  import { t, matchFieldLabel } from '../lib/i18n';
  import FacetGroup from './FacetGroup.svelte';
  import FilterChip from './FilterChip.svelte';
  import { buildFilterChips, type FilterChipModel } from '../lib/filterChips';

  interface Props {
    /** Facet counts from the latest response (arbitrary order). */
    facets: Facet[];
    /** Display order (the block's configured facet list). */
    order: string[];
    /** field => label. */
    labels: Record<string, string>;
    selected: ActiveFilters;
    activeCount: number;
    onToggle: (field: string, value: string, checked: boolean) => void;
    onClearAll: () => void;
    /** Optional extra control rendered above the facet groups (e.g. a year slider). */
    prepend?: Snippet;
  }

  const { facets, order, labels, selected, activeCount, onToggle, onClearAll, prepend }: Props =
    $props();

  function labelFor(field: string): string {
    // Sidebar facets carry a server-translated label; a filter added from a result
    // card may target a non-sidebar field (e.g. an author) — fall back to its
    // friendly name so the active-filter chip never reads a raw field id.
    return labels[field] ?? matchFieldLabel(field);
  }

  // Order the response facets by the configured order; drop empty ones.
  const orderedFacets = $derived(
    order
      .map((field) => facets.find((f) => f.field === field))
      .filter((f): f is Facet => !!f && f.counts.length > 0),
  );

  const chips = $derived(buildFilterChips(selected, labels));
  function removeChip(chip: FilterChipModel): void {
    onToggle(chip.field, chip.value, false);
  }
</script>

<div class="dre-facets">
  <header class="dre-facets__header">
    <h2 class="dre-facets__heading">{t('filters')}</h2>
    {#if activeCount > 0}
      <button type="button" class="dre-facets__clear-all" onclick={onClearAll}>
        {t('clear_all')}
      </button>
    {/if}
  </header>

  {#if chips.length > 0}
    <section class="dre-facets__active" aria-label={t('active_filters')}>
      <ul class="dre-facets__chips">
        {#each chips as chip (chip.field + '|' + chip.value)}
          <li>
            <FilterChip {chip} onRemove={removeChip} />
          </li>
        {/each}
      </ul>
    </section>
  {/if}

  {#if orderedFacets.length === 0 && !prepend}
    <p class="dre-facets__empty">{t('search_to_see_options')}</p>
  {:else}
    <div class="dre-facets__groups">
      {@render prepend?.()}
      {#each orderedFacets as facet (facet.field)}
        {#if facet.field === 'has_fulltext'}
          <button
            type="button"
            class:dre-facets__fulltext-active={(selected.has_fulltext ?? []).includes('Yes')}
            class="dre-facets__fulltext"
            aria-pressed={(selected.has_fulltext ?? []).includes('Yes')}
            onclick={() =>
              onToggle('has_fulltext', 'Yes', !(selected.has_fulltext ?? []).includes('Yes'))}
            >{labelFor(facet.field)}</button
          >
        {:else}
          <FacetGroup
            field={facet.field}
            label={labelFor(facet.field)}
            counts={facet.counts}
            selected={selected[facet.field] ?? []}
            {onToggle}
          />
        {/if}
      {/each}
    </div>
  {/if}
</div>

<style>
  .dre-facets {
    display: flex;
    flex-direction: column;
  }
  .dre-facets__header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    padding-block: var(--space-xs, 0.25rem) var(--space-sm, 0.5rem);
    border-bottom: 1px solid var(--border, #dbd7d1);
  }
  .dre-facets__heading {
    margin: 0;
    /* It's an <h2>, so the host theme would render it in the display serif;
       force the body face so it reads as a UI eyebrow, like the facet-group
       labels below it (Spectral caps at 13px look out of place here). */
    font-family: var(
      --font-body,
      'Hanken Grotesk',
      system-ui,
      -apple-system,
      'Segoe UI',
      Roboto,
      'Helvetica Neue',
      sans-serif
    );
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--ink-strong, #261d15);
  }
  .dre-facets__clear-all {
    /* Plain text link — reset the native button chrome. */
    background: none;
    border: none;
    color: var(--primary, #007a50);
    cursor: pointer;
    font-size: var(--text-xs, 0.8125rem);
    padding: 0;
  }
  .dre-facets__clear-all:hover {
    color: var(--primary, #007a50);
    text-decoration: underline;
  }

  .dre-facets__active {
    padding-block: var(--space-sm, 0.5rem);
  }
  .dre-facets__chips {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-facets__groups {
    display: flex;
    flex-direction: column;
  }
  .dre-facets__fulltext {
    margin-block: var(--space-md, 1rem) 0;
    padding: 0.55rem 0.7rem;
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfcf9);
    color: var(--ink, #3c342d);
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    text-align: start;
    cursor: pointer;
  }
  .dre-facets__fulltext:hover,
  .dre-facets__fulltext-active {
    border-color: var(--primary, #007a50);
    background: color-mix(in srgb, var(--primary, #007a50) 12%, var(--surface, #fdfcf9));
  }
  .dre-facets__empty {
    padding-block: var(--space-md, 1rem);
    color: var(--muted, #716a66);
    font-size: var(--text-sm, 0.9375rem);
    margin: 0;
  }
</style>
