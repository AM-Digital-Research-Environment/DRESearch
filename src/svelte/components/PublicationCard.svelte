<script lang="ts">
  import type { Doc } from '../lib/types';
  import { cardTitleTag } from '../lib/headings';
  import { t } from '../lib/i18n';
  import { markedLookup } from '../lib/highlight';
  import {
    cardSnippet,
    cardTitle,
    publicationIds,
    publicationReference,
    recordUrl,
    titleMarkup,
  } from '../lib/resultCard';
  import { safeExternalUrl } from '../lib/text';
  import FilterLink from './FilterLink.svelte';
  import Highlight from './Highlight.svelte';
  import FilterButton from './FilterButton.svelte';
  import MatchedIn from './MatchedIn.svelte';
  import CiteMenu from './CiteMenu.svelte';
  import IdentifierLinks from './IdentifierLinks.svelte';
  import RegisterMark from './RegisterMark.svelte';
  import '../styles/card.css';

  /**
   * One publication card — a bibliographic reference:
   *
   *   ┌────────────────────────────────────────────────────┐
   *   │ 2026                                        [chapter] │
   *   │ Art for Art's Sake: The Rejection of Ethical Value?   │
   *   │ Klaeger, Florian          (author → filter button)    │
   *   │ In: Ganteau, J.-M.; Onega, S. (eds.), Handbook of …,   │
   *   │   vol. 4, pp. 141–165. Brill                           │
   *   │ Abstract, clamped to a few lines…                     │
   *   │ [keyword]  ([|||] Q123 ↗) (Full text ↗) (DOI ↗) (Cite ▾) │
   *   └────────────────────────────────────────────────────┘
   *
   * Authors and editors are FilterLinks that toggle the person in the "Author /
   * Editor" facet (creator_ss); the venue (journal / book) and publisher filter
   * on container_ss / publisher_ss; the keyword chips toggle a keyword filter; "Full
   * text" opens the open-access copy (the EPub Bayreuth record), the DOI the
   * canonical record, and Cite copies or downloads the reference. Editors render
   * as their own byline for an edited volume (editors, no container) and inside
   * the "In: … (eds.), <venue>" line for a chapter.
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
  }

  const { doc, itemUrlBase }: Props = $props();
  const titleTag = cardTitleTag();

  const url = $derived(recordUrl(itemUrlBase, doc.id));
  const title = $derived(cardTitle(doc));
  const titleHl = $derived(titleMarkup(doc));
  const type = $derived(doc.type_s ?? '');
  const year = $derived(doc.year);
  // Abstract: the matched window when it matched, else the plain abstract.
  const snippet = $derived(cardSnippet(doc, ['abstract']));
  // Cap chips so a heavily-tagged publication doesn't blow out the card; the
  // Keyword facet still exposes the full list.
  const keywords = $derived((doc.keyword_ss ?? []).slice(0, 8));
  const keywordHl = $derived(markedLookup(doc, 'keyword_ss'));
  const doi = $derived(safeExternalUrl(doc.doi_s));
  // The work's Wikidata item. The DOI keeps its own action pill below.
  const ids = $derived(publicationIds(doc));
  // The open-access copy's landing page (EPub Bayreuth on AMIRA).
  const fulltext = $derived(safeExternalUrl(doc.fulltext_url_s));
  const languages = $derived(doc.language_ss ?? []);

  // Authors — filter buttons (click adds the person to the creator_ss facet,
  // which unifies authors + editors). Literals filter fine by name.
  const authors = $derived(doc.author_ss ?? []);
  const authorHl = $derived(markedLookup(doc, 'author_ss'));

  const editors = $derived(doc.editor_ss ?? []);
  const editorHl = $derived(markedLookup(doc, 'editor_ss'));

  // The reference line — venue · metrics · publisher, with the editors either
  // as their own byline (an edited volume) or inside "In: … (eds.), <venue>" (a
  // chapter) — and its separators: lib/resultCard.ts publicationReference().
  // The venue and publisher are clickable filter values, so they stay separate
  // pieces of the template.
  const ref = $derived(publicationReference(doc));
  const edsLabel = $derived(ref.editorsLabel);
  const container = $derived(ref.container);
  const containerHl = $derived(markedLookup(doc, 'container_ss'));
  const publisher = $derived(ref.publisher);
  const publisherHl = $derived(markedLookup(doc, 'publisher_ss'));
  const editorsAsByline = $derived(ref.editorsAsByline);
  const editorsInRef = $derived(ref.editorsInRef);
  const metrics = $derived(ref.metrics);
  const sepAfterVenue = $derived(ref.sepAfterVenue);
  const sepAfterMetrics = $derived(ref.sepAfterMetrics);
  const hasReference = $derived(ref.hasReference);
</script>

<article class="dre-shell dre-bcard">
  <div class="dre-shell__body">
    <header class="dre-shell__head">
      {#if year != null}
        <span class="dre-bcard__year">{year}</span>
      {/if}
      {#if type}
        <span class="dre-shell__pill dre-bcard__type">{type}</span>
      {/if}
    </header>

    <svelte:element this={titleTag} class="dre-shell__title">
      <a href={url}><Highlight value={titleHl ?? title} /></a>
    </svelte:element>

    {#if authors.length > 0}
      <p class="dre-shell__line dre-bcard__authors">
        {#each authors as name, i (name + '|' + i)}{i > 0 ? ', ' : ''}<FilterLink
            field="creator_ss"
            value={name}><Highlight value={authorHl.get(name) ?? name} /></FilterLink
          >{/each}
      </p>
    {/if}

    {#if editorsAsByline}
      <p class="dre-shell__line dre-bcard__authors">
        {#each editors as name, i (name + '|' + i)}{i > 0 ? '; ' : ''}<FilterLink
            field="creator_ss"
            value={name}><Highlight value={editorHl.get(name) ?? name} /></FilterLink
          >{/each}{` (${edsLabel})`}
      </p>
    {/if}

    {#if hasReference}
      <p class="dre-bcard__ref">
        {#if editorsInRef}{`${t('in_prefix')} `}{#each editors as name, i (name + '|' + i)}{i > 0
              ? '; '
              : ''}<FilterLink field="creator_ss" value={name}
              ><Highlight value={editorHl.get(name) ?? name} /></FilterLink
            >{/each}{` (${edsLabel}), `}{/if}{#if container}<FilterLink
            field="container_ss"
            value={container}
            ><cite class="dre-bcard__venue"
              ><Highlight value={containerHl.get(container) ?? container} /></cite
            ></FilterLink
          >{sepAfterVenue}{/if}{#if metrics}{metrics}{sepAfterMetrics}{/if}{#if publisher}<FilterLink
            field="publisher_ss"
            value={publisher}
            ><Highlight value={publisherHl.get(publisher) ?? publisher} /></FilterLink
          >{/if}
      </p>
    {/if}

    {#if languages.length > 0}
      <p class="dre-shell__line dre-bcard__languages">
        <span>{t('language_label')}</span>
        {#each languages as language, i (language + '|' + i)}{i > 0 ? ' · ' : ''}<FilterLink
            field="language_ss"
            value={language}>{language}</FilterLink
          >{/each}
      </p>
    {/if}

    {#if snippet}
      <p class="dre-shell__snippet"><Highlight value={snippet} /></p>
    {/if}

    <div class="dre-bcard__footer">
      {#if keywords.length > 0}
        <ul class="dre-shell__chips dre-bcard__chips">
          {#each keywords as kw (kw)}
            <li>
              <FilterButton field="keyword_ss" value={kw} class="dre-shell__chip">
                <Highlight value={keywordHl.get(kw) ?? kw} />
              </FilterButton>
            </li>
          {/each}
        </ul>
      {/if}
      <div class="dre-bcard__actions">
        <IdentifierLinks {ids} />
        {#if fulltext}
          <a class="dre-shell__external" href={fulltext} target="_blank" rel="noopener noreferrer">
            {t('fulltext_link')}
          </a>
        {/if}
        {#if doi}
          <a class="dre-shell__external" href={doi} target="_blank" rel="noopener noreferrer">
            <RegisterMark key="doi" />{t('doi_label')}
          </a>
        {/if}
        <CiteMenu {doc} kind="publication" {itemUrlBase} />
      </div>
    </div>

    <MatchedIn
      {doc}
      exclude={[
        'title',
        'abstract',
        'author_ss',
        'editor_ss',
        'creator_ss',
        'container_ss',
        'keyword_ss',
      ]}
    />
  </div>
</article>

<style>
  /* The shell, title, bylines, snippet and chips are styles/card.css. */
  .dre-bcard__year {
    color: var(--muted, #716a66);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: var(--tracking-wide, 0.04em);
    font-variant-numeric: tabular-nums;
  }
  .dre-bcard__type {
    text-transform: capitalize;
  }
  /* Author / editor names, venue and publisher are FilterLink spans — see that
     component for the styling and for why they are not <button>s. */
  .dre-bcard__ref {
    margin: 0;
    font-size: var(--text-sm, 0.9375rem);
    color: var(--muted, #716a66);
    line-height: var(--leading-normal, 1.6);
  }
  .dre-bcard__venue {
    font-style: italic;
  }
  .dre-bcard__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--space-sm, 0.5rem);
    margin-top: var(--space-xs, 0.25rem);
  }
  .dre-bcard__chips {
    margin: 0;
    min-width: 0;
  }
  /* Full text, DOI and Cite: one row of pills (Full text and DOI are the
     shared external-link pill, styles/card.css), held at the end of the
     footer whether or not keyword chips share the row. */
  .dre-bcard__actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
    margin-inline-start: auto;
  }
  /* The Wikidata link joins the row rather than opening one of its own. */
  .dre-bcard__actions :global(.dre-shell__ids) {
    margin: 0;
  }
</style>
