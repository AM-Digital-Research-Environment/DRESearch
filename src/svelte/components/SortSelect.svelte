<script lang="ts">
  import type { SortKey, SortOption } from '../lib/types';
  import { t } from '../lib/i18n';

  interface Props {
    value: SortKey;
    /** Sort choices for this corpus (server-built; labels already translated). */
    options: SortOption[];
    onChange: (next: SortKey) => void;
  }

  const { value, options, onChange }: Props = $props();
</script>

<label class="dre-sort">
  <span class="dre-sort__label">{t('sort_label')}</span>
  <select
    class="dre-sort__select"
    {value}
    onchange={(e) => onChange((e.currentTarget as HTMLSelectElement).value as SortKey)}
  >
    {#each options as o (o.value)}
      <option value={o.value}>{o.label}</option>
    {/each}
  </select>
</label>

<style>
  .dre-sort {
    display: inline-flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    font-size: var(--text-sm, 0.9rem);
    color: var(--muted, #666);
  }
  .dre-sort__select {
    height: var(--size-control-md, 2.5rem);
    padding-inline: var(--space-sm, 0.5rem);
    font: inherit;
    color: var(--ink, #222);
    background: var(--surface, #fff);
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-md, 0.5rem);
    cursor: pointer;
  }
  .dre-sort__select:focus-visible {
    outline: none;
    border-color: var(--primary, #2a4d8f);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
</style>
