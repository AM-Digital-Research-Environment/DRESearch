<script lang="ts">
  import type { Doc } from '../lib/types';
  import { cardTitleTag } from '../lib/headings';
  import { t, researchItemsLabel, projectsLabel, peopleLabel } from '../lib/i18n';
  import Highlight from './Highlight.svelte';
  import CardThumb from './CardThumb.svelte';
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
   * buttons that add that value as a facet filter (onAddFilter).
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
  const type = $derived((doc.type_s ?? '').trim());
  const roles = $derived(doc.roles_ss ?? []);

  // Association counts — show only the non-zero ones, joined with "·". A group
  // typically shows just "N research items"; an institution shows projects/people.
  const counts = $derived.by(() => {
    const out: string[] = [];
    if ((doc.project_count ?? 0) > 0) {
      out.push(projectsLabel(doc.project_count ?? 0));
    }
    if ((doc.item_count ?? 0) > 0) {
      out.push(researchItemsLabel(doc.item_count ?? 0));
    }
    if ((doc.people_count ?? 0) > 0) {
      out.push(peopleLabel(doc.people_count ?? 0));
    }
    return out;
  });
</script>

<article class="dre-shell dre-shell--media dre-org" class:dre-shell--no-thumb={!doc.thumbnail_url}>
  <CardThumb href={url} url={doc.thumbnail_url} shape="emblem" />

  <div class="dre-shell__body">
    <div class="dre-org__head">
      <svelte:element this={titleTag} class="dre-shell__title dre-org__name">
        <a href={url}><Highlight value={nameHl ?? name} /></a>
      </svelte:element>
      {#if type}
        <button
          type="button"
          class="dre-shell__tag dre-shell__tag--organisation"
          onclick={() => onAddFilter('type_s', type)}
        >
          {type}
        </button>
      {/if}
    </div>

    {#if roles.length > 0}
      <ul class="dre-shell__chips">
        {#each roles as role (role)}
          <li>
            <button
              type="button"
              class="dre-shell__chip dre-shell__chip--role"
              onclick={() => onAddFilter('roles_ss', role)}
            >
              {role}
            </button>
          </li>
        {/each}
      </ul>
    {/if}

    {#if counts.length > 0}
      <p class="dre-shell__counts dre-org__counts">{counts.join(' · ')}</p>
    {/if}
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
