<script lang="ts">
  import type { Snippet } from 'svelte';
  import type { FilterChipModel } from '../lib/filterChips';
  import { formatNumber, t } from '../lib/i18n';
  import FilterChip from './FilterChip.svelte';
  interface Props {
    found: number;
    chips: FilterChipModel[];
    onRemove: (chip: FilterChipModel) => void;
    tools?: Snippet;
  }
  const { found, chips, onRemove, tools }: Props = $props();
</script>

<header class="dre-summary" aria-live="polite">
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
</header>

<style>
  .dre-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding-block: 0.65rem;
    border-block: 1px solid var(--border-light, #eae5dd);
  }
  .dre-summary__scope {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    min-width: 0;
  }
  .dre-summary__count {
    white-space: nowrap;
    color: var(--muted, #7a7164);
    font-size: var(--text-sm, 0.9rem);
  }
  .dre-summary__count strong {
    color: var(--ink, #33291f);
    font-variant-numeric: tabular-nums;
  }
  ul {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    list-style: none;
    margin: 0;
    padding: 0;
  }
  .dre-summary__tools {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.5rem;
  }
  @media (max-width: 46rem) {
    .dre-summary {
      align-items: stretch;
      flex-direction: column;
    }
    .dre-summary__tools {
      justify-content: flex-start;
    }
  }
</style>
