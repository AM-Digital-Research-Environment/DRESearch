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
   *   │ Klaeger, Florian          (authors link to persons)   │
   *   │ In: Ganteau, J.-M.; Onega, S. (eds.), Handbook of …,   │
   *   │   vol. 4, pp. 141–165. Brill                           │
   *   │ Abstract, clamped to a few lines…                     │
   *   │ [keyword] [keyword]                            DOI ↗   │
   *   └────────────────────────────────────────────────────┘
   *
   * Authors link to their person page (when linked resources); the keyword chips
   * are buttons that add that value as a facet filter (onAddFilter); the DOI opens
   * the canonical record.
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

  // Authors paired with a link to their person page (unreconciled literals have
  // no id and render as plain text).
  const authors = $derived.by(() => {
    const names = doc.author_ss ?? [];
    const ids = doc.author_ids ?? [];
    return names.map((name, i) => {
      const id = ids[i] ?? '';
      return { name, href: id ? `${itemUrlBase}/${encodeURIComponent(id)}` : null };
    });
  });
  const authorHl = $derived(markedLookup(doc, 'author_ss'));

  const editors = $derived(doc.editor_ss ?? []);
  const container = $derived(doc.container_ss?.[0] ?? '');
  const containerHl = $derived(markedLookup(doc, 'container_ss'));
  const publisher = $derived(doc.publisher_ss?.[0] ?? '');

  // "In: Editors (eds.), " — only for edited volumes (a container with editors).
  const editorsPrefix = $derived.by(() => {
    if (!container || editors.length === 0) {
      return '';
    }
    const label = editors.length > 1 ? t('eds_short') : t('ed_short');
    return `${t('in_prefix')} ${editors.join('; ')} (${label}), `;
  });

  // Everything after the venue: ", vol. X(Y), pp. Z. Publisher" — separators
  // chosen so the line reads cleanly whether or not a venue precedes it.
  const tail = $derived.by(() => {
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
    let s = bits.join(', ');
    if (s && container) {
      s = `, ${s}`;
    }
    if (publisher) {
      s += `${s || container ? '. ' : ''}${publisher}`;
    }
    return s;
  });

  const hasReference = $derived(Boolean(editorsPrefix || container || tail));
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
        {#each authors as a, i (a.name + '|' + i)}{i > 0 ? ', ' : ''}{#if a.href}<a
              class="dre-bcard__author-link"
              href={a.href}><Highlight value={authorHl.get(a.name) ?? a.name} /></a
            >{:else}<span><Highlight value={authorHl.get(a.name) ?? a.name} /></span>{/if}{/each}
      </p>
    {/if}

    {#if hasReference}
      <p class="dre-bcard__ref">
        {editorsPrefix}{#if container}<cite class="dre-bcard__venue"
            ><Highlight value={containerHl.get(container) ?? container} /></cite
          >{/if}{tail}
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

    <MatchedIn {doc} exclude={['title', 'abstract', 'author_ss', 'container_ss', 'keyword_ss']} />
  </div>
</article>

<style>
  .dre-bcard {
    padding: var(--space-md, 1rem);
    background: var(--surface, #fff);
    border: 1px solid var(--border-light, #eee);
    border-radius: var(--radius-lg, 0.75rem);
    box-shadow: var(--shadow-xs, 0 1px 2px rgba(0, 0, 0, 0.04));
    transition:
      border-color var(--transition-base, 200ms ease),
      box-shadow var(--transition-base, 200ms ease);
  }
  .dre-bcard:hover {
    border-color: color-mix(in srgb, var(--primary, #2a4d8f) 40%, var(--border, #ccc));
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
    color: var(--muted, #666);
    font-size: var(--text-xs, 0.75rem);
    font-weight: 600;
    letter-spacing: 0.06em;
    font-variant-numeric: tabular-nums;
  }
  .dre-bcard__type {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: color-mix(in srgb, var(--primary, #2a4d8f) 14%, var(--surface, #fff));
    color: var(--ink-strong, var(--ink, #222));
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
    color: var(--ink-strong, var(--ink, #222));
  }
  .dre-bcard__title a {
    color: inherit;
    text-decoration: none;
  }
  .dre-bcard__title a:hover {
    color: var(--primary, #2a4d8f);
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .dre-bcard__authors {
    margin: 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #444));
  }
  .dre-bcard__author-link {
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
    text-decoration-color: color-mix(in srgb, currentColor 35%, transparent);
  }
  .dre-bcard__author-link:hover {
    color: var(--primary, #2a4d8f);
    text-decoration-color: currentColor;
  }
  .dre-bcard__ref {
    margin: 0;
    font-size: var(--text-sm, 0.85rem);
    color: var(--muted, #666);
    line-height: 1.5;
  }
  .dre-bcard__venue {
    font-style: italic;
  }
  .dre-bcard__snippet {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #444));
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
    background: var(--surface-sunken, #f5f5f5);
    color: var(--ink-light, var(--ink, #444));
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
    background: color-mix(in srgb, var(--primary, #2a4d8f) 18%, var(--surface, #fff));
    color: var(--ink-strong, var(--ink, #222));
    /* Suppress the host primary-button hover lift + green glow (chips are flat). */
    box-shadow: none !important;
    transform: none !important;
  }
  .dre-bcard__chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(42, 77, 143, 0.3));
  }
  .dre-bcard__doi {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.6rem;
    border: 1px solid color-mix(in srgb, var(--primary, #2a4d8f) 40%, var(--border, #ccc));
    border-radius: var(--radius-full, 9999px);
    color: var(--primary, #2a4d8f);
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
    background: var(--primary, #2a4d8f);
    color: var(--primary-contrast, #fff);
  }
</style>
