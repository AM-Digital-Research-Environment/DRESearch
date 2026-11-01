<script lang="ts">
  import type { Doc } from '../lib/types';
  import { cardTitleTag } from '../lib/headings';
  import { associationCounts, cardTitle, recordUrl, titleMarkup } from '../lib/resultCard';
  import { markedLookup } from '../lib/highlight';
  import Highlight from './Highlight.svelte';
  import FilterButton from './FilterButton.svelte';
  import FilterLink from './FilterLink.svelte';
  import CardThumb from './CardThumb.svelte';
  import IdentifierLinks from './IdentifierLinks.svelte';
  import { externalIds } from '../lib/identifiers';
  import '../styles/card.css';

  /**
   * One person card:
   *
   *   ┌──────────────────────────────────────────────────┐
   *   │ (◯)  Vierke, Ulf                                    │
   *   │      University of Bayreuth                          │
   *   │      [Principal investigator] [Author]              │  ← roles, click to filter
   *   │      3 research items · 2 publications               │  ← association counts
   *   │      [iD] 0000-0002-… ↗  [|||] Q123 ↗  [GND] 1324… ↗  │  ← reconciled identifiers
   *   └──────────────────────────────────────────────────┘
   *
   * Name links to the person's Omeka page; each affiliation (in the byline) and
   * each role chip toggles that value as a facet filter (lib/cardFilters.ts). Affiliations
   * used to be listed twice when roles existed, and not be clickable at all
   * when they did not.
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
  const affiliations = $derived(doc.affiliation_ss ?? []);
  const roles = $derived(doc.roles_ss ?? []);
  const affilHl = $derived(markedLookup(doc, 'affiliation_ss'));
  const roleHl = $derived(markedLookup(doc, 'roles_ss'));
  // ORCID, Wikidata, GND — the person as other registers know them.
  const ids = $derived(externalIds(doc.same_as_ss ?? []));

  // Association counts — only the non-zero ones, joined with "·".
  const counts = $derived(associationCounts(doc, ['items', 'publications']));
</script>

<article
  class="dre-shell dre-shell--media dre-person"
  class:dre-shell--no-thumb={!doc.thumbnail_url}
>
  <CardThumb href={url} url={doc.thumbnail_url} shape="avatar" />

  <div class="dre-shell__body">
    <svelte:element this={titleTag} class="dre-shell__title">
      <a href={url}><Highlight value={nameHl ?? name} /></a>
    </svelte:element>

    {#if affiliations.length > 0}
      <p class="dre-shell__line">
        {#each affiliations as aff, i (aff + '|' + i)}{i > 0 ? '; ' : ''}<FilterLink
            field="affiliation_ss"
            value={aff}><Highlight value={affilHl.get(aff) ?? aff} /></FilterLink
          >{/each}
      </p>
    {/if}

    {#if roles.length > 0}
      <ul class="dre-shell__chips">
        {#each roles as role (role)}
          <li>
            <FilterButton
              field="roles_ss"
              value={role}
              class="dre-shell__chip dre-shell__chip--role"
            >
              <Highlight value={roleHl.get(role) ?? role} />
            </FilterButton>
          </li>
        {/each}
      </ul>
    {/if}

    {#if counts.length > 0}
      <p class="dre-shell__counts dre-person__counts">{counts.join(' · ')}</p>
    {/if}

    <IdentifierLinks {ids} />
  </div>
</article>

<style>
  /* The shell, avatar, name, chips and counts are styles/card.css. */
  .dre-person {
    align-items: start;
  }
  .dre-person__counts {
    margin-top: var(--space-xs, 0.25rem);
  }
</style>
