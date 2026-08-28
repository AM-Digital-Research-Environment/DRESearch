<script lang="ts">
  import type { ViewMode } from '../lib/types';
  import { t } from '../lib/i18n';
  interface Props {
    value: ViewMode;
    options: ViewMode[];
    onChange: (next: ViewMode) => void;
  }
  const { value, options, onChange }: Props = $props();
</script>

<div class="dre-view" role="group" aria-label={t('view_label')}>
  {#each options as option (option)}
    <button
      type="button"
      class:dre-view__active={value === option}
      aria-pressed={value === option}
      onclick={() => onChange(option)}
    >
      {#if option === 'list'}<svg viewBox="0 0 20 20" aria-hidden="true"
          ><path d="M3 4h2v2H3zm4 0h10v2H7zM3 9h2v2H3zm4 0h10v2H7zM3 14h2v2H3zm4 0h10v2H7z" /></svg
        >
      {:else if option === 'gallery'}<svg viewBox="0 0 20 20" aria-hidden="true"
          ><path d="M3 3h6v6H3zm8 0h6v6h-6zM3 11h6v6H3zm8 0h6v6h-6z" /></svg
        >
      {:else}<svg viewBox="0 0 20 20" aria-hidden="true"
          ><path d="m2 5 5-2 6 2 5-2v12l-5 2-6-2-5 2zm6-.5v9l4 1.4v-9zm6 1.4v8.6l2-.8V5.1z" /></svg
        >{/if}
      <span>{t(`view_${option}`)}</span>
    </button>
  {/each}
</div>

<style>
  .dre-view {
    display: inline-flex;
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-md, 0.5rem);
    overflow: hidden;
  }
  button {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    min-height: var(--size-control-lg, 2.75rem);
    margin: 0;
    padding: 0.35rem 0.6rem;
    border: 0;
    border-inline-end: 1px solid var(--border, #dbd7d1);
    background: var(--surface, #fdfcf9);
    color: var(--muted, #716a66);
    font: inherit;
    font-size: var(--text-xs, 0.8125rem);
    cursor: pointer;
  }
  button:last-child {
    border-inline-end: 0;
  }
  button:hover,
  .dre-view__active {
    background: var(--surface-sunken, #f3f0eb);
    color: var(--ink, #3c342d);
  }
  .dre-view__active {
    box-shadow: inset 0 -2px var(--primary, #007a50);
    font-weight: 700;
  }
  svg {
    width: 1rem;
    height: 1rem;
    fill: currentColor;
  }
  @media (max-width: 32rem) {
    button span {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip: rect(0 0 0 0);
    }
  }
</style>
