<script lang="ts">
  import type { Doc } from '../lib/types';
  import { cardTitleTag } from '../lib/headings';
  import {
    associationCounts,
    cardTitle,
    cardType,
    recordUrl,
    titleMarkup,
  } from '../lib/resultCard';
  import Highlight from './Highlight.svelte';
  import FilterButton from './FilterButton.svelte';
  import CardThumb from './CardThumb.svelte';
  import IdentifierLinks from './IdentifierLinks.svelte';
  import { externalIds } from '../lib/identifiers';
  import '../styles/card.css';

  /**
   * One organisation card (institution or group):
   *
   *   ┌──────────────────────────────────────────────────┐
   *   │ University of Bayreuth                  Institution │  ← type chip, click to filter
   *   │ [Funder] [Host institution]                          │  ← roles, click to filter
   *   │ 26 projects · 142 research items · 53 people          │  ← association counts
   *   └──────────────────────────────────────────────────┘
   *
   * Name links to the organisation's Omeka page; the type and role chips are
   * filter toggles (FilterButton, lib/cardFilters.ts).
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
  }

  const { doc, itemUrlBase }: Props = $props();
  const titleTag = cardTitleTag();

  const url = $derived(recordUrl(itemUrlBase, doc.id));
  const name = $derived(cardTitle(doc));
  const nameHl = $derived(titleMarkup(doc));
  const type = $derived(cardType(doc));
  const roles = $derived(doc.roles_ss ?? []);
  // The institution's Wikidata item, when it has been reconciled.
  const ids = $derived(externalIds(doc.same_as_ss ?? []));

  // Association counts — show only the non-zero ones, joined with "·". A group
  // typically shows just "N research items"; an institution shows projects/people.
  const counts = $derived(associationCounts(doc, ['projects', 'items', 'people']));
</script>

<article class="dre-shell dre-shell--media dre-org" class:dre-shell--no-thumb={!doc.thumbnail_url}>
  <CardThumb href={url} url={doc.thumbnail_url} shape="emblem" />

  <div class="dre-shell__body">
    <div class="dre-org__head">
      <svelte:element this={titleTag} class="dre-shell__title dre-org__name">
        <a href={url}><Highlight value={nameHl ?? name} /></a>
      </svelte:element>
      {#if type}
        <FilterButton
          field="type_s"
          value={type}
          class="dre-shell__tag dre-shell__tag--organisation"
        >
          {type}
        </FilterButton>
      {/if}
    </div>

    {#if roles.length > 0}
      <ul class="dre-shell__chips">
        {#each roles as role (role)}
          <li>
            <FilterButton
              field="roles_ss"
              value={role}
              class="dre-shell__chip dre-shell__chip--role"
            >
              {role}
            </FilterButton>
          </li>
        {/each}
      </ul>
    {/if}

    {#if counts.length > 0}
      <p class="dre-shell__counts dre-org__counts">{counts.join(' · ')}</p>
    {/if}

    <IdentifierLinks {ids} />
  </div>
</article>

<style>
  /* The shell, emblem, name, tag, chips and counts are styles/card.css. */
  .dre-org {
    align-items: start;
  }
  .dre-org__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
  }
  .dre-org__name {
    min-width: 0;
  }
  .dre-org__counts {
    margin-top: var(--space-xs, 0.25rem);
  }
</style>
