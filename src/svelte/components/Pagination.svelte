<script lang="ts">
  import { MAX_PAGE } from '../lib/urlState';
  import { t } from '../lib/i18n';

  /**
   * The one results pager (a search block's results and the federated "All"
   * list): previous / next, the first and last page, and two pages either side
   * of the current one.
   */
  interface Props {
    found: number;
    page: number;
    perPage: number;
    onPageChange: (next: number) => void;
  }

  const { found, page, perPage, onPageChange }: Props = $props();

  // Capped where the server stops serving pages, so a pager never offers (or a
  // shared link never lands on) a page it cannot load.
  const totalPages = $derived(
    Math.min(MAX_PAGE, Math.max(1, Math.ceil(found / Math.max(1, perPage)))),
  );

  const windowPages = $derived.by(() => {
    const span = 2;
    const start = Math.max(1, page - span);
    const end = Math.min(totalPages, page + span);
    const pages: number[] = [];
    for (let i = start; i <= end; i++) {
      pages.push(i);
    }
    return pages;
  });
  const firstWindow = $derived(windowPages[0] ?? 1);
  const lastWindow = $derived(windowPages[windowPages.length - 1] ?? 1);

  function go(next: number): void {
    if (next >= 1 && next <= totalPages && next !== page) {
      onPageChange(next);
    }
  }
</script>

{#if totalPages > 1}
  <nav class="dre-pager" aria-label={t('pagination')}>
    <button
      type="button"
      class="dre-pager__btn"
      disabled={page <= 1}
      aria-label={t('previous_page')}
      onclick={() => go(page - 1)}>‹</button
    >

    {#if firstWindow > 1}
      <button type="button" class="dre-pager__btn" onclick={() => go(1)}>1</button>
      {#if firstWindow > 2}
        <span class="dre-pager__gap" aria-hidden="true">…</span>
      {/if}
    {/if}

    {#each windowPages as p (p)}
      <button
        type="button"
        class="dre-pager__btn"
        class:dre-pager__btn--active={p === page}
        aria-current={p === page ? 'page' : undefined}
        onclick={() => go(p)}>{p}</button
      >
    {/each}

    {#if lastWindow < totalPages}
      {#if lastWindow < totalPages - 1}
        <span class="dre-pager__gap" aria-hidden="true">…</span>
      {/if}
      <button type="button" class="dre-pager__btn" onclick={() => go(totalPages)}
        >{totalPages}</button
      >
    {/if}

    <button
      type="button"
      class="dre-pager__btn"
      disabled={page >= totalPages}
      aria-label={t('next_page')}
      onclick={() => go(page + 1)}>›</button
    >
  </nav>
{/if}

<style>
  .dre-pager {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    margin-top: var(--space-md, 1rem);
    justify-content: center;
  }
  .dre-pager__btn {
    min-width: var(--size-control-lg, 2.75rem);
    height: var(--size-control-lg, 2.75rem);
    margin: 0;
    padding: 0 0.5rem;
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfcf9);
    color: var(--ink, #3c342d);
    font: inherit;
    font-variant-numeric: tabular-nums;
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      background var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  /* Exclude the active page: it carries the filled-primary green, so turning the
     label primary on hover would put primary text on the primary fill. The
     background is restated so a host theme's button:hover fill cannot bleed in. */
  .dre-pager__btn:hover:not(:disabled):not(.dre-pager__btn--active) {
    border-color: var(--primary, #007a50);
    color: var(--primary, #007a50);
    background: var(--surface, #fdfcf9);
  }
  .dre-pager__btn--active {
    background: var(--primary, #007a50);
    border-color: var(--primary, #007a50);
    color: var(--primary-contrast, #fcfcf9);
    font-weight: 600;
  }
  .dre-pager__btn:disabled {
    opacity: 0.45;
    cursor: default;
  }
  .dre-pager__btn:focus-visible {
    outline: 2px solid var(--primary, #007a50);
    outline-offset: 2px;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }
  .dre-pager__gap {
    color: var(--muted, #716a66);
    padding-inline: 0.25rem;
  }
</style>
