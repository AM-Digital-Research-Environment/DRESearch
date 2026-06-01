<script lang="ts">
  import type { SortKey } from '../lib/types';
  import { t } from '../lib/i18n';

  interface Props {
    value: SortKey;
    onChange: (next: SortKey) => void;
  }

  const { value, onChange }: Props = $props();

  const options: { key: SortKey; label: string }[] = [
    { key: 'relevance', label: t('sort_relevance') },
    { key: 'newest', label: t('sort_newest') },
    { key: 'oldest', label: t('sort_oldest') },
    { key: 'title', label: t('sort_title') },
  ];
</script>

<label class="dre-sort">
  <span class="dre-sort__label">{t('sort_label')}</span>
  <select
    class="dre-sort__select"
    {value}
    onchange={(e) => onChange((e.currentTarget as HTMLSelectElement).value as SortKey)}
  >
    {#each options as o (o.key)}
      <option value={o.key}>{o.label}</option>
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
