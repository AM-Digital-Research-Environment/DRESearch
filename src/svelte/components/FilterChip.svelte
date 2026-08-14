<script lang="ts">
  import type { FilterChipModel } from '../lib/filterChips';
  import { t } from '../lib/i18n';
  interface Props {
    chip: FilterChipModel;
    onRemove: (chip: FilterChipModel) => void;
  }
  const { chip, onRemove }: Props = $props();
</script>

<button
  type="button"
  class="dre-filter-chip"
  aria-label={t('remove_filter', { label: chip.label, value: chip.value })}
  onclick={() => onRemove(chip)}
>
  <span class="dre-filter-chip__label">{chip.label}:</span>
  <strong>{chip.value}</strong>
  <span aria-hidden="true">×</span>
</button>

<style>
  .dre-filter-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    max-width: 100%;
    padding: 0.2rem 0.55rem;
    border: 1px solid color-mix(in srgb, var(--primary, #007a50) 40%, var(--border, #dbd7d1));
    border-radius: var(--radius-full, 9999px);
    background: var(--surface, #fdfcf9);
    color: var(--ink, #3c342d);
    font: inherit;
    font-size: var(--text-xs, 0.8125rem);
    cursor: pointer;
  }
  .dre-filter-chip:hover {
    background: var(--primary, #007a50);
    border-color: var(--primary, #007a50);
    color: var(--primary-contrast, #fcfcf9);
  }
  .dre-filter-chip__label {
    color: var(--muted, #716a66);
  }
  .dre-filter-chip:hover .dre-filter-chip__label {
    color: inherit;
  }
  strong {
    overflow-wrap: anywhere;
  }
</style>
