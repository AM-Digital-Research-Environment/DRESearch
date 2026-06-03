<script lang="ts">
  import type { CardKind, Doc } from '../lib/types';
  import ResultItem from './ResultItem.svelte';
  import ProjectCard from './ProjectCard.svelte';
  import PublicationCard from './PublicationCard.svelte';
  import PersonCard from './PersonCard.svelte';
  import SectionCard from './SectionCard.svelte';
  import OrganisationCard from './OrganisationCard.svelte';
  import TermCard from './TermCard.svelte';

  interface Props {
    hits: Doc[];
    found: number;
    page: number;
    perPage: number;
    itemUrlBase: string;
    cardKind: CardKind;
    /** Pack the compact two-up cards as a masonry instead of a row-aligned grid. */
    masonry?: boolean;
    onPageChange: (next: number) => void;
    onAddFilter: (field: string, value: string) => void;
  }

  const {
    hits,
    found,
    page,
    perPage,
    itemUrlBase,
    cardKind,
    masonry = false,
    onPageChange,
    onAddFilter,
  }: Props = $props();

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

<ol
  class="dre-results"
  class:dre-results--masonry={masonry}
  class:dre-results--two-col={cardKind === 'term' && !masonry}
>
  {#each hits as doc (doc.id)}
    <li class="dre-results__item">
      {#if cardKind === 'project'}
        <ProjectCard {doc} {itemUrlBase} {onAddFilter} />
      {:else if cardKind === 'publication'}
        <PublicationCard {doc} {itemUrlBase} {onAddFilter} />
      {:else if cardKind === 'person'}
        <PersonCard {doc} {itemUrlBase} {onAddFilter} />
      {:else if cardKind === 'section'}
        <SectionCard {doc} {itemUrlBase} {onAddFilter} />
      {:else if cardKind === 'organisation'}
        <OrganisationCard {doc} {itemUrlBase} {onAddFilter} />
      {:else if cardKind === 'term'}
        <TermCard {doc} {itemUrlBase} {onAddFilter} />
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
  /* Facet-less authority-term cards (genres, languages) are uniform height — name
     and a count — so a plain two-up grid packs them cleanly and keeps their
     count-ranked order reading row-by-row. */
  .dre-results--two-col {
    display: grid;
    grid-template-columns: 1fr;
  }
  @media (min-width: 60rem) {
    .dre-results--two-col {
      grid-template-columns: 1fr 1fr;
    }
  }
  /* Compact cards whose height varies — people & organisations (a name alone, or
     several role chips) and the faceted authority terms (locations, subjects:
     Type/role chips and long wrapping headings) — leave ragged gaps in a grid,
     where each row waits on its tallest card. Pack them with a CSS multi-column
     "masonry" instead: each card keeps its natural height and the following card
     rises to fill the space. Columns fill top-to-bottom then left-to-right, so the
     order still reads in sequence column-by-column. Pure CSS — no JS, no thrash. */
  .dre-results--masonry {
    display: block;
    column-count: 1;
    column-gap: var(--space-md, 1rem);
  }
  @media (min-width: 60rem) {
    .dre-results--masonry {
      column-count: 2;
    }
  }
  .dre-results--masonry .dre-results__item {
    break-inside: avoid;
    -webkit-column-break-inside: avoid; /* older WebKit */
    margin-bottom: var(--space-md, 1rem);
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
    /* Host theme adds a primary-button drop-shadow + hover lift/glow to every
       <button>; pager buttons are flat, so strip it in every state. */
    box-shadow: none !important;
    transform: none !important;
    transition:
      border-color var(--transition-fast, 150ms ease),
      background var(--transition-fast, 150ms ease);
  }
  /* Exclude the active page: the host's `button:hover` would otherwise fill an
     inactive button green (and its primary text would vanish on the green). */
  .dre-pager__btn:hover:not(:disabled):not(.dre-pager__btn--active) {
    border-color: var(--primary, #2a4d8f);
    color: var(--primary, #2a4d8f);
    background: var(--surface, #fff);
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
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1)) !important;
  }
  .dre-pager__gap {
    color: var(--muted, #888);
    padding-inline: 0.25rem;
  }
</style>
