<script lang="ts">
  import type { Doc } from '../lib/types';
  import { cardTitleTag } from '../lib/headings';
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
  const titleTag = cardTitleTag();

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

  const listen = $derived(safeExternalUrl(doc.url_s));
</script>

<article
  class="dre-shell dre-shell--media dre-pcard"
  class:dre-shell--no-thumb={!doc.thumbnail_url}
>
  <CardThumb href={url} url={doc.thumbnail_url} shape="logo" />

  <div class="dre-shell__body">
    <header class="dre-shell__head dre-pcard__head">
      {#if episode != null}
        <span class="dre-pcard__episode">{t('episode_label', { n: episode })}</span>
      {/if}
      {#if dateLabel}
        <span class="dre-pcard__date">{dateLabel}</span>
      {/if}
    </header>

    <svelte:element this={titleTag} class="dre-shell__title">
      <a href={url}><Highlight value={titleHl ?? title} /></a>
    </svelte:element>

    {#if series}
      <ul class="dre-shell__chips">
        <li>
          <button
            type="button"
            class="dre-shell__chip dre-shell__chip--accent"
            onclick={() => onAddFilter('series_s', series)}
          >
            {series}
          </button>
          <RecordLink {itemUrlBase} id={doc.series_id} name={series} />
        </li>
      </ul>
    {/if}

    {#if hosts.length > 0}
      <p class="dre-shell__line">
        <span class="dre-shell__label">{t('host_label')}</span>
        {#each hosts as p, i (p.name + '|' + i)}{i > 0 ? ', ' : ''}{#if p.href}<a
              class="dre-shell__person"
              href={p.href}><Highlight value={hostHl.get(p.name) ?? p.name} /></a
            >{:else}<span><Highlight value={hostHl.get(p.name) ?? p.name} /></span>{/if}{/each}
      </p>
    {/if}

    {#if guests.length > 0}
      <p class="dre-shell__line">
        <span class="dre-shell__label">{t('guest_label')}</span>
        {#each guests as p, i (p.name + '|' + i)}{i > 0 ? ', ' : ''}{#if p.href}<a
              class="dre-shell__person"
              href={p.href}><Highlight value={guestHl.get(p.name) ?? p.name} /></a
            >{:else}<span><Highlight value={guestHl.get(p.name) ?? p.name} /></span>{/if}{/each}
      </p>
    {/if}

    {#if engineers.length > 0}
      <p class="dre-shell__line">
        <span class="dre-shell__label">{t('engineer_label')}</span>
        {#each engineers as p, i (p.name + '|' + i)}{i > 0 ? ', ' : ''}{#if p.href}<a
              class="dre-shell__person"
              href={p.href}><Highlight value={engineerHl.get(p.name) ?? p.name} /></a
            >{:else}<span><Highlight value={engineerHl.get(p.name) ?? p.name} /></span>{/if}{/each}
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

    {#if hasTranscript || listen}
      <div class="dre-shell__footer">
        {#if hasTranscript}
          <span class="dre-shell__badge" title={t('transcript_label')}>{t('transcript_label')}</span
          >
        {/if}
        {#if listen}
          <a class="dre-shell__external" href={listen} target="_blank" rel="noopener noreferrer">
            {t('listen_label')}
          </a>
        {/if}
      </div>
    {/if}

    <MatchedIn {doc} exclude={['title', 'abstract', 'host_ss', 'guest_ss', 'engineer_ss']} />
  </div>
</article>

<style>
  /* The shell, thumbnail, title, chips, bylines and footer are styles/card.css. */
  .dre-pcard__head {
    justify-content: normal;
    color: var(--muted, #716a66);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: var(--tracking-wide, 0.04em);
    font-variant-numeric: tabular-nums;
  }
  .dre-pcard__episode {
    text-transform: uppercase;
    color: var(--primary, #007a50);
  }
  .dre-pcard__date::before {
    content: '· ';
    color: var(--muted, #716a66);
  }
  .dre-shell--no-thumb .dre-pcard__date:first-child::before {
    content: '';
  }
  /* The language is a FilterLink span — see that component for the styling. */
</style>
