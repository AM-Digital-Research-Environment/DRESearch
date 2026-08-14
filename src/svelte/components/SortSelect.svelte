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
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    font-size: var(--text-sm, 0.9375rem);
    color: var(--muted, #716a66);
  }
  .dre-sort__select {
    height: var(--size-control-md, 2.5rem);
    /* The host theme's `select` rule adds margin-bottom:8px, which knocks this
       off-centre inside the toolbar; zero it. */
    margin: 0;
    padding-block: 0;
    padding-inline: var(--space-sm, 0.5rem) 1.9rem;
    font: inherit;
    color: var(--ink, #3c342d);
    /* background-color (not the `background` shorthand) so we don't blow away
       the chevron drawn on .dre-sort::after; also drop the host's arrow asset. */
    background-color: var(--surface, #fdfcf9);
    background-image: none;
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-md, 0.5rem);
    cursor: pointer;
    -webkit-appearance: none;
    appearance: none;
  }
  /* Self-contained dropdown chevron — the host theme's arrow asset isn't ours
     to depend on, and `appearance: none` removes the native one. */
  .dre-sort::after {
    content: '';
    position: absolute;
    inset-inline-end: 0.7rem;
    top: 50%;
    width: 0.7rem;
    height: 0.7rem;
    transform: translateY(-50%);
    background-color: var(--muted, #716a66);
    pointer-events: none;
    --dre-chevron: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8' fill='none' stroke='%23000' stroke-width='2'%3E%3Cpath d='M1 1.5 6 6.5 11 1.5'/%3E%3C/svg%3E");
    -webkit-mask: var(--dre-chevron) center / contain no-repeat;
    mask: var(--dre-chevron) center / contain no-repeat;
  }
  .dre-sort__select:focus-visible {
    outline: none;
    border-color: var(--primary, #007a50);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }
</style>
