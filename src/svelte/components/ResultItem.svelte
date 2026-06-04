<script lang="ts">
  import type { Doc } from '../lib/types';
  import { t } from '../lib/i18n';
  import { firstMarked, markedLookup } from '../lib/highlight';
  import Highlight from './Highlight.svelte';
  import MatchedIn from './MatchedIn.svelte';

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
   * are buttons that add that value as a facet filter (onAddFilter). Matched query
   * terms are highlighted in the title, byline and snippet; matches in fields the
   * card doesn't show surface in a "Matched in" line.
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

  // Authors / contributors — clickable (filters creator_ss) and highlighted.
  const creators = $derived(doc.creator_ss ?? []);
  const creatorHl = $derived(markedLookup(doc, 'creator_ss'));

  // Snippet: whichever of abstract/description matched (centred on the match),
  // else the abstract (or description) shown plainly.
  const snippet = $derived(
    firstMarked(doc, ['abstract', 'description']) ?? (doc.abstract ?? doc.description ?? '').trim(),
  );

  const project = $derived(doc.project_s ?? '');
  // Geographic provenance: where the item is from (the specific place as recorded,
  // e.g. "Bayreuth") vs where it is held now (specific place or repository
  // institution). Both are the verbatim linked place — not the country roll-up.
  const origins = $derived(doc.origin_ss ?? []);
  const currentLocations = $derived(doc.provenance_ss ?? []);
  const languages = $derived(doc.language_ss ?? []);
</script>

