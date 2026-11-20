<script lang="ts">
  import type { Doc } from '../lib/types';
  import { cardTitleTag } from '../lib/headings';
  import { formatDate, t } from '../lib/i18n';
  import { markedLookup } from '../lib/highlight';
  import { cardSnippet, cardTitle, linkedPeople, recordUrl, titleMarkup } from '../lib/resultCard';
  import { safeExternalUrl } from '../lib/text';
  import FilterLink from './FilterLink.svelte';
  import Highlight from './Highlight.svelte';
  import FilterButton from './FilterButton.svelte';
  import MatchedIn from './MatchedIn.svelte';
  import CardThumb from './CardThumb.svelte';
  import '../styles/card.css';

  /**
   * One Cluster Chronicles card:
   *
   *   ┌────────────────────────────────────────────────┐
   *   │ ┌────┐  NO. 3 · May 2023                         │
   *   │ │img │  Work title                               │
   *   │ │    │  Translated title                         │
   *   │ └────┘  [Essay]                  ← type chip      │
   *   │         Author: Egara Kabaji     ← person links   │
   *   │         Short abstract…                           │
   *   │         Place: Kenya · Bayreuth                   │
   *   │         [Full text]  [ Cluster website ↗ ]        │
   *   └────────────────────────────────────────────────┘
   *
   * The type is the work's resource class (Essay, Poem, Image, Event, Video);
   * its chip filters by it. Each role the work credits gets a line, and the
   * people a photograph depicts the last one. Only photographs and drawings
   * have their own image, so most cards have no thumbnail. The full text is
   * searched but never shipped: a "Full text" badge says the work has one.
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

  const number = $derived(doc.number);
  const dateLabel = $derived(formatDate(doc.date_s));
  const type = $derived(doc.type_s ?? '');
  const alternative = $derived(doc.alternative_s ?? '');
  // Both are searched, so a match is marked where the card shows them and
  // left out of the "Matched in" line, which would only repeat them.
  const alternativeHl = $derived(doc._highlights?.alternative_s?.[0] ?? null);

  // People are searched through people_ss, so its highlights mark every role.
  const peopleHl = $derived(markedLookup(doc, 'people_ss'));
  const ROLES = [
    ['author_ss', 'author_ids', 'role_author'],
    ['photographer_ss', 'photographer_ids', 'role_photographer'],
    ['artist_ss', 'artist_ids', 'role_artist'],
    ['translator_ss', 'translator_ids', 'role_translator'],
    ['speaker_ss', 'speaker_ids', 'speaker_label'],
    ['moderator_ss', 'moderator_ids', 'role_moderator'],
    ['curator_ss', 'curator_ids', 'role_curator'],
    ['depicted_ss', 'depicted_ids', 'role_depicted'],
  ] as const;
  const roles = $derived(
    ROLES.map(([field, ids, label]) => ({
      label,
      people: linkedPeople(itemUrlBase, doc[field], doc[ids]),
    })).filter((role) => role.people.length > 0),
  );

  const places = $derived(doc.place_ss ?? []);
  const placeHl = $derived(markedLookup(doc, 'place_ss'));
  const languages = $derived(doc.language_ss ?? []);
  const hasContent = $derived(doc.has_content === true);

  // Abstract: the matched window when it matched, else the plain abstract.
  const snippet = $derived(cardSnippet(doc, ['abstract']));

  const source = $derived(safeExternalUrl(doc.url_s));
</script>

<article
  class="dre-shell dre-shell--media dre-ccard"
  class:dre-shell--no-thumb={!doc.thumbnail_url}
>
  <CardThumb href={url} url={doc.thumbnail_url} shape="item" />

  <div class="dre-shell__body">
    {#if number != null || dateLabel}
      <header class="dre-shell__head dre-ccard__head">
        {#if number != null}
          <span class="dre-ccard__number">{t('chronicle_number', { n: number })}</span>
        {/if}
        {#if dateLabel}
          <span class="dre-ccard__date">{dateLabel}</span>
        {/if}
      </header>
    {/if}

    <svelte:element this={titleTag} class="dre-shell__title">
      <a href={url}><Highlight value={titleHl ?? title} /></a>
    </svelte:element>

    {#if alternative}
      <p class="dre-ccard__alternative"><Highlight value={alternativeHl ?? alternative} /></p>
    {/if}

    {#if type}
      <ul class="dre-shell__chips">
        <li>
          <FilterButton field="type_s" value={type} class="dre-shell__chip dre-shell__chip--accent">
            {type}
          </FilterButton>
        </li>
      </ul>
    {/if}

    {#each roles as role (role.label)}
      <p class="dre-shell__line">
        <span class="dre-shell__label">{t(role.label)}</span>
        {#each role.people as p, i (p.name + '|' + i)}{i > 0 ? ', ' : ''}{#if p.href}<a
              class="dre-shell__person"
              href={p.href}><Highlight value={peopleHl.get(p.name) ?? p.name} /></a
            >{:else}<span><Highlight value={peopleHl.get(p.name) ?? p.name} /></span>{/if}{/each}
      </p>
    {/each}

    {#if snippet}
      <p class="dre-shell__snippet"><Highlight value={snippet} /></p>
    {/if}

    {#if places.length > 0}
      <p class="dre-shell__meta">
        <span class="dre-shell__label">{t('place_label')}</span>
        {#each places as l, i (l + '|' + i)}{i > 0 ? ' · ' : ''}<FilterLink
            field="place_ss"
            value={l}><Highlight value={placeHl.get(l) ?? l} /></FilterLink
          >{/each}
      </p>
    {/if}

    {#if languages.length > 0}
      <p class="dre-shell__meta">
        <span class="dre-shell__label">{t('language_label')}</span>
        {#each languages as l, i (l + '|' + i)}{i > 0 ? ' · ' : ''}<FilterLink
            field="language_ss"
            value={l}>{l}</FilterLink
          >{/each}
      </p>
    {/if}

    {#if hasContent || source}
      <div class="dre-shell__footer">
        {#if hasContent}
          <span class="dre-shell__badge" title={t('fulltext_link')}>{t('fulltext_link')}</span>
        {/if}
        {#if source}
          <a class="dre-shell__external" href={source} target="_blank" rel="noopener noreferrer">
            {t('chronicle_source_label')}
          </a>
        {/if}
      </div>
    {/if}

    <MatchedIn {doc} exclude={['title', 'alternative_s', 'abstract', 'people_ss', 'place_ss']} />
  </div>
</article>

<style>
  /* The shell, thumbnail, title, chips, bylines and footer are styles/card.css. */
  /* The head is the podcast card's: the number in the accent, then the date. */
  .dre-ccard__head {
    justify-content: normal;
    color: var(--muted, #716a66);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: var(--tracking-wide, 0.04em);
    font-variant-numeric: tabular-nums;
  }
  .dre-ccard__number {
    text-transform: uppercase;
    color: var(--primary, #007a50);
  }
  .dre-ccard__number + .dre-ccard__date::before {
    content: '· ';
  }
  .dre-ccard__alternative {
    margin: 0;
    color: var(--muted, #716a66);
    font-style: italic;
  }
</style>
