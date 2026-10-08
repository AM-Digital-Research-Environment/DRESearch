<script lang="ts">
  import { MAX_PAGE } from '../lib/urlState';
  import { t } from '../lib/i18n';
  import '../styles/buttons.css';

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
      class="dre-pager__btn dre-button-secondary"
      disabled={page <= 1}
      aria-label={t('previous_page')}
      onclick={() => go(page - 1)}>‹</button
    >

    {#if firstWindow > 1}
      <button type="button" class="dre-pager__btn dre-button-secondary" onclick={() => go(1)}
        >1</button
      >
      {#if firstWindow > 2}
        <span class="dre-pager__gap" aria-hidden="true">…</span>
      {/if}
    {/if}

    {#each windowPages as p (p)}
      <button
        type="button"
        class="dre-pager__btn dre-button-secondary"
        class:dre-pager__btn--active={p === page}
        aria-current={p === page ? 'page' : undefined}
        onclick={() => go(p)}>{p}</button
      >
    {/each}

    {#if lastWindow < totalPages}
      {#if lastWindow < totalPages - 1}
        <span class="dre-pager__gap" aria-hidden="true">…</span>
      {/if}
      <button
        type="button"
        class="dre-pager__btn dre-button-secondary"
        onclick={() => go(totalPages)}>{totalPages}</button
      >
    {/if}

    <button
      type="button"
      class="dre-pager__btn dre-button-secondary"
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
  /* The secondary button (styles/buttons.css), square and numeric. */
  .dre-pager__btn {
    min-width: var(--size-control-lg, 2.75rem);
    padding: 0 var(--space-2, 0.5rem);
    font-variant-numeric: tabular-nums;
  }
  /* The current page is the one filled control. This rule (0,2,0 with Svelte's
     scope class) outranks the secondary hover, so it stays filled. */
  .dre-pager__btn--active {
    background: var(--primary, #007a50);
    border-color: var(--primary, #007a50);
    color: var(--primary-contrast, #fcfcf9);
  }
  @media print {
    .dre-pager {
      display: none;
    }
  }
  .dre-pager__gap {
    color: var(--muted, #716a66);
    padding-inline: 0.25rem;
  }
</style>
