<script lang="ts">
  import type { Doc, ViewMode } from '../lib/types';
  import { t } from '../lib/i18n';
  import { firstMarked, markedLookup } from '../lib/highlight';
  import FilterLink from './FilterLink.svelte';
  import Highlight from './Highlight.svelte';
  import MatchedIn from './MatchedIn.svelte';
  import { thumbnailFor } from '../lib/thumbnail';

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
    view?: ViewMode;
  }

  const { doc, itemUrlBase, onAddFilter, view = 'list' }: Props = $props();

  const url = $derived(`${itemUrlBase}/${encodeURIComponent(doc.id)}`);
  const title = $derived(doc.title || t('untitled'));
  const titleHl = $derived(doc._highlights?.title?.[0] ?? null);
  const thumbnail = $derived(
    thumbnailFor(doc.thumbnail_url, view === 'gallery' ? 'gallery' : 'list'),
  );

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

<article class="dre-card" class:dre-card--gallery={view === 'gallery'}>
  {#if thumbnail}
    <a class="dre-card__thumb" href={url} tabindex="-1" aria-hidden="true">
      <img src={thumbnail} alt="" loading="lazy" />
    </a>
  {:else}
    <div class="dre-card__thumb dre-card__thumb--empty" aria-hidden="true"></div>
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
        {#each creators as name, i (name + '|' + i)}{i > 0 ? ', ' : ''}<FilterLink
            onclick={() => onAddFilter('creator_ss', name)}
            ><Highlight value={creatorHl.get(name) ?? name} /></FilterLink
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
        {#each origins as o, i (o + '|' + i)}{i > 0 ? ' · ' : ''}<FilterLink
            onclick={() => onAddFilter('origin_ss', o)}>{o}</FilterLink
          >{/each}
      </p>
    {/if}

    {#if currentLocations.length > 0}
      <p class="dre-card__geo">
        <span class="dre-card__geo-label">{t('current_location_label')}</span>
        {#each currentLocations as c, i (c + '|' + i)}{i > 0 ? ' · ' : ''}<FilterLink
            onclick={() => onAddFilter('provenance_ss', c)}>{c}</FilterLink
          >{/each}
      </p>
    {/if}

    {#if languages.length > 0}
      <p class="dre-card__geo">
        <span class="dre-card__geo-label">{t('language_label')}</span>
        {#each languages as l, i (l + '|' + i)}{i > 0 ? ' · ' : ''}<FilterLink
            onclick={() => onAddFilter('language_ss', l)}>{l}</FilterLink
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
    background: var(--surface, #fdfcf9);
    border: 1px solid var(--border-light, #eae8e3);
    border-radius: var(--radius-lg, 0.75rem);
    box-shadow: var(--shadow-xs, 0 1px 2px 0 rgba(52, 37, 26, 0.07));
    transition:
      border-color var(--transition-base, 200ms cubic-bezier(0.25, 1, 0.5, 1)),
      box-shadow var(--transition-base, 200ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-card:hover {
    border-color: color-mix(in srgb, var(--primary, #007a50) 40%, var(--border, #dbd7d1));
    box-shadow: var(
      --shadow-md,
      0 4px 6px -1px rgba(42, 28, 16, 0.14),
      0 2px 4px -2px rgba(52, 37, 26, 0.07)
    );
  }
  .dre-card__thumb {
    display: block;
    width: 7rem;
    height: 7rem;
    border-radius: var(--radius-sm, 0.375rem);
    overflow: hidden;
    background: var(--surface-sunken, #f3f0eb);
    border: 1px solid var(--border-light, #eae8e3);
  }
  .dre-card__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    filter: saturate(0.82) contrast(0.96);
    transition: filter var(--transition-base, 200ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-card:hover .dre-card__thumb img {
    filter: saturate(1) contrast(1);
  }
  .dre-card__thumb--empty {
    background: linear-gradient(
      135deg,
      var(--surface-sunken, #f3f0eb),
      var(--border-light, #eae8e3)
    );
  }
  .dre-card--gallery {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 0;
    overflow: hidden;
  }
  .dre-card--gallery .dre-card__thumb {
    width: 100%;
    height: auto;
    aspect-ratio: 4/3;
    border: 0;
    border-radius: 0;
  }
  .dre-card--gallery .dre-card__body {
    padding: var(--space-md, 1rem);
  }
  .dre-card--gallery .dre-card__snippet,
  .dre-card--gallery .dre-card__geo,
  .dre-card--gallery :global(.dre-matched-in) {
    display: none;
  }
  /* Mode comes from the THEME, never from the OS. The DRE theme's head script
     resolves the mode (stored choice, else OS preference) and writes it to
     [data-theme] on <html> and <body> before first paint, so this is the same
     switch the rest of the client follows. Asking prefers-color-scheme here
     instead meant a system-dark visitor who chose light got a light page with
     dark-tuned thumbnails. See DESIGN.md §9 "Mode selectors". */
  :global([data-theme='dark']) .dre-card__thumb {
    border-color: var(--border, #2c3531);
  }
  :global([data-theme='dark']) .dre-card__thumb img {
    filter: saturate(0.72) brightness(0.9) contrast(1.08);
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
    color: var(--muted, #716a66);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    font-variant-numeric: tabular-nums;
  }
  .dre-card__type {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: color-mix(in srgb, var(--primary, #007a50) 14%, var(--surface, #fdfcf9));
    color: var(--ink-strong, #261d15);
    border-radius: var(--radius-full, 9999px);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
  }
  .dre-card__title {
    margin: 0;
    font-size: var(--text-lg, 1.1875rem);
    line-height: var(--leading-snug, 1.25);
    font-family: var(--font-display, 'Spectral', Georgia, 'Times New Roman', serif);
    color: var(--ink-strong, #261d15);
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
    font-size: var(--text-sm, 0.9375rem);
    color: var(--ink-light, #5f5650);
  }
  .dre-card__snippet {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-sm, 0.9375rem);
    color: var(--ink-light, #5f5650);
    line-height: var(--leading-normal, 1.6);
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
  /* Chips are buttons (click to filter); reset the native button chrome. */
  .dre-card__chip {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: var(--surface-sunken, #f3f0eb);
    color: var(--ink-light, #5f5650);
    border: none;
    border-radius: var(--radius-sm, 0.375rem);
    font-family: inherit;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 500;
    line-height: var(--leading-normal, 1.6);
    cursor: pointer;
    transition:
      background var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-card__chip:hover {
    background: color-mix(in srgb, var(--primary, #007a50) 18%, var(--surface, #fdfcf9));
    color: var(--ink-strong, #261d15);
  }
  .dre-card__chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }
  .dre-card__chip--project {
    background: color-mix(in srgb, var(--accent, #ca7210) 16%, var(--surface, #fdfcf9));
    color: var(--ink-strong, #261d15);
    font-weight: 600;
  }
  .dre-card__chip--project:hover {
    background: color-mix(in srgb, var(--accent, #ca7210) 30%, var(--surface, #fdfcf9));
  }
  .dre-card__geo {
    margin: 0;
    font-size: var(--text-xs, 0.8125rem);
    line-height: var(--leading-normal, 1.6);
    color: var(--ink-light, #5f5650);
  }
  .dre-card__geo-label {
    color: var(--muted, #716a66);
    font-weight: 600;
  }
  .dre-card__geo-label::after {
    content: ': ';
  }
  /* Inline "click to filter" values (authors, places, language) are FilterLink
     spans — see that component for the styling and the rationale. */

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
