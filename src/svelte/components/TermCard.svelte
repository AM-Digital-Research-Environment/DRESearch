<script lang="ts">
  import type { Doc } from '../lib/types';
  import { t, researchItemsLabel, publicationsLabel } from '../lib/i18n';

  /**
   * One authority-term card — a genre, language, location, or subject/tag:
   *
   *   ┌──────────────────────────────────────────────────┐
   *   │ Lagos                                       Country │  ← type chip (if any), click to filter
   *   │ 142 research items · 8 publications                  │  ← association counts
   *   └──────────────────────────────────────────────────┘
   *
   * Name links to the term's Omeka page; the type chip (present only for corpora
   * with a sub-type, e.g. locations and subjects) is a button that adds that value
   * as a facet filter (onAddFilter). Genres and languages have no type chip.
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
    onAddFilter: (field: string, value: string) => void;
  }

  const { doc, itemUrlBase, onAddFilter }: Props = $props();

  const url = $derived(`${itemUrlBase}/${encodeURIComponent(doc.id)}`);
  const name = $derived(doc.title || t('untitled'));
  const type = $derived((doc.type_s ?? '').trim());

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

<article class="dre-term">
  <div class="dre-term__head">
    <h3 class="dre-term__name">
      <a href={url}>{name}</a>
    </h3>
    {#if type}
      <button type="button" class="dre-term__type" onclick={() => onAddFilter('type_s', type)}>
        {type}
      </button>
    {/if}
  </div>

  {#if counts.length > 0}
    <p class="dre-term__counts">{counts.join(' · ')}</p>
  {/if}
</article>

<style>
  .dre-term {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
    padding: var(--space-md, 1rem);
    background: var(--surface, #fff);
    border: 1px solid var(--border-light, #eee);
    border-radius: var(--radius-lg, 0.75rem);
    box-shadow: var(--shadow-xs, 0 1px 2px rgba(0, 0, 0, 0.04));
    transition:
      border-color var(--transition-base, 200ms ease),
      box-shadow var(--transition-base, 200ms ease);
  }
  .dre-term:hover {
    border-color: color-mix(in srgb, var(--primary, #2a4d8f) 40%, var(--border, #ccc));
    box-shadow: var(--shadow-md, 0 4px 12px rgba(0, 0, 0, 0.08));
  }

  .dre-term__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
  }
  .dre-term__name {
    margin: 0;
    font-size: var(--text-lg, 1.125rem);
    line-height: 1.3;
    font-family: var(--font-display, Georgia, serif);
    color: var(--ink-strong, var(--ink, #222));
    min-width: 0;
  }
  .dre-term__name a {
    color: inherit;
    text-decoration: none;
  }
  .dre-term__name a:hover {
    color: var(--primary, #2a4d8f);
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .dre-term__type {
    flex: none;
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: color-mix(in srgb, var(--accent, #d57912) 16%, var(--surface, #fff));
    color: var(--ink-strong, var(--ink, #222));
    border: none;
    border-radius: var(--radius-full, 9999px);
    font-family: inherit;
    font-size: var(--text-xs, 0.7rem);
    font-weight: 600;
    line-height: 1.5;
    white-space: nowrap;
    cursor: pointer;
    transition: background var(--transition-fast, 150ms ease);
  }
  .dre-term__type:hover {
    background: color-mix(in srgb, var(--accent, #d57912) 30%, var(--surface, #fff));
  }
  .dre-term__type:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(42, 77, 143, 0.3));
  }
  .dre-term__counts {
    margin: 0;
    font-size: var(--text-xs, 0.78rem);
    color: var(--muted, #666);
    font-variant-numeric: tabular-nums;
  }
</style>
