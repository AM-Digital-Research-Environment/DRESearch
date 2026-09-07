<script lang="ts">
  import type { Snippet } from 'svelte';
  import { t } from '../lib/i18n';
  const { children }: { children: Snippet } = $props();
  let open = $state(false);
  $effect(() => {
    const media = window.matchMedia('(min-width: 48rem)');
    const update = () => {
      open = media.matches;
    };
    update();
    media.addEventListener('change', update);
    return () => media.removeEventListener('change', update);
  });
</script>

<details class="dre-actions" bind:open>
  <summary>{t('result_actions')}</summary>
  <div>{@render children()}</div>
</details>

<style>
  .dre-actions > div {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
  }
  summary {
    cursor: pointer;
    min-height: var(--size-control-lg, 2.75rem);
    padding: 0.5rem 0.75rem;
    color: var(--ink, #3c342d);
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-md, 0.5rem);
  }
  summary:focus-visible {
    outline: 2px solid var(--primary, #007a50);
    outline-offset: 2px;
  }
  @media (min-width: 48rem) {
    summary {
      display: none;
    }
  }
  @media (max-width: 48rem) {
    .dre-actions[open] > div {
      padding-block-start: 0.5rem;
    }
  }
</style>