<article class="dre-card" class:dre-card--no-thumb={!doc.thumbnail_url}>
  {#if doc.thumbnail_url}
    <a class="dre-card__thumb" href={url} tabindex="-1" aria-hidden="true">
      <img src={doc.thumbnail_url} alt="" loading="lazy" />
    </a>
  {/if}

  <div class="dre-card__body">
    <header class="dre-card__head">
      {#if doc.year}
        <span class="dre-card__eyebrow">{doc.year}</span>
      {/if}
      {#if doc.type_s}
        <span class="dre-card__type">{doc.type_s}</span>
      {/if}
    </header>

    <h3 class="dre-card__title">
      <a href={url}><Highlight value={titleHl ?? title} /></a>
    </h3>

    {#if creators.length > 0}
      <p class="dre-card__byline">
        {#each creators as name, i (name + '|' + i)}{i > 0 ? ', ' : ''}<button
            type="button"
            class="dre-card__filter-link"
            onclick={() => onAddFilter('creator_ss', name)}
            ><Highlight value={creatorHl.get(name) ?? name} /></button
          >{/each}
      </p>
    {/if}

    {#if snippet}
      <p class="dre-card__snippet"><Highlight value={snippet} /></p>
    {/if}

    {#if project}
      <ul class="dre-card__chips">
        <li>
          <button
            type="button"
            class="dre-card__chip dre-card__chip--project"
            onclick={() => onAddFilter('project_s', project)}
          >
            {project}
          </button>
        </li>
      </ul>
    {/if}

    {#if origins.length > 0}
      <p class="dre-card__geo">
        <span class="dre-card__geo-label">{t('origin_label')}</span>
        {#each origins as o, i (o + '|' + i)}{i > 0 ? ' · ' : ''}<button
            type="button"
            class="dre-card__filter-link"
            onclick={() => onAddFilter('origin_ss', o)}>{o}</button
          >{/each}
      </p>
    {/if}

    {#if currentLocations.length > 0}
      <p class="dre-card__geo">
        <span class="dre-card__geo-label">{t('current_location_label')}</span>
        {#each currentLocations as c, i (c + '|' + i)}{i > 0 ? ' · ' : ''}<button
            type="button"
            class="dre-card__filter-link"
            onclick={() => onAddFilter('provenance_ss', c)}>{c}</button
          >{/each}
      </p>
    {/if}

    {#if languages.length > 0}
      <p class="dre-card__geo">
        <span class="dre-card__geo-label">{t('language_label')}</span>
        {#each languages as l, i (l + '|' + i)}{i > 0 ? ' · ' : ''}<button
            type="button"
            class="dre-card__filter-link"
            onclick={() => onAddFilter('language_ss', l)}>{l}</button
          >{/each}
      </p>
    {/if}

    <MatchedIn {doc} exclude={['title', 'abstract', 'description', 'creator_ss']} />
  </div>
</article>

<style>
  .dre-card {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--space-md, 1rem);
    padding: var(--space-md, 1rem);
    background: var(--surface, #fdfcfa);
    border: 1px solid var(--border-light, #eae5dd);
    border-radius: var(--radius-lg, 0.75rem);
    box-shadow: var(--shadow-xs, 0 1px 2px rgba(0, 0, 0, 0.04));
    transition:
      border-color var(--transition-base, 200ms ease),
      box-shadow var(--transition-base, 200ms ease);
  }
  .dre-card:hover {
    border-color: color-mix(in srgb, var(--primary, #007a50) 40%, var(--border, #dcd6cb));
    box-shadow: var(--shadow-md, 0 4px 12px rgba(0, 0, 0, 0.08));
  }
  .dre-card--no-thumb {
    grid-template-columns: 1fr;
  }

  .dre-card__thumb {
    display: block;
    width: 6rem;
    height: 6rem;
    border-radius: var(--radius-sm, 0.375rem);
    overflow: hidden;
    background: var(--surface-sunken, #f1ede6);
    border: 1px solid var(--border-light, #eae5dd);
  }
  .dre-card__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .dre-card__body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-card__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    min-height: 1.1rem;
  }
  .dre-card__eyebrow {
    color: var(--muted, #7a7164);
    font-size: var(--text-xs, 0.75rem);
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    font-variant-numeric: tabular-nums;
  }
  .dre-card__type {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: color-mix(in srgb, var(--primary, #007a50) 14%, var(--surface, #fdfcfa));
    color: var(--ink-strong, var(--ink, #33291f));
    border-radius: var(--radius-full, 9999px);
    font-size: var(--text-xs, 0.7rem);
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
  }
  .dre-card__title {
    margin: 0;
    font-size: var(--text-lg, 1.125rem);
    line-height: 1.35;
    font-family: var(--font-display, Georgia, serif);
    color: var(--ink-strong, var(--ink, #33291f));
  }
  .dre-card__title a {
    color: inherit;
    text-decoration: none;
  }
  .dre-card__title a:hover {
    color: var(--primary, #007a50);
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .dre-card__byline {
    margin: 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #5f5648));
  }
  .dre-card__snippet {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #5f5648));
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .dre-card__chips {
    list-style: none;
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
  }
  /* Chips are buttons (click to filter); reset the native chrome and suppress the
     host theme's primary-button hover lift/glow. */
  .dre-card__chip {
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
  .dre-card__chip:hover {
    background: color-mix(in srgb, var(--primary, #007a50) 18%, var(--surface, #fdfcfa));
    color: var(--ink-strong, var(--ink, #33291f));
    box-shadow: none !important;
    transform: none !important;
  }
  .dre-card__chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.3));
  }
  .dre-card__chip--project {
    background: color-mix(in srgb, var(--accent, #d57912) 16%, var(--surface, #fdfcfa));
    color: var(--ink-strong, var(--ink, #33291f));
    font-weight: 600;
  }
  .dre-card__chip--project:hover {
    background: color-mix(in srgb, var(--accent, #d57912) 30%, var(--surface, #fdfcfa));
  }
  .dre-card__geo {
    margin: 0;
    font-size: var(--text-xs, 0.78rem);
    line-height: 1.5;
    color: var(--ink-light, var(--ink, #5f5648));
  }
  .dre-card__geo-label {
    color: var(--muted, #7a7164);
    font-weight: 600;
  }
  .dre-card__geo-label::after {
    content: ': ';
  }
  /* Inline "click to filter" values (authors, places, language) — a plain text
     button, underlined, that turns brand-coloured on hover. The !important rules
     beat the host theme, which styles every <button> as a filled primary button. */
  .dre-card__filter-link {
    padding: 0;
    border: none;
    background: none !important;
    box-shadow: none !important;
    transform: none !important;
    font: inherit;
    cursor: pointer;
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
    text-decoration-color: color-mix(in srgb, currentColor 35%, transparent);
  }
  .dre-card__filter-link:hover {
    color: var(--primary, #007a50) !important;
    text-decoration-color: currentColor;
  }
  .dre-card__filter-link:focus-visible {
    outline: none;
    border-radius: var(--radius-sm, 0.375rem);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.3)) !important;
  }

  @media (max-width: 32rem) {
    .dre-card {
      grid-template-columns: 1fr;
      gap: var(--space-sm, 0.5rem);
    }
    .dre-card__thumb {
      width: 100%;
      height: 8rem;
    }
  }
</style>
