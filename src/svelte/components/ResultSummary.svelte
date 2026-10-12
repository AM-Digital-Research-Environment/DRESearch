<script lang="ts">
  import type { Snippet } from 'svelte';
  import type { FilterChipModel } from '../lib/filterChips';
  import { formatNumber, t } from '../lib/i18n';
  import FilterChip from './FilterChip.svelte';
  interface Props {
    found: number;
    chips: FilterChipModel[];
    onRemove: (chip: FilterChipModel) => void;
    /** How the results are shown: sort and view. */
    tools?: Snippet;
    /** What can be done with the result set: copy its link, export it. */
    actions?: Snippet;
  }
  const { found, chips, onRemove, tools, actions }: Props = $props();
</script>

<!-- Not a live region: it also holds sort, view, copy-link and export controls,
     so announcing it read out stray numbers and open menus. App owns one atomic
     status line for the count instead. -->
<div class="dre-summary">
  <header class="dre-summary__bar">
    <div class="dre-summary__scope">
      <span class="dre-summary__count"
        ><strong>{formatNumber(found)}</strong>
        {found === 1 ? t('result_one') : t('result_other')}</span
      >
      {#if chips.length > 0}<ul>
          {#each chips as chip (chip.id)}<li><FilterChip {chip} {onRemove} /></li>{/each}
        </ul>{/if}
    </div>
    {#if tools}<div class="dre-summary__tools">{@render tools()}</div>{/if}
    {#if actions}<div class="dre-summary__actions" role="group" aria-label={t('result_actions')}>
        {@render actions()}
      </div>{/if}
  </header>
</div>

<style>
  /* The bar folds by its own width, not the screen's: beside the facet rail
     at 768px the results column is narrower than a phone held sideways. */
  .dre-summary {
    container: dre-summary / inline-size;
  }
  .dre-summary__bar {
    display: flex;
    align-items: center;
    gap: var(--space-3, 0.75rem);
    padding-block: var(--space-3, 0.75rem);
    border-block: 1px solid var(--border-light, #eae8e3);
  }
  .dre-summary__scope {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-2, 0.5rem);
    min-width: 0;
    margin-inline-end: auto;
  }
  .dre-summary__count {
    white-space: nowrap;
    color: var(--muted, #716a66);
    font-size: var(--text-sm, 0.9375rem);
  }
  .dre-summary__count strong {
    color: var(--ink, #3c342d);
    font-variant-numeric: tabular-nums;
  }
  ul {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-1, 0.25rem);
    list-style: none;
    margin: 0;
    padding: 0;
  }
  .dre-summary__tools,
  .dre-summary__actions {
    display: flex;
    align-items: center;
    gap: var(--space-2, 0.5rem);
  }
  /* Print the count and the active filters, not sort / view / share / export. */
  @media print {
    .dre-summary__tools,
    .dre-summary__actions {
      display: none;
    }
  }
  /* Narrow: the result set's actions share the count's row, at its end; sort
     and view take the row below, the view switch under the actions. One row
     cannot hold them all, and hiding the two actions behind a "Share and
     export" disclosure cost a tap and moved the row when it opened. The chips
     and tools are placed by column only, so they fill the next rows in order
     and no empty chips row doubles the gap. */
  @container dre-summary (width < 42rem) {
    .dre-summary__bar {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: var(--space-3, 0.75rem) var(--space-2, 0.5rem);
    }
    .dre-summary__scope {
      display: contents;
    }
    .dre-summary__count {
      grid-area: 1 / 1;
    }
    .dre-summary__actions {
      grid-area: 1 / 2;
    }
    ul,
    .dre-summary__tools {
      grid-column: 1 / -1;
    }
    .dre-summary__tools {
      justify-content: space-between;
    }
  }
</style>
