<script lang="ts">
  import type { Doc } from '../lib/types';
  import { cardTitleTag } from '../lib/headings';
  import { t, researchItemsLabel, publicationsLabel } from '../lib/i18n';
  import { markedLookup } from '../lib/highlight';
  import Highlight from './Highlight.svelte';
  import FilterLink from './FilterLink.svelte';
  import CardThumb from './CardThumb.svelte';
  import '../styles/card.css';

  /**
   * One person card:
   *
   *   ┌──────────────────────────────────────────────────┐
   *   │ (◯)  Vierke, Ulf                                    │
   *   │      University of Bayreuth                          │
   *   │      [Principal investigator] [Author]              │  ← roles, click to filter
   *   │      3 research items · 2 publications               │  ← association counts
   *   └──────────────────────────────────────────────────┘
   *
   * Name links to the person's Omeka page; each affiliation (in the byline) and
   * each role chip adds that value as a facet filter (onAddFilter). Affiliations
   * used to be listed twice when roles existed, and not be clickable at all
   * when they did not.
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
    onAddFilter: (field: string, value: string) => void;
  }

  const { doc, itemUrlBase, onAddFilter }: Props = $props();
  const titleTag = cardTitleTag();

  const url = $derived(`${itemUrlBase}/${encodeURIComponent(doc.id)}`);
  const name = $derived(doc.title || t('untitled'));
  const nameHl = $derived(doc._highlights?.title?.[0] ?? null);
  const affiliations = $derived(doc.affiliation_ss ?? []);
  const roles = $derived(doc.roles_ss ?? []);
  const affilHl = $derived(markedLookup(doc, 'affiliation_ss'));
  const roleHl = $derived(markedLookup(doc, 'roles_ss'));

  // Association counts — show only the non-zero ones, joined with "·".
  const counts = $derived.by(() => {
    const out: string[] = [];
    if ((doc.item_count ?? 0) > 0) {
      out.push(researchItemsLabel(doc.item_count ?? 0));
    }
    if ((doc.publication_count ?? 0) > 0) {
      out.push(publicationsLabel(doc.publication_count ?? 0));
    }
    return out;
  });
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
            onclick={() => onAddFilter('affiliation_ss', aff)}
            ><Highlight value={affilHl.get(aff) ?? aff} /></FilterLink
          >{/each}
      </p>
    {/if}

    {#if roles.length > 0}
      <ul class="dre-shell__chips">
        {#each roles as role (role)}
          <li>
            <button
              type="button"
              class="dre-shell__chip dre-shell__chip--role"
              onclick={() => onAddFilter('roles_ss', role)}
            >
              <Highlight value={roleHl.get(role) ?? role} />
            </button>
          </li>
        {/each}
      </ul>
    {/if}

    {#if counts.length > 0}
      <p class="dre-shell__counts dre-person__counts">{counts.join(' · ')}</p>
    {/if}
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
