<script lang="ts">
  import type { Snippet } from 'svelte';
  import type { ActiveFilters, Facet } from '../lib/types';
  import { t, matchFieldLabel } from '../lib/i18n';
  import FacetGroup from './FacetGroup.svelte';

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

  const chips = $derived.by(() => {
    const out: { field: string; value: string; label: string }[] = [];
    for (const [field, values] of Object.entries(selected)) {
      for (const value of values) {
        out.push({ field, value, label: labelFor(field) });
      }
    }
    return out;
  });
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
            <button
              type="button"
              class="dre-facets__chip"
              aria-label={t('remove_filter', { label: chip.label, value: chip.value })}
              onclick={() => onToggle(chip.field, chip.value, false)}
            >
              <span class="dre-facets__chip-field">{chip.label}:</span>
              <span class="dre-facets__chip-value">{chip.value}</span>
              <span class="dre-facets__chip-x" aria-hidden="true">×</span>
            </button>
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
        <FacetGroup
          field={facet.field}
          label={labelFor(facet.field)}
          counts={facet.counts}
          selected={selected[facet.field] ?? []}
          {onToggle}
        />
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
    border-bottom: 1px solid var(--border, #ccc);
  }
  .dre-facets__heading {
    margin: 0;
    /* It's an <h2>, so the host theme would render it in the display serif;
       force the body face so it reads as a UI eyebrow, like the facet-group
       labels below it (Spectral caps at 13px look out of place here). */
    font-family: var(--font-body, system-ui, sans-serif);
    font-size: var(--text-xs, 0.75rem);
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--ink-strong, var(--ink, #222));
  }
  .dre-facets__clear-all {
    /* Host theme styles every <button> as a filled primary button; keep this a
       plain text link (!important beats the host's higher-specificity states). */
    background: none !important;
    border: none;
    box-shadow: none !important;
    transform: none !important;
    color: var(--primary, #2a4d8f);
    cursor: pointer;
    font-size: var(--text-xs, 0.75rem);
    padding: 0;
  }
  .dre-facets__clear-all:hover {
    color: var(--primary, #2a4d8f) !important;
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
  .dre-facets__chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    padding: 0.2rem 0.55rem;
    background: var(--surface, #fff);
    border: 1px solid color-mix(in srgb, var(--primary, #2a4d8f) 40%, var(--border, #ccc));
    border-radius: var(--radius-full, 9999px);
    cursor: pointer;
    font: inherit;
    font-size: var(--text-xs, 0.75rem);
    color: var(--ink, #222);
    /* Suppress the host primary-button hover lift + green glow (the fill on
       hover below is intentional). */
    box-shadow: none !important;
    transform: none !important;
  }
  .dre-facets__chip:hover {
    background: var(--primary, #2a4d8f);
    border-color: var(--primary, #2a4d8f);
    color: var(--primary-contrast, #fff);
  }
  .dre-facets__chip-field {
    color: var(--muted, #666);
  }
  .dre-facets__chip:hover .dre-facets__chip-field {
    color: inherit;
    opacity: 0.85;
  }
  .dre-facets__chip-value {
    font-weight: 600;
  }
  .dre-facets__chip-x {
    font-size: var(--text-sm, 0.9rem);
    line-height: 1;
  }

  .dre-facets__groups {
    display: flex;
    flex-direction: column;
  }
  .dre-facets__empty {
    padding-block: var(--space-md, 1rem);
    color: var(--muted, #888);
    font-size: var(--text-sm, 0.9rem);
    margin: 0;
  }
</style>
