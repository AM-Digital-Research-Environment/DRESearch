<script lang="ts">
  import type { Doc } from '../lib/types';
  import { t, researchItemsLabel, publicationsLabel } from '../lib/i18n';
  import Highlight from './Highlight.svelte';
  import Sparkline from './Sparkline.svelte';
  import { associationSeries } from '../lib/sparkline';

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
  }

  const { doc, itemUrlBase, onAddFilter }: Props = $props();

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

<article class="dre-term">
  <div class="dre-term__head">
    <h3 class="dre-term__name">
      <a href={url}><Highlight value={nameHl ?? name} /></a>
    </h3>
    {#if type}
      <button type="button" class="dre-term__type" onclick={() => onAddFilter('type_s', type)}>
        {type}
      </button>
    {/if}
  </div>

  {#if roles.length > 0}
    <ul class="dre-term__chips">
      {#each roles as role (role)}
        <li>
          <button
            type="button"
            class="dre-term__chip"
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
      <p class="dre-term__counts">{counts.join(' · ')}</p>
    </div>
  {/if}
</article>

<style>
  .dre-term {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
    padding: var(--space-md, 1rem);
    background: var(--surface, #fdfcf9);
    border: 1px solid var(--border-light, #eae8e3);
    border-radius: var(--radius-lg, 0.75rem);
    box-shadow: var(--shadow-xs, 0 1px 2px 0 rgba(52, 37, 26, 0.07));
    transition:
      border-color var(--transition-base, 200ms cubic-bezier(0.25, 1, 0.5, 1)),
      box-shadow var(--transition-base, 200ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-term:hover {
    border-color: color-mix(in srgb, var(--primary, #007a50) 40%, var(--border, #dbd7d1));
    box-shadow: var(
      --shadow-md,
      0 4px 6px -1px rgba(42, 28, 16, 0.14),
      0 2px 4px -2px rgba(52, 37, 26, 0.07)
    );
  }

  .dre-term__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
  }
  .dre-term__name {
    margin: 0;
    font-size: var(--text-lg, 1.1875rem);
    line-height: var(--leading-snug, 1.25);
    font-family: var(--font-display, 'Spectral', Georgia, 'Times New Roman', serif);
    color: var(--ink-strong, #261d15);
    min-width: 0;
  }
  .dre-term__name a {
    color: inherit;
    text-decoration: none;
  }
  .dre-term__name a:hover {
    color: var(--primary, #007a50);
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .dre-term__type {
    flex: none;
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: color-mix(in srgb, var(--accent, #ca7210) 16%, var(--surface, #fdfcf9));
    color: var(--ink-strong, #261d15);
    border: none;
    border-radius: var(--radius-full, 9999px);
    font-family: inherit;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    line-height: var(--leading-normal, 1.6);
    white-space: nowrap;
    cursor: pointer;
    transition: background var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-term__type:hover {
    background: color-mix(in srgb, var(--accent, #ca7210) 30%, var(--surface, #fdfcf9));
  }
  .dre-term__type:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }
  .dre-term__chips {
    list-style: none;
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-term__chip {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: color-mix(in srgb, var(--primary, #007a50) 14%, var(--surface, #fdfcf9));
    color: var(--ink-strong, #261d15);
    border: none;
    border-radius: var(--radius-sm, 0.375rem);
    font-family: inherit;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    line-height: var(--leading-normal, 1.6);
    cursor: pointer;
    transition: background var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-term__chip:hover {
    background: color-mix(in srgb, var(--primary, #007a50) 28%, var(--surface, #fdfcf9));
  }
  .dre-term__chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }
  .dre-term__counts {
    margin: 0;
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #716a66);
    font-variant-numeric: tabular-nums;
  }
  .dre-term__association {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    min-height: 0.875rem;
  }
</style>
