<script lang="ts">
  import type { Doc, ViewMode } from '../lib/types';
  import { cardTitleTag } from '../lib/headings';
  import { t } from '../lib/i18n';
  import { markedLookup } from '../lib/highlight';
  import { cardSnippet, cardTitle, recordUrl, titleMarkup } from '../lib/resultCard';
  import FilterLink from './FilterLink.svelte';
  import Highlight from './Highlight.svelte';
  import FilterButton from './FilterButton.svelte';
  import MatchedIn from './MatchedIn.svelte';
  import CardThumb from './CardThumb.svelte';
  import '../styles/card.css';

  /**
   * One result card:
   *
   *   ┌────────────────────────────────────────────────┐
   *   │ ┌────┐  2021                            TEXT    │
   *   │ │img │  Title of the research item              │
   *   │ │    │  Author A, Author B                      │
   *   │ └────┘  Short abstract / description…           │
   *   │         [Project]                               │
   *   │         Place of origin: Bayreuth · Lagos       │
   *   │         Current location: University of Bayreuth │
   *   │         Language: English                        │
   *   └────────────────────────────────────────────────┘
   *
   * The project chip, each author, place of origin, current location and language
   * are toggles of that value as a facet filter (lib/cardFilters.ts). Matched query
   * terms are highlighted in the title, byline and snippet; matches in fields the
   * card doesn't show surface in a "Matched in" line.
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
    view?: ViewMode;
    /** Above the fold (first gallery row): load now rather than lazily. */
    eager?: boolean;
  }

  const { doc, itemUrlBase, view = 'list', eager = false }: Props = $props();
  const titleTag = cardTitleTag();

  const url = $derived(recordUrl(itemUrlBase, doc.id));
  const title = $derived(cardTitle(doc));
  const titleHl = $derived(titleMarkup(doc));

  // Authors / contributors — clickable (filters creator_ss) and highlighted.
  const creators = $derived(doc.creator_ss ?? []);
  const creatorHl = $derived(markedLookup(doc, 'creator_ss'));

  // Snippet: whichever of abstract/description matched (centred on the match),
  // else the abstract (or description) shown plainly.
  const snippet = $derived(cardSnippet(doc, ['abstract', 'description']));

  const project = $derived(doc.project_s ?? '');
  // Geographic provenance: where the item is from (the specific place as recorded,
  // e.g. "Bayreuth") vs where it is held now (specific place or repository
  // institution). Both are the verbatim linked place — not the country roll-up.
  const origins = $derived(doc.origin_ss ?? []);
  const currentLocations = $derived(doc.provenance_ss ?? []);
  const languages = $derived(doc.language_ss ?? []);
</script>

<article class="dre-shell dre-shell--media dre-card" class:dre-shell--gallery={view === 'gallery'}>
  <CardThumb
    href={url}
    url={doc.thumbnail_url}
    shape="item"
    gallery={view === 'gallery'}
    {eager}
    placeholder
    type={doc.type_s}
  />

  <div class="dre-shell__body">
    <header class="dre-shell__head">
      {#if doc.year}
        <span class="dre-shell__eyebrow">{doc.year}</span>
      {/if}
      {#if doc.type_s}
        <span class="dre-shell__pill dre-card__type">{doc.type_s}</span>
      {/if}
    </header>

    <svelte:element this={titleTag} class="dre-shell__title">
      <a href={url}><Highlight value={titleHl ?? title} /></a>
    </svelte:element>

    {#if creators.length > 0}
      <p class="dre-shell__line">
        {#each creators as name, i (name + '|' + i)}{i > 0 ? ', ' : ''}<FilterLink
            field="creator_ss"
            value={name}><Highlight value={creatorHl.get(name) ?? name} /></FilterLink
          >{/each}
      </p>
    {/if}

    {#if snippet}
      <p class="dre-shell__snippet dre-card__snippet"><Highlight value={snippet} /></p>
    {/if}

    {#if project}
      <ul class="dre-shell__chips">
        <li>
          <FilterButton
            field="project_s"
            value={project}
            class="dre-shell__chip dre-shell__chip--accent"
          >
            {project}
          </FilterButton>
        </li>
      </ul>
    {/if}

    {#if origins.length > 0}
      <p class="dre-shell__meta dre-card__geo">
        <span class="dre-shell__label">{t('origin_label')}</span>
        {#each origins as o, i (o + '|' + i)}{i > 0 ? ' · ' : ''}<FilterLink
            field="origin_ss"
            value={o}>{o}</FilterLink
          >{/each}
      </p>
    {/if}

    {#if currentLocations.length > 0}
      <p class="dre-shell__meta dre-card__geo">
        <span class="dre-shell__label">{t('current_location_label')}</span>
        {#each currentLocations as c, i (c + '|' + i)}{i > 0 ? ' · ' : ''}<FilterLink
            field="provenance_ss"
            value={c}>{c}</FilterLink
          >{/each}
      </p>
    {/if}

    {#if languages.length > 0}
      <p class="dre-shell__meta dre-card__geo">
        <span class="dre-shell__label">{t('language_label')}</span>
        {#each languages as l, i (l + '|' + i)}{i > 0 ? ' · ' : ''}<FilterLink
            field="language_ss"
            value={l}>{l}</FilterLink
          >{/each}
      </p>
    {/if}

    <MatchedIn {doc} exclude={['title', 'abstract', 'description', 'creator_ss']} />
  </div>
</article>

<style>
  /* The shell, thumbnail, chips and bylines are styles/card.css. */
  .dre-card__type {
    text-transform: uppercase;
  }
  .dre-card__snippet {
    -webkit-line-clamp: 2;
    line-clamp: 2;
  }
  /* Inline "click to filter" values (authors, places, language) are FilterLink
     spans — see that component for the styling and the rationale. */

  /* The gallery tile keeps the image, title and byline only. */
  .dre-shell--gallery .dre-card__snippet,
  .dre-shell--gallery .dre-card__geo,
  .dre-shell--gallery :global(.dre-matched-in) {
    display: none;
  }
</style>
