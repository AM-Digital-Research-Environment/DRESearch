<script lang="ts">
  import type { Snippet } from 'svelte';
  import { t } from '../lib/i18n';
  import { detailsPopover } from '../lib/popover';
  const { children }: { children: Snippet } = $props();
  let open = $state(false);
  // Wide screens pin the actions open with the summary hidden; only the
  // narrow disclosure closes on Escape or a press outside.
  let wide = $state(false);
  $effect(() => {
    const media = window.matchMedia('(min-width: 48rem)');
    const update = () => {
      wide = media.matches;
      open = media.matches;
    };
    update();
    media.addEventListener('change', update);
    return () => media.removeEventListener('change', update);
  });
</script>

<details class="dre-actions" bind:open use:detailsPopover={{ enabled: !wide }}>
  <summary>{t('result_actions')}</summary>
  <div>{@render children()}</div>
</details>

<style>
  .dre-actions > div {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-2, 0.5rem);
  }
  summary {
    display: flex;
    align-items: center;
    cursor: pointer;
    min-height: var(--size-control-lg, 2.75rem);
    padding: 0 var(--space-3, 0.75rem);
    color: var(--primary-text, #006440);
    font-size: var(--text-sm, 0.9375rem);
    font-weight: 600;
    border: 1px solid var(--border-strong, #bfbab3);
    border-radius: var(--radius-md, 0.5rem);
  }
  summary:hover {
    background-color: var(--primary-muted, #e4f0e6);
    border-color: var(--primary, #007a50);
  }
  summary:focus-visible {
    outline: 2px solid var(--focus-color, #007a50);
    outline-offset: 2px;
  }
  @media (min-width: 768px) {
    summary {
      display: none;
    }
  }
  @media (max-width: 767px) {
    .dre-actions[open] > div {
      padding-block-start: var(--space-2, 0.5rem);
    }
  }
</style>
