<script lang="ts">
  import type { ViewMode } from '../lib/types';
  import { t } from '../lib/i18n';
  interface Props {
    view?: ViewMode;
    count?: number;
  }
  const { view = 'list', count = 6 }: Props = $props();
</script>

<div
  class="dre-skeletons"
  class:dre-skeletons--gallery={view === 'gallery'}
  role="status"
  aria-label={t('loading_results')}
>
  {#each Array(count) as _, i (i)}
    <div class="dre-skeleton">
      <span class="dre-skeleton__image"></span><span class="dre-skeleton__body"
        ><i></i><i></i><i></i></span
      >
    </div>
  {/each}
</div>

<style>
  .dre-skeletons {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
  }
  .dre-skeletons--gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr));
  }
  .dre-skeleton {
    display: grid;
    grid-template-columns: 7rem 1fr;
    gap: 1rem;
    min-height: 8.5rem;
    padding: 1rem;
    border: 1px solid var(--border-light, #eae8e3);
    border-radius: var(--radius-lg, 0.75rem);
  }
  .dre-skeleton__image,
  .dre-skeleton i {
    display: block;
    background: linear-gradient(
      90deg,
      var(--surface-sunken, #f3f0eb) 25%,
      color-mix(in srgb, var(--surface-sunken, #f3f0eb) 65%, white) 50%,
      var(--surface-sunken, #f3f0eb) 75%
    );
    background-size: 200% 100%;
    animation: dre-shimmer 1.3s linear infinite;
  }
  .dre-skeleton__image {
    aspect-ratio: 1;
    border-radius: 0.375rem;
  }
  .dre-skeleton__body {
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    padding-top: 0.2rem;
  }
  .dre-skeleton i {
    height: 0.8rem;
    border-radius: 0.2rem;
  }
  .dre-skeleton i:first-child {
    height: 1.2rem;
    width: 75%;
  }
  .dre-skeleton i:last-child {
    width: 55%;
  }
  .dre-skeletons--gallery .dre-skeleton {
    display: flex;
    flex-direction: column;
  }
  .dre-skeletons--gallery .dre-skeleton__image {
    aspect-ratio: 4/3;
    width: 100%;
  }
  @keyframes dre-shimmer {
    to {
      background-position: -200% 0;
    }
  }
  @media (prefers-reduced-motion: reduce) {
    .dre-skeleton__image,
    .dre-skeleton i {
      animation: none;
      background: var(--surface-sunken, #f3f0eb);
    }
  }
</style>
