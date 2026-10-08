<script lang="ts">
  import type { CardKind, Doc, ViewMode } from '../lib/types';
  import Pagination from './Pagination.svelte';
  import ResultItem from './ResultItem.svelte';
  import ProjectCard from './ProjectCard.svelte';
  import PublicationCard from './PublicationCard.svelte';
  import PodcastCard from './PodcastCard.svelte';
  import VideoCard from './VideoCard.svelte';
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
    /** The corpus, for the entity hue of a term card's type tag. */
    profile?: string;
    /** Pack the compact two-up cards as a masonry instead of a row-aligned grid. */
    masonry?: boolean;
    view?: ViewMode;
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
    profile,
    masonry = false,
    view = 'list',
    onPageChange,
    onAddFilter,
  }: Props = $props();
</script>

<ol
  class="dre-results"
  class:dre-results--masonry={masonry}
  class:dre-results--two-col={cardKind === 'term' && !masonry}
  class:dre-results--gallery={view === 'gallery'}
>
  {#each hits as doc, index (doc.id)}
    <li class="dre-results__item">
      {#if cardKind === 'project'}
        <ProjectCard {doc} {itemUrlBase} {onAddFilter} />
      {:else if cardKind === 'publication'}
        <PublicationCard {doc} {itemUrlBase} {onAddFilter} />
      {:else if cardKind === 'podcast'}
        <PodcastCard {doc} {itemUrlBase} {onAddFilter} />
      {:else if cardKind === 'video'}
        <VideoCard {doc} {itemUrlBase} {onAddFilter} />
      {:else if cardKind === 'person'}
        <PersonCard {doc} {itemUrlBase} {onAddFilter} />
      {:else if cardKind === 'section'}
        <SectionCard {doc} {itemUrlBase} {onAddFilter} />
      {:else if cardKind === 'organisation'}
        <OrganisationCard {doc} {itemUrlBase} {onAddFilter} />
      {:else if cardKind === 'term'}
        <TermCard {doc} {itemUrlBase} {profile} {onAddFilter} />
      {:else}
        <ResultItem {doc} {itemUrlBase} {onAddFilter} {view} eager={index < 4} />
      {/if}
    </li>
  {/each}
</ol>

<Pagination {found} {page} {perPage} {onPageChange} />

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
  .dre-results--gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 14rem), 1fr));
    gap: var(--space-md, 1rem);
  }
  @media (min-width: 1024px) {
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
  @media (min-width: 1024px) {
    .dre-results--masonry {
      column-count: 2;
    }
  }
  .dre-results--masonry .dre-results__item {
    break-inside: avoid;
    -webkit-column-break-inside: avoid; /* older WebKit */
    margin-bottom: var(--space-md, 1rem);
  }
</style>
