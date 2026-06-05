<script lang="ts">
  import type { Doc } from '../lib/types';
  import { t } from '../lib/i18n';
  import { firstMarked, markedLookup } from '../lib/highlight';
  import Highlight from './Highlight.svelte';
  import MatchedIn from './MatchedIn.svelte';

  /**
   * One podcast-episode card:
   *
   *   ┌────────────────────────────────────────────────┐
   *   │ ┌────┐  EPISODE 34 · 28 Jan 2026                 │
   *   │ │logo│  Episode title                            │
   *   │ │    │  [Cluster Conversations]   ← series chip   │
   *   │ └────┘  Guest: Ute Fendler        ← person link   │
   *   │         Short abstract…                           │
   *   │         [ Listen ↗ ]                              │
   *   └────────────────────────────────────────────────┘
   *
   * The thumbnail is the podcast SERIES logo (every episode of a series shares it).
   * The series chip adds that series as a facet filter; hosts and guests link to
   * their person page; "Listen" opens the external episode/audio link. Matched
   * query terms are highlighted in the title, byline and snippet.
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
    onAddFilter: (field: string, value: string) => void;
  }

  const { doc, itemUrlBase, onAddFilter }: Props = $props();

  const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
  ];

  /** "2026-01-28" → "28 Jan 2026"; tolerates year-month and year-only values. */
  function formatDate(raw: string | undefined): string {
    if (!raw) {
      return '';
    }
    const ymd = /^(\d{4})-(\d{2})-(\d{2})/.exec(raw);
    if (ymd) {
      return `${Number(ymd[3])} ${MONTHS[Number(ymd[2]) - 1] ?? ''} ${ymd[1]}`.trim();
    }
    const ym = /^(\d{4})-(\d{2})/.exec(raw);
    if (ym) {
      return `${MONTHS[Number(ym[2]) - 1] ?? ''} ${ym[1]}`.trim();
    }
    const y = /^(\d{4})/.exec(raw);
    return y ? y[1] : raw;
  }

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

  const episode = $derived(doc.episode);
  const dateLabel = $derived(formatDate(doc.date_s));

  const series = $derived(doc.series_s ?? '');

  const hosts = $derived(people(doc.host_ss, doc.host_ids));
  const hostHl = $derived(markedLookup(doc, 'host_ss'));
  const guests = $derived(people(doc.guest_ss, doc.guest_ids));
  const guestHl = $derived(markedLookup(doc, 'guest_ss'));
  const engineers = $derived(people(doc.engineer_ss, doc.engineer_ids));
  const engineerHl = $derived(markedLookup(doc, 'engineer_ss'));

  const languages = $derived(doc.language_ss ?? []);
  const hasTranscript = $derived(doc.has_transcript === true);

  // Abstract: the matched window when it matched, else the plain abstract.
  const snippet = $derived(firstMarked(doc, ['abstract']) ?? (doc.abstract ?? '').trim());

  const listen = $derived(doc.url_s ?? '');
</script>

