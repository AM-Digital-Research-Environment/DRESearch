<script lang="ts">
  import type { Doc } from '../lib/types';
  import { cardTitleTag } from '../lib/headings';
  import { t } from '../lib/i18n';
  import { firstMarked, markedLookup } from '../lib/highlight';
  import { safeExternalUrl } from '../lib/text';
  import FilterLink from './FilterLink.svelte';
  import Highlight from './Highlight.svelte';
  import MatchedIn from './MatchedIn.svelte';
  import CiteMenu from './CiteMenu.svelte';
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
   *   │ [keyword] [keyword]   (Full text ↗) (DOI ↗) (Cite ▾)  │
   *   └────────────────────────────────────────────────────┘
   *
   * Authors and editors are FilterLinks that add the person to the "Author / Editor"
   * facet (onAddFilter creator_ss); the venue (journal / book) and publisher filter
   * on container_ss / publisher_ss; the keyword chips add a keyword filter; "Full
   * text" opens the open-access copy (the EPub Bayreuth record), the DOI the
   * canonical record, and Cite copies or downloads the reference. Editors render
   * as their own byline for an edited volume (editors, no container) and inside
   * the "In: … (eds.), <venue>" line for a chapter.
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
    onAddFilter: (field: string, value: string) => void;
  }

  const { doc, itemUrlBase, onAddFilter }: Props = $props();
  const titleTag = cardTitleTag();

  const url = $derived(`${itemUrlBase}/${encodeURIComponent(doc.id)}`);
  const title = $derived(doc.title || t('untitled'));
  const titleHl = $derived(doc._highlights?.title?.[0] ?? null);
  const type = $derived(doc.type_s ?? '');
  const year = $derived(doc.year);
  // Abstract: the matched window when it matched, else the plain abstract.
  const snippet = $derived(firstMarked(doc, ['abstract']) ?? (doc.abstract ?? '').trim());
  // Cap chips so a heavily-tagged publication doesn't blow out the card; the
  // Keyword facet still exposes the full list.
  const keywords = $derived((doc.keyword_ss ?? []).slice(0, 8));
  const keywordHl = $derived(markedLookup(doc, 'keyword_ss'));
  const doi = $derived(safeExternalUrl(doc.doi_s));
  // The open-access copy's landing page (EPub Bayreuth on AMIRA).
  const fulltext = $derived(safeExternalUrl(doc.fulltext_url_s));
  const languages = $derived(doc.language_ss ?? []);

  // Authors — filter buttons (click adds the person to the creator_ss facet,
  // which unifies authors + editors). Literals filter fine by name.
  const authors = $derived(doc.author_ss ?? []);
  const authorHl = $derived(markedLookup(doc, 'author_ss'));

  const editors = $derived(doc.editor_ss ?? []);
  const editorHl = $derived(markedLookup(doc, 'editor_ss'));
  const edsLabel = $derived(editors.length > 1 ? t('eds_short') : t('ed_short'));

  const container = $derived(doc.container_ss?.[0] ?? '');
  const containerHl = $derived(markedLookup(doc, 'container_ss'));
  const publisher = $derived(doc.publisher_ss?.[0] ?? '');
  const publisherHl = $derived(markedLookup(doc, 'publisher_ss'));

  // Where the editors render: as their own byline for an edited volume/book
  // (editors but no container), or inside the "In: … (eds.), <venue>" reference
  // line for a chapter (container present). Never both.
  const editorsAsByline = $derived(editors.length > 0 && !container);
  const editorsInRef = $derived(editors.length > 0 && container !== '');

  // The reference line is three pieces — venue · metrics · publisher — of which
  // the venue and publisher are clickable filter buttons, so only the middle
  // (volume/issue/pages, e.g. "vol. 4(2), pp. 141–165") is a plain string here.
  const metrics = $derived.by(() => {
    const bits: string[] = [];
    const vol = doc.volume_s ?? '';
    const issue = doc.issue_s ?? '';
    if (vol && issue) {
      bits.push(`${t('vol_short')} ${vol}(${issue})`);
    } else if (vol) {
      bits.push(`${t('vol_short')} ${vol}`);
    } else if (issue) {
      bits.push(`${t('no_short')} ${issue}`);
    }
    // A range takes the "pp." prefix; a monograph's page count already reads
    // "370 pp." (PublicationMapper), and prefixing it gave "pp. 370 pp.".
    const pages = doc.pages_s ?? '';
    if (pages) {
      bits.push(/\spp\.$/.test(pages) ? pages : `${t('pp_short')} ${pages}`);
    }
    return bits.join(', ');
  });

  // Separators rendered *after* each piece (trailing, ending in a space), so any
  // whitespace the template introduces between the buttons collapses into the
  // separator's own space instead of surfacing as a stray space before a comma.
  const sepAfterVenue = $derived.by(() => {
    if (!container) return '';
    if (metrics) return ', ';
    return publisher ? '. ' : '';
  });
  // No second full stop after a metric that already ends in one ("370 pp.").
  const sepAfterMetrics = $derived(
    metrics && publisher ? (metrics.endsWith('.') ? ' ' : '. ') : '',
  );

  const hasReference = $derived(Boolean(container || metrics || publisher));
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
            onclick={() => onAddFilter('creator_ss', name)}
            ><Highlight value={authorHl.get(name) ?? name} /></FilterLink
          >{/each}
      </p>
    {/if}

    {#if editorsAsByline}
      <p class="dre-shell__line dre-bcard__authors">
        {#each editors as name, i (name + '|' + i)}{i > 0 ? '; ' : ''}<FilterLink
            onclick={() => onAddFilter('creator_ss', name)}
            ><Highlight value={editorHl.get(name) ?? name} /></FilterLink
          >{/each}{` (${edsLabel})`}
      </p>
    {/if}

    {#if hasReference}
      <p class="dre-bcard__ref">
        {#if editorsInRef}{`${t('in_prefix')} `}{#each editors as name, i (name + '|' + i)}{i > 0
              ? '; '
              : ''}<FilterLink onclick={() => onAddFilter('creator_ss', name)}
              ><Highlight value={editorHl.get(name) ?? name} /></FilterLink
            >{/each}{` (${edsLabel}), `}{/if}{#if container}<FilterLink
            onclick={() => onAddFilter('container_ss', container)}
            ><cite class="dre-bcard__venue"
              ><Highlight value={containerHl.get(container) ?? container} /></cite
            ></FilterLink
          >{sepAfterVenue}{/if}{#if metrics}{metrics}{sepAfterMetrics}{/if}{#if publisher}<FilterLink
            onclick={() => onAddFilter('publisher_ss', publisher)}
            ><Highlight value={publisherHl.get(publisher) ?? publisher} /></FilterLink
          >{/if}
      </p>
    {/if}

    {#if languages.length > 0}
      <p class="dre-shell__line dre-bcard__languages">
        <span>{t('language_label')}</span>
        {#each languages as language, i (language + '|' + i)}{i > 0 ? ' · ' : ''}<FilterLink
            onclick={() => onAddFilter('language_ss', language)}>{language}</FilterLink
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
              <button
                type="button"
                data-print
                class="dre-shell__chip"
                onclick={() => onAddFilter('keyword_ss', kw)}
              >
                <Highlight value={keywordHl.get(kw) ?? kw} />
              </button>
            </li>
          {/each}
        </ul>
      {/if}
      <div class="dre-bcard__actions">
        {#if fulltext}
          <a class="dre-shell__external" href={fulltext} target="_blank" rel="noopener noreferrer">
            {t('fulltext_link')}
          </a>
        {/if}
        {#if doi}
          <a class="dre-shell__external" href={doi} target="_blank" rel="noopener noreferrer">
            {t('doi_label')}
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
</style>
