<script lang="ts">
  import type { Doc } from '../lib/types';
  import { cardTitleTag } from '../lib/headings';
  import { t, researchItemsLabel, publicationsLabel } from '../lib/i18n';
  import Highlight from './Highlight.svelte';
  import Sparkline from './Sparkline.svelte';
  import '../styles/card.css';
  import { associationSeries } from '../lib/sparkline';
  import { entityTagClass } from '../lib/entity';

  /**
   * One authority-term card — a genre, language, location, or subject/tag:
   *
   *   ┌──────────────────────────────────────────────────┐
   *   │ Lagos                                       Country │  ← type chip (if any), click to filter
   *   │ [Place of origin] [Current location]                │  ← relationship chips (locations), click to filter
   *   │ 142 research items · 8 publications                  │  ← association counts
   *   └──────────────────────────────────────────────────┘
   *
   * Name links to the term's Omeka page; the type chip (present only for corpora
   * with a sub-type, e.g. locations and subjects) and the relationship chips
   * (locations: how the place is referenced) are buttons that add that value as a
   * facet filter (onAddFilter). Genres and languages have neither.
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
    onAddFilter: (field: string, value: string) => void;
    /** The corpus, which names the entity hue of the type tag. */
    profile?: string;
  }

  const { doc, itemUrlBase, onAddFilter, profile }: Props = $props();
  const titleTag = cardTitleTag();

  const url = $derived(`${itemUrlBase}/${encodeURIComponent(doc.id)}`);
  const name = $derived(doc.title || t('untitled'));
  const nameHl = $derived(doc._highlights?.title?.[0] ?? null);
  const type = $derived((doc.type_s ?? '').trim());
  const roles = $derived(doc.roles_ss ?? []);

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
  const series = $derived(associationSeries(doc.item_count, doc.publication_count));
</script>

<article class="dre-shell dre-term">
  <div class="dre-term__head">
    <svelte:element this={titleTag} class="dre-shell__title dre-term__name">
      <a href={url}><Highlight value={nameHl ?? name} /></a>
    </svelte:element>
    {#if type}
      <button
        type="button"
        data-print
        class="dre-shell__tag {entityTagClass(profile)}"
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
            data-print
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
    <div class="dre-term__association">
      <Sparkline values={series} label={t('association_counts', { values: counts.join(', ') })} />
      <p class="dre-shell__counts">{counts.join(' · ')}</p>
    </div>
  {/if}
</article>

<style>
  /* The shell, name, tag, chips and counts are styles/card.css. */
  .dre-term {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-term__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
  }
  .dre-term__name {
    min-width: 0;
  }
  .dre-term__association {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    min-height: 0.875rem;
  }
</style>
