<script lang="ts">
  import type { Doc } from '../lib/types';
  import { formatDate, t } from '../lib/i18n';
  import { firstMarked, markedLookup } from '../lib/highlight';
  import { safeExternalUrl } from '../lib/text';
  import Highlight from './Highlight.svelte';
  import MatchedIn from './MatchedIn.svelte';

  /**
   * One YouTube-video card:
   *
   *   ┌────────────────────────────────────────────────┐
   *   │ ┌────┐  29 Mar 2026                              │
   *   │ │ ▷  │  Video title                              │
   *   │ │poster  [Cinema Africa 2024/25]  ← playlist chip │
   *   │ └────┘  Speaker: Sana Na N'Hada   ← person link   │
   *   │         Short abstract…                           │
   *   │         Language: French                          │
   *   │         [Transcript]  [ Watch ↗ ]                 │
   *   └────────────────────────────────────────────────┘
   *
   * The thumbnail is the video's own poster frame. The playlist chip adds that
   * playlist as a facet filter; speakers link to their person page; "Watch" opens
   * the external YouTube link. Matched query terms (incl. transcript hits, surfaced
   * via "Matched in") are highlighted in the title, byline and snippet.
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
    onAddFilter: (field: string, value: string) => void;
  }

  const { doc, itemUrlBase, onAddFilter }: Props = $props();

  function people(names: string[] | undefined, ids: string[] | undefined) {
    const list = names ?? [];
    const idList = ids ?? [];
    return list.map((name, i) => {
      const id = idList[i] ?? '';
      return { name, href: id ? `${itemUrlBase}/${encodeURIComponent(id)}` : null };
    });
  }

  const url = $derived(`${itemUrlBase}/${encodeURIComponent(doc.id)}`);
  const title = $derived(doc.title || t('untitled'));
  const titleHl = $derived(doc._highlights?.title?.[0] ?? null);

  const dateLabel = $derived(formatDate(doc.date_s));

  const playlist = $derived(doc.playlist_s ?? '');

  const speakers = $derived(people(doc.speaker_ss, doc.speaker_ids));
  const speakerHl = $derived(markedLookup(doc, 'speaker_ss'));

  const languages = $derived(doc.language_ss ?? []);
  const hasTranscript = $derived(doc.has_transcript === true);

  // Abstract: the matched window when it matched, else the plain abstract.
  const snippet = $derived(firstMarked(doc, ['abstract']) ?? (doc.abstract ?? '').trim());

  const watch = $derived(safeExternalUrl(doc.url_s));
</script>

<article class="dre-vcard" class:dre-vcard--no-thumb={!doc.thumbnail_url}>
  {#if doc.thumbnail_url}
    <a class="dre-vcard__thumb" href={url} tabindex="-1" aria-hidden="true">
      <img src={doc.thumbnail_url} alt="" loading="lazy" />
    </a>
  {/if}

  <div class="dre-vcard__body">
    {#if dateLabel}
      <header class="dre-vcard__head">
        <span class="dre-vcard__date">{dateLabel}</span>
      </header>
    {/if}

    <h3 class="dre-vcard__title">
      <a href={url}><Highlight value={titleHl ?? title} /></a>
    </h3>

    {#if playlist}
      <ul class="dre-vcard__chips">
        <li>
          <button
            type="button"
            class="dre-vcard__chip"
            onclick={() => onAddFilter('playlist_s', playlist)}
          >
            {playlist}
          </button>
        </li>
      </ul>
    {/if}

    {#if speakers.length > 0}
      <p class="dre-vcard__people">
        <span class="dre-vcard__role">{t('speaker_label')}</span>
        {#each speakers as p, i (p.name + '|' + i)}{i > 0 ? ', ' : ''}{#if p.href}<a
              class="dre-vcard__person"
              href={p.href}><Highlight value={speakerHl.get(p.name) ?? p.name} /></a
            >{:else}<span><Highlight value={speakerHl.get(p.name) ?? p.name} /></span>{/if}{/each}
      </p>
    {/if}

    {#if snippet}
      <p class="dre-vcard__snippet"><Highlight value={snippet} /></p>
    {/if}

    {#if languages.length > 0}
      <p class="dre-vcard__meta">
        <span class="dre-vcard__role">{t('language_label')}</span>
        {#each languages as l, i (l + '|' + i)}{i > 0 ? ' · ' : ''}<button
            type="button"
            class="dre-vcard__filter-link"
            onclick={() => onAddFilter('language_ss', l)}>{l}</button
          >{/each}
      </p>
    {/if}

    {#if hasTranscript || watch}
      <div class="dre-vcard__footer">
        {#if hasTranscript}
          <span class="dre-vcard__badge" title={t('transcript_label')}>{t('transcript_label')}</span
          >
        {/if}
        {#if watch}
          <a class="dre-vcard__watch" href={watch} target="_blank" rel="noopener noreferrer">
            {t('watch_label')}
          </a>
        {/if}
      </div>
    {/if}

    <MatchedIn {doc} exclude={['title', 'abstract', 'speaker_ss']} />
  </div>
</article>

<style>
  .dre-vcard {
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
  .dre-vcard:hover {
    border-color: color-mix(in srgb, var(--primary, #007a50) 40%, var(--border, #dcd6cb));
    box-shadow: var(--shadow-md, 0 4px 12px rgba(0, 0, 0, 0.08));
  }
  .dre-vcard--no-thumb {
    grid-template-columns: 1fr;
  }

  /* A 16:9 poster frame (YouTube's native ratio), rather than the podcast card's
     square logo. */
  .dre-vcard__thumb {
    display: block;
    width: 10.5rem;
    aspect-ratio: 16 / 9;
    border-radius: var(--radius-sm, 0.375rem);
    overflow: hidden;
    background: var(--surface-sunken, #f1ede6);
    border: 1px solid var(--border-light, #eae5dd);
  }
  .dre-vcard__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .dre-vcard__body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-vcard__head {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    min-height: 1.1rem;
    color: var(--muted, #7a7164);
    font-size: var(--text-xs, 0.75rem);
    font-weight: 600;
    letter-spacing: 0.06em;
    font-variant-numeric: tabular-nums;
  }

  .dre-vcard__title {
    margin: 0;
    font-size: var(--text-lg, 1.125rem);
    line-height: 1.35;
    font-family: var(--font-display, Georgia, serif);
    color: var(--ink-strong, var(--ink, #33291f));
  }
  .dre-vcard__title a {
    color: inherit;
    text-decoration: none;
  }
  .dre-vcard__title a:hover {
    color: var(--primary, #007a50);
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  .dre-vcard__chips {
    list-style: none;
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
  }
  /* Chip is a button (click to filter by playlist); reset the native chrome. */
  .dre-vcard__chip {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: color-mix(in srgb, var(--accent, #d57912) 16%, var(--surface, #fdfcfa));
    color: var(--ink-strong, var(--ink, #33291f));
    border: none;
    border-radius: var(--radius-sm, 0.375rem);
    font-family: inherit;
    font-size: var(--text-xs, 0.75rem);
    font-weight: 600;
    line-height: 1.5;
    cursor: pointer;
    transition: background var(--transition-fast, 150ms ease);
  }
  .dre-vcard__chip:hover {
    background: color-mix(in srgb, var(--accent, #d57912) 30%, var(--surface, #fdfcfa));
  }
  .dre-vcard__chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.3));
  }

  .dre-vcard__people {
    margin: 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #5f5648));
  }
  .dre-vcard__role {
    color: var(--muted, #7a7164);
    font-weight: 600;
    font-size: var(--text-xs, 0.78rem);
  }
  .dre-vcard__role::after {
    content: ': ';
  }
  /* Person links — underlined, brand-coloured on hover. */
  .dre-vcard__person {
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
    text-decoration-color: color-mix(in srgb, currentColor 35%, transparent);
  }
  .dre-vcard__person:hover {
    color: var(--primary, #007a50);
    text-decoration-color: currentColor;
  }

  .dre-vcard__meta {
    margin: 0;
    font-size: var(--text-xs, 0.78rem);
    line-height: 1.5;
    color: var(--ink-light, var(--ink, #5f5648));
  }
  /* Inline "click to filter" value (language) — a plain text button, underlined,
     brand-coloured on hover. `background: none` resets the native button chrome. */
  .dre-vcard__filter-link {
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
  .dre-vcard__filter-link:hover {
    color: var(--primary, #007a50);
    text-decoration-color: currentColor;
  }
  .dre-vcard__filter-link:focus-visible {
    outline: none;
    border-radius: var(--radius-sm, 0.375rem);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.3));
  }

  .dre-vcard__snippet {
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

  .dre-vcard__footer {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-sm, 0.5rem);
    margin-top: var(--space-xs, 0.25rem);
  }
  /* Transcript-available badge — a neutral pill (distinct from the green Watch
     pill) flagging that the video is full-text searchable. */
  .dre-vcard__badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.15rem 0.6rem;
    background: var(--surface-sunken, #f1ede6);
    color: var(--muted, #7a7164);
    border-radius: var(--radius-full, 9999px);
    font-size: var(--text-xs, 0.72rem);
    font-weight: 600;
    letter-spacing: 0.04em;
    white-space: nowrap;
  }
  .dre-vcard__badge::before {
    content: '';
    width: 0.7rem;
    height: 0.7rem;
    background-color: currentColor;
    /* A small document glyph — pure CSS mask, no asset dependency. */
    --dre-doc: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='%23000' stroke-width='1.5'%3E%3Cpath d='M4 1.75h5l3 3v9.5H4z'/%3E%3Cpath d='M6 6.5h4M6 9h4M6 11.5h2.5'/%3E%3C/svg%3E");
    -webkit-mask: var(--dre-doc) center / contain no-repeat;
    mask: var(--dre-doc) center / contain no-repeat;
  }
  /* "Watch" link — a brand-coloured outline pill that fills on hover, with an
     external-link arrow. */
  .dre-vcard__watch {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.15rem 0.7rem;
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
  .dre-vcard__watch::after {
    content: '↗';
    font-weight: 400;
  }
  .dre-vcard__watch:hover {
    background: var(--primary, #007a50);
    color: var(--primary-contrast, #fdfcfa);
  }

  @media (max-width: 32rem) {
    .dre-vcard {
      grid-template-columns: 1fr;
      gap: var(--space-sm, 0.5rem);
    }
    .dre-vcard__thumb {
      width: 100%;
    }
  }
</style>