<article class="dre-pcard" class:dre-pcard--no-thumb={!doc.thumbnail_url}>
  {#if doc.thumbnail_url}
    <a class="dre-pcard__thumb" href={url} tabindex="-1" aria-hidden="true">
      <img src={doc.thumbnail_url} alt="" loading="lazy" />
    </a>
  {/if}

  <div class="dre-pcard__body">
    <header class="dre-pcard__head">
      {#if episode != null}
        <span class="dre-pcard__episode">{t('episode_label', { n: episode })}</span>
      {/if}
      {#if dateLabel}
        <span class="dre-pcard__date">{dateLabel}</span>
      {/if}
    </header>

    <h3 class="dre-pcard__title">
      <a href={url}><Highlight value={titleHl ?? title} /></a>
    </h3>

    {#if series}
      <ul class="dre-pcard__chips">
        <li>
          <button
            type="button"
            class="dre-pcard__chip"
            onclick={() => onAddFilter('series_s', series)}
          >
            {series}
          </button>
        </li>
      </ul>
    {/if}

    {#if hosts.length > 0}
      <p class="dre-pcard__people">
        <span class="dre-pcard__role">{t('host_label')}</span>
        {#each hosts as p, i (p.name + '|' + i)}{i > 0 ? ', ' : ''}{#if p.href}<a
              class="dre-pcard__person"
              href={p.href}><Highlight value={hostHl.get(p.name) ?? p.name} /></a
            >{:else}<span><Highlight value={hostHl.get(p.name) ?? p.name} /></span>{/if}{/each}
      </p>
    {/if}

    {#if guests.length > 0}
      <p class="dre-pcard__people">
        <span class="dre-pcard__role">{t('guest_label')}</span>
        {#each guests as p, i (p.name + '|' + i)}{i > 0 ? ', ' : ''}{#if p.href}<a
              class="dre-pcard__person"
              href={p.href}><Highlight value={guestHl.get(p.name) ?? p.name} /></a
            >{:else}<span><Highlight value={guestHl.get(p.name) ?? p.name} /></span>{/if}{/each}
      </p>
    {/if}

    {#if engineers.length > 0}
      <p class="dre-pcard__people">
        <span class="dre-pcard__role">{t('engineer_label')}</span>
        {#each engineers as p, i (p.name + '|' + i)}{i > 0 ? ', ' : ''}{#if p.href}<a
              class="dre-pcard__person"
              href={p.href}><Highlight value={engineerHl.get(p.name) ?? p.name} /></a
            >{:else}<span><Highlight value={engineerHl.get(p.name) ?? p.name} /></span>{/if}{/each}
      </p>
    {/if}

    {#if snippet}
      <p class="dre-pcard__snippet"><Highlight value={snippet} /></p>
    {/if}

    {#if languages.length > 0}
      <p class="dre-pcard__meta">
        <span class="dre-pcard__role">{t('language_label')}</span>
        {#each languages as l, i (l + '|' + i)}{i > 0 ? ' · ' : ''}<button
            type="button"
            class="dre-pcard__filter-link"
            onclick={() => onAddFilter('language_ss', l)}>{l}</button
          >{/each}
      </p>
    {/if}

    {#if hasTranscript || listen}
      <div class="dre-pcard__footer">
        {#if hasTranscript}
          <span class="dre-pcard__badge" title={t('transcript_label')}>{t('transcript_label')}</span
          >
        {/if}
        {#if listen}
          <a class="dre-pcard__listen" href={listen} target="_blank" rel="noopener noreferrer">
            {t('listen_label')}
          </a>
        {/if}
      </div>
    {/if}

    <MatchedIn {doc} exclude={['title', 'abstract', 'host_ss', 'guest_ss', 'engineer_ss']} />
  </div>
</article>

<style>
  .dre-pcard {
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
  .dre-pcard:hover {
    border-color: color-mix(in srgb, var(--primary, #007a50) 40%, var(--border, #dcd6cb));
    box-shadow: var(--shadow-md, 0 4px 12px rgba(0, 0, 0, 0.08));
  }
  .dre-pcard--no-thumb {
    grid-template-columns: 1fr;
  }

  .dre-pcard__thumb {
    display: block;
    width: 6rem;
    height: 6rem;
    border-radius: var(--radius-sm, 0.375rem);
    overflow: hidden;
    background: var(--surface-sunken, #f1ede6);
    border: 1px solid var(--border-light, #eae5dd);
  }
  .dre-pcard__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .dre-pcard__body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-pcard__head {
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
  .dre-pcard__episode {
    text-transform: uppercase;
    color: var(--primary, #007a50);
  }
  .dre-pcard__date::before {
    content: '· ';
    color: var(--muted, #a39a8c);
  }
  .dre-pcard--no-thumb .dre-pcard__date:first-child::before {
    content: '';
  }

  .dre-pcard__title {
    margin: 0;
    font-size: var(--text-lg, 1.125rem);
    line-height: 1.35;
    font-family: var(--font-display, Georgia, serif);
    color: var(--ink-strong, var(--ink, #33291f));
  }
  .dre-pcard__title a {
    color: inherit;
    text-decoration: none;
  }
  .dre-pcard__title a:hover {
    color: var(--primary, #007a50);
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  .dre-pcard__chips {
    list-style: none;
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
  }
  /* Chip is a button (click to filter by series); reset native chrome and suppress
     the host theme's primary-button hover lift/glow. */
  .dre-pcard__chip {
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
  .dre-pcard__chip:hover {
    background: color-mix(in srgb, var(--accent, #d57912) 30%, var(--surface, #fdfcfa));
    box-shadow: none !important;
    transform: none !important;
  }
  .dre-pcard__chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.3));
  }

  .dre-pcard__people {
    margin: 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #5f5648));
  }
  .dre-pcard__role {
    color: var(--muted, #7a7164);
    font-weight: 600;
    font-size: var(--text-xs, 0.78rem);
  }
  .dre-pcard__role::after {
    content: ': ';
  }
  /* Person links — underlined, brand-coloured on hover. The !important rules beat
     the host theme, which styles every <button>/<a> as a filled primary button. */
  .dre-pcard__person {
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
    text-decoration-color: color-mix(in srgb, currentColor 35%, transparent);
  }
  .dre-pcard__person:hover {
    color: var(--primary, #007a50);
    text-decoration-color: currentColor;
  }

  .dre-pcard__meta {
    margin: 0;
    font-size: var(--text-xs, 0.78rem);
    line-height: 1.5;
    color: var(--ink-light, var(--ink, #5f5648));
  }
  /* Inline "click to filter" value (language) — a plain text button, underlined,
     brand-coloured on hover. The !important rules beat the host theme, which styles
     every <button> as a filled primary button. */
  .dre-pcard__filter-link {
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
  .dre-pcard__filter-link:hover {
    color: var(--primary, #007a50) !important;
    text-decoration-color: currentColor;
  }
  .dre-pcard__filter-link:focus-visible {
    outline: none;
    border-radius: var(--radius-sm, 0.375rem);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.3)) !important;
  }

  .dre-pcard__snippet {
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

  .dre-pcard__footer {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-sm, 0.5rem);
    margin-top: var(--space-xs, 0.25rem);
  }
  /* Transcript-available badge — a neutral pill (distinct from the green Listen
     pill) flagging that the episode is full-text searchable. */
  .dre-pcard__badge {
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
  .dre-pcard__badge::before {
    content: '';
    width: 0.7rem;
    height: 0.7rem;
    background-color: currentColor;
    /* A small document glyph — pure CSS mask, no asset dependency. */
    --dre-doc: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='%23000' stroke-width='1.5'%3E%3Cpath d='M4 1.75h5l3 3v9.5H4z'/%3E%3Cpath d='M6 6.5h4M6 9h4M6 11.5h2.5'/%3E%3C/svg%3E");
    -webkit-mask: var(--dre-doc) center / contain no-repeat;
    mask: var(--dre-doc) center / contain no-repeat;
  }
  .dre-pcard__listen {
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
  .dre-pcard__listen::after {
    content: '↗';
    font-weight: 400;
  }
  .dre-pcard__listen:hover {
    background: var(--primary, #007a50);
    color: var(--primary-contrast, #fdfcfa);
  }

  @media (max-width: 32rem) {
    .dre-pcard {
      grid-template-columns: 1fr;
      gap: var(--space-sm, 0.5rem);
    }
    .dre-pcard__thumb {
      width: 100%;
      height: 8rem;
    }
  }
</style>
