<script lang="ts">
  import type { CardKind, Doc } from '../lib/types';
  import ResultItem from './ResultItem.svelte';
  import ProjectCard from './ProjectCard.svelte';

  interface Props {
    hits: Doc[];
    found: number;
    page: number;
    perPage: number;
    itemUrlBase: string;
    cardKind: CardKind;
    onPageChange: (next: number) => void;
    onAddFilter: (field: string, value: string) => void;
  }

  const { hits, found, page, perPage, itemUrlBase, cardKind, onPageChange, onAddFilter }: Props =
    $props();

  // Cap at 100 pages — deep pagination past that is rarely useful and keeps
  // the pager bounded.
  const totalPages = $derived(Math.min(100, Math.max(1, Math.ceil(found / Math.max(1, perPage)))));

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

<ol class="dre-results">
  {#each hits as doc (doc.id)}
    <li class="dre-results__item">
      {#if cardKind === 'project'}
        <ProjectCard {doc} {itemUrlBase} {onAddFilter} />
      {:else}
        <ResultItem {doc} {itemUrlBase} />
      {/if}
    </li>
  {/each}
</ol>

{#if totalPages > 1}
  <nav class="dre-pager" aria-label="Pagination">
    <button
      type="button"
      class="dre-pager__btn"
      disabled={page <= 1}
      aria-label="Previous page"
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
      aria-label="Next page"
      onclick={() => go(page + 1)}>›</button
    >
  </nav>
{/if}

<style>
  .dre-results {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
  }

  .dre-pager {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    margin-top: var(--space-md, 1rem);
    justify-content: center;
  }
  .dre-pager__btn {
    min-width: 2.25rem;
    height: 2.25rem;
    padding: 0 0.5rem;
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fff);
    color: var(--ink, #222);
    font: inherit;
    font-variant-numeric: tabular-nums;
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms ease),
      background var(--transition-fast, 150ms ease);
  }
  .dre-pager__btn:hover:not(:disabled) {
    border-color: var(--primary, #2a4d8f);
    color: var(--primary, #2a4d8f);
  }
  .dre-pager__btn--active {
    background: var(--primary, #2a4d8f);
    border-color: var(--primary, #2a4d8f);
    color: var(--primary-contrast, #fff);
    font-weight: 600;
  }
  .dre-pager__btn:disabled {
    opacity: 0.45;
    cursor: default;
  }
  .dre-pager__btn:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .dre-pager__gap {
    color: var(--muted, #888);
    padding-inline: 0.25rem;
  }
</style>
