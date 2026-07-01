<script lang="ts">
  import type { Doc } from '../lib/types';
  import { t } from '../lib/i18n';
  import { firstMarked, markedLookup } from '../lib/highlight';
  import Highlight from './Highlight.svelte';
  import MatchedIn from './MatchedIn.svelte';

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
   *   │ [keyword] [keyword]                            DOI ↗   │
   *   └────────────────────────────────────────────────────┘
   *
   * Authors and editors are buttons that add the person to the "Author / Editor"
   * facet (onAddFilter creator_ss); the venue (journal / book) and publisher filter
   * on container_ss / publisher_ss; the keyword chips add a keyword filter; the DOI
   * opens the canonical record. Editors render as their own byline for an edited
   * volume (editors, no container) and inside the "In: … (eds.), <venue>" line for
   * a chapter.
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
    onAddFilter: (field: string, value: string) => void;
  }

  const { doc, itemUrlBase, onAddFilter }: Props = $props();

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
  const doi = $derived(doc.doi_s ?? '');

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
    if (doc.pages_s) {
      bits.push(`${t('pp_short')} ${doc.pages_s}`);
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
  const sepAfterMetrics = $derived(metrics && publisher ? '. ' : '');

  const hasReference = $derived(Boolean(container || metrics || publisher));
</script>

<article class="dre-bcard">
  <div class="dre-bcard__body">
    <header class="dre-bcard__head">
      {#if year != null}
        <span class="dre-bcard__year">{year}</span>
      {/if}
      {#if type}
        <span class="dre-bcard__type">{type}</span>
      {/if}
    </header>

    <h3 class="dre-bcard__title">
      <a href={url}><Highlight value={titleHl ?? title} /></a>
    </h3>

    {#if authors.length > 0}
      <p class="dre-bcard__authors">
        {#each authors as name, i (name + '|' + i)}{i > 0 ? ', ' : ''}<button
            type="button"
            class="dre-bcard__person"
            onclick={() => onAddFilter('creator_ss', name)}
            ><Highlight value={authorHl.get(name) ?? name} /></button
          >{/each}
      </p>
    {/if}

    {#if editorsAsByline}
      <p class="dre-bcard__authors">
        {#each editors as name, i (name + '|' + i)}{i > 0 ? '; ' : ''}<button
            type="button"
            class="dre-bcard__person"
            onclick={() => onAddFilter('creator_ss', name)}
            ><Highlight value={editorHl.get(name) ?? name} /></button
          >{/each}{` (${edsLabel})`}
      </p>
    {/if}

    {#if hasReference}
      <p class="dre-bcard__ref">
        {#if editorsInRef}{`${t('in_prefix')} `}{#each editors as name, i (name + '|' + i)}{i > 0
              ? '; '
              : ''}<button
              type="button"
              class="dre-bcard__person"
              onclick={() => onAddFilter('creator_ss', name)}
              ><Highlight value={editorHl.get(name) ?? name} /></button
            >{/each}{` (${edsLabel}), `}{/if}{#if container}<button
            type="button"
            class="dre-bcard__person"
            onclick={() => onAddFilter('container_ss', container)}
            ><cite class="dre-bcard__venue"
              ><Highlight value={containerHl.get(container) ?? container} /></cite
            ></button
          >{sepAfterVenue}{/if}{#if metrics}{metrics}{sepAfterMetrics}{/if}{#if publisher}<button
            type="button"
            class="dre-bcard__person"
            onclick={() => onAddFilter('publisher_ss', publisher)}
            ><Highlight value={publisherHl.get(publisher) ?? publisher} /></button
          >{/if}
      </p>
    {/if}

    {#if snippet}
      <p class="dre-bcard__snippet"><Highlight value={snippet} /></p>
    {/if}

    {#if keywords.length > 0 || doi}
      <div class="dre-bcard__footer">
        {#if keywords.length > 0}
          <ul class="dre-bcard__chips">
            {#each keywords as kw (kw)}
              <li>
                <button
                  type="button"
                  class="dre-bcard__chip"
                  onclick={() => onAddFilter('keyword_ss', kw)}
                >
                  <Highlight value={keywordHl.get(kw) ?? kw} />
                </button>
              </li>
            {/each}
          </ul>
        {/if}
        {#if doi}
          <a class="dre-bcard__doi" href={doi} target="_blank" rel="noopener noreferrer">
            {t('doi_label')}
          </a>
        {/if}
      </div>
    {/if}

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
  .dre-bcard {
    padding: var(--space-md, 1rem);
    background: var(--surface, #fdfcfa);
    border: 1px solid var(--border-light, #eae5dd);
    border-radius: var(--radius-lg, 0.75rem);
    box-shadow: var(--shadow-xs, 0 1px 2px rgba(0, 0, 0, 0.04));
    transition:
      border-color var(--transition-base, 200ms ease),
      box-shadow var(--transition-base, 200ms ease);
  }
  .dre-bcard:hover {
    border-color: color-mix(in srgb, var(--primary, #007a50) 40%, var(--border, #dcd6cb));
    box-shadow: var(--shadow-md, 0 4px 12px rgba(0, 0, 0, 0.08));
  }

  .dre-bcard__body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-bcard__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    min-height: 1.1rem;
  }
  .dre-bcard__year {
    color: var(--muted, #7a7164);
    font-size: var(--text-xs, 0.75rem);
    font-weight: 600;
    letter-spacing: 0.06em;
    font-variant-numeric: tabular-nums;
  }
  .dre-bcard__type {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: color-mix(in srgb, var(--primary, #007a50) 14%, var(--surface, #fdfcfa));
    color: var(--ink-strong, var(--ink, #33291f));
    border-radius: var(--radius-full, 9999px);
    font-size: var(--text-xs, 0.7rem);
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: capitalize;
    white-space: nowrap;
  }
  .dre-bcard__title {
    margin: 0;
    font-size: var(--text-lg, 1.125rem);
    line-height: 1.35;
    font-family: var(--font-display, Georgia, serif);
    color: var(--ink-strong, var(--ink, #33291f));
  }
  .dre-bcard__title a {
    color: inherit;
    text-decoration: none;
  }
  .dre-bcard__title a:hover {
    color: var(--primary, #007a50);
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .dre-bcard__authors {
    margin: 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #5f5648));
  }
  /* Author / editor names — plain-text buttons (click to filter), underlined,
     turning brand-coloured on hover. background:none strips the native button fill
     (the host theme no longer styles bare <button>s, so no override fight). */
  .dre-bcard__person {
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    cursor: pointer;
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
    text-decoration-color: color-mix(in srgb, currentColor 35%, transparent);
  }
  .dre-bcard__person:hover {
    color: var(--primary, #007a50);
    text-decoration-color: currentColor;
  }
  .dre-bcard__person:focus-visible {
    outline: none;
    border-radius: var(--radius-sm, 0.375rem);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.3));
  }
  .dre-bcard__ref {
    margin: 0;
    font-size: var(--text-sm, 0.85rem);
    color: var(--muted, #7a7164);
    line-height: 1.5;
  }
  .dre-bcard__venue {
    font-style: italic;
  }
  .dre-bcard__snippet {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #5f5648));
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
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
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
    min-width: 0;
  }
  .dre-bcard__chip {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: var(--surface-sunken, #f1ede6);
    color: var(--ink-light, var(--ink, #5f5648));
    border: none;
    border-radius: var(--radius-sm, 0.375rem);
    font-family: inherit;
    font-size: var(--text-xs, 0.75rem);
    font-weight: 500;
    line-height: 1.5;
    cursor: pointer;
    transition:
      background var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease);
  }
  .dre-bcard__chip:hover {
    background: color-mix(in srgb, var(--primary, #007a50) 18%, var(--surface, #fdfcfa));
    color: var(--ink-strong, var(--ink, #33291f));
  }
  .dre-bcard__chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.3));
  }
  .dre-bcard__doi {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.6rem;
    border: 1px solid color-mix(in srgb, var(--primary, #007a50) 40%, var(--border, #dcd6cb));
    border-radius: var(--radius-full, 9999px);
    color: var(--primary, #007a50);
    font-size: var(--text-xs, 0.72rem);
    font-weight: 700;
    letter-spacing: 0.04em;
    text-decoration: none;
    white-space: nowrap;
    transition:
      background var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease);
  }
  .dre-bcard__doi:hover {
    background: var(--primary, #007a50);
    color: var(--primary-contrast, #fdfcfa);
  }
</style>
