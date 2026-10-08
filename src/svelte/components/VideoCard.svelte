<script lang="ts">
  import type { Doc } from '../lib/types';
  import { formatDate, t } from '../lib/i18n';
  import { firstMarked, markedLookup } from '../lib/highlight';
  import { safeExternalUrl } from '../lib/text';
  import FilterLink from './FilterLink.svelte';
  import Highlight from './Highlight.svelte';
  import MatchedIn from './MatchedIn.svelte';
  import RecordLink from './RecordLink.svelte';
  import CardThumb from './CardThumb.svelte';
  import '../styles/card.css';

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

<article
  class="dre-shell dre-shell--media dre-vcard"
  class:dre-shell--no-thumb={!doc.thumbnail_url}
>
  <CardThumb href={url} url={doc.thumbnail_url} shape="poster" />

  <div class="dre-shell__body">
    {#if dateLabel}
      <header class="dre-shell__head dre-vcard__head">
        <span class="dre-vcard__date">{dateLabel}</span>
      </header>
    {/if}

    <h3 class="dre-shell__title">
      <a href={url}><Highlight value={titleHl ?? title} /></a>
    </h3>

    {#if playlist}
      <ul class="dre-shell__chips">
        <li>
          <button
            type="button"
            class="dre-shell__chip dre-shell__chip--accent"
            onclick={() => onAddFilter('playlist_s', playlist)}
          >
            {playlist}
          </button>
          <RecordLink {itemUrlBase} id={doc.playlist_id} name={playlist} />
        </li>
      </ul>
    {/if}

    {#if speakers.length > 0}
      <p class="dre-shell__line">
        <span class="dre-shell__label">{t('speaker_label')}</span>
        {#each speakers as p, i (p.name + '|' + i)}{i > 0 ? ', ' : ''}{#if p.href}<a
              class="dre-shell__person"
              href={p.href}><Highlight value={speakerHl.get(p.name) ?? p.name} /></a
            >{:else}<span><Highlight value={speakerHl.get(p.name) ?? p.name} /></span>{/if}{/each}
      </p>
    {/if}

    {#if snippet}
      <p class="dre-shell__snippet"><Highlight value={snippet} /></p>
    {/if}

    {#if languages.length > 0}
      <p class="dre-shell__meta">
        <span class="dre-shell__label">{t('language_label')}</span>
        {#each languages as l, i (l + '|' + i)}{i > 0 ? ' · ' : ''}<FilterLink
            onclick={() => onAddFilter('language_ss', l)}>{l}</FilterLink
          >{/each}
      </p>
    {/if}

    {#if hasTranscript || watch}
      <div class="dre-shell__footer">
        {#if hasTranscript}
          <span class="dre-shell__badge" title={t('transcript_label')}>{t('transcript_label')}</span
          >
        {/if}
        {#if watch}
          <a class="dre-shell__external" href={watch} target="_blank" rel="noopener noreferrer">
            {t('watch_label')}
          </a>
        {/if}
      </div>
    {/if}

    <MatchedIn {doc} exclude={['title', 'abstract', 'speaker_ss']} />
  </div>
</article>

<style>
  /* The shell, poster, title, chips, bylines and footer are styles/card.css. */
  .dre-vcard__head {
    justify-content: normal;
    color: var(--muted, #716a66);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: var(--tracking-wide, 0.04em);
    font-variant-numeric: tabular-nums;
  }
  /* The language is a FilterLink span — see that component for the styling. */
</style>
