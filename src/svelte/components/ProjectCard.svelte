<script lang="ts">
  import type { Doc } from '../lib/types';
  import { t, researchItemsLabel } from '../lib/i18n';
  import { firstMarked, markedLookup } from '../lib/highlight';
  import FilterLink from './FilterLink.svelte';
  import Highlight from './Highlight.svelte';
  import MatchedIn from './MatchedIn.svelte';

  /**
   * One research-project card:
   *
   *   ┌────────────────────────────────────────────────┐
   *   │ 2020 – 2023                     182 research items│
   *   │ Project title                                    │
   *   │ PI  Vierke, Ulf  (links to the person's page)    │
   *   │ Short abstract…                                  │
   *   │ [Arts & Aesthetics] [University of Bayreuth]      │  ← click to filter
   *   └────────────────────────────────────────────────┘
   *
   * PI names link to the person's Omeka page; the section / institution chips
   * are buttons that add that value as a facet filter (onAddFilter).
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
    onAddFilter: (field: string, value: string) => void;
  }

  const { doc, itemUrlBase, onAddFilter }: Props = $props();

  const url = $derived(`${itemUrlBase}/${encodeURIComponent(doc.id)}`);
  const title = $derived(doc.title || t('untitled'));
  const titleHl = $derived(doc._highlights?.title?.[0] ?? null);
  const sections = $derived(doc.section_ss ?? []);
  const institutions = $derived(doc.institution_ss ?? []);
  const sectionHl = $derived(markedLookup(doc, 'section_ss'));
  const instHl = $derived(markedLookup(doc, 'institution_ss'));
  // Abstract: the matched window when it matched, else the plain abstract.
  const snippet = $derived(firstMarked(doc, ['abstract']) ?? (doc.abstract ?? '').trim());
  const itemCount = $derived(doc.item_count ?? 0);

  // PI names. Clicking one adds it as an "Associated people" (people_ss) filter,
  // so you can pivot to every project that person is involved in.
  const pis = $derived(doc.pi_ss ?? []);
  const piHl = $derived(markedLookup(doc, 'pi_ss'));

  const yearRange = $derived.by(() => {
    const s = doc.year_start;
    const e = doc.year_end;
    if (s == null) {
      return '';
    }
    return e != null && e !== s ? `${s} – ${e}` : String(s);
  });
</script>

<article class="dre-pcard">
  <div class="dre-pcard__body">
    <header class="dre-pcard__head">
      {#if yearRange}
        <span class="dre-pcard__years">{yearRange}</span>
      {/if}
      {#if itemCount > 0}
        <span class="dre-pcard__count">{researchItemsLabel(itemCount)}</span>
      {/if}
    </header>

    <h3 class="dre-pcard__title">
      <a href={url}><Highlight value={titleHl ?? title} /></a>
    </h3>

    {#if pis.length > 0}
      <p class="dre-pcard__pi">
        <span class="dre-pcard__pi-label">{t('pi_label')}</span>
        {#each pis as pi, i (pi + '|' + i)}{i > 0 ? ', ' : ''}<FilterLink
            onclick={() => onAddFilter('people_ss', pi)}
            ><Highlight value={piHl.get(pi) ?? pi} /></FilterLink
          >{/each}
      </p>
    {/if}

    {#if snippet}
      <p class="dre-pcard__snippet"><Highlight value={snippet} /></p>
    {/if}

    {#if sections.length > 0 || institutions.length > 0}
      <ul class="dre-pcard__chips">
        {#each sections as s (s)}
          <li>
            <button
              type="button"
              class="dre-pcard__chip dre-pcard__chip--section"
              onclick={() => onAddFilter('section_ss', s)}
            >
              <Highlight value={sectionHl.get(s) ?? s} />
            </button>
          </li>
        {/each}
        {#each institutions as inst (inst)}
          <li>
            <button
              type="button"
              class="dre-pcard__chip"
              onclick={() => onAddFilter('institution_ss', inst)}
            >
              <Highlight value={instHl.get(inst) ?? inst} />
            </button>
          </li>
        {/each}
      </ul>
    {/if}

    <MatchedIn {doc} exclude={['title', 'abstract', 'pi_ss', 'section_ss', 'institution_ss']} />
  </div>
</article>

<style>
  .dre-pcard {
    padding: var(--space-md, 1rem);
    background: var(--surface, #fdfcf9);
    border: 1px solid var(--border-light, #eae8e3);
    border-radius: var(--radius-lg, 0.75rem);
    box-shadow: var(--shadow-xs, 0 1px 2px 0 rgba(52, 37, 26, 0.07));
    transition:
      border-color var(--transition-base, 200ms cubic-bezier(0.25, 1, 0.5, 1)),
      box-shadow var(--transition-base, 200ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-pcard:hover {
    border-color: color-mix(in srgb, var(--primary, #007a50) 40%, var(--border, #dbd7d1));
    box-shadow: var(
      --shadow-md,
      0 4px 6px -1px rgba(42, 28, 16, 0.14),
      0 2px 4px -2px rgba(52, 37, 26, 0.07)
    );
  }

  .dre-pcard__body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-pcard__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    min-height: 1.1rem;
  }
  .dre-pcard__years {
    color: var(--muted, #716a66);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    font-variant-numeric: tabular-nums;
  }
  .dre-pcard__count {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: color-mix(in srgb, var(--primary, #007a50) 12%, var(--surface, #fdfcf9));
    color: var(--ink-strong, #261d15);
    border-radius: var(--radius-full, 9999px);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
  }
  .dre-pcard__title {
    margin: 0;
    font-size: var(--text-lg, 1.1875rem);
    line-height: var(--leading-snug, 1.25);
    font-family: var(--font-display, 'Spectral', Georgia, 'Times New Roman', serif);
    color: var(--ink-strong, #261d15);
  }
  .dre-pcard__title a {
    color: inherit;
    text-decoration: none;
  }
  .dre-pcard__title a:hover {
    color: var(--primary, #007a50);
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .dre-pcard__pi {
    margin: 0;
    font-size: var(--text-sm, 0.9375rem);
    color: var(--ink-light, #5f5650);
  }
  .dre-pcard__pi-label {
    font-weight: 700;
    font-size: var(--text-xs, 0.8125rem);
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted, #716a66);
    margin-inline-end: 0.15rem;
  }
  /* The PI names are FilterLink spans — see that component for the styling. */
  .dre-pcard__snippet {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-sm, 0.9375rem);
    color: var(--ink-light, #5f5650);
    line-height: var(--leading-normal, 1.6);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .dre-pcard__chips {
    list-style: none;
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-pcard__chip {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: var(--surface-sunken, #f3f0eb);
    color: var(--ink-light, #5f5650);
    border: none;
    border-radius: var(--radius-sm, 0.375rem);
    font-family: inherit;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 500;
    line-height: var(--leading-normal, 1.6);
    cursor: pointer;
    transition:
      background var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-pcard__chip:hover {
    background: color-mix(in srgb, var(--primary, #007a50) 18%, var(--surface, #fdfcf9));
    color: var(--ink-strong, #261d15);
  }
  .dre-pcard__chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }
  .dre-pcard__chip--section {
    background: color-mix(in srgb, var(--accent, #ca7210) 16%, var(--surface, #fdfcf9));
    color: var(--ink-strong, #261d15);
    font-weight: 600;
  }
  .dre-pcard__chip--section:hover {
    background: color-mix(in srgb, var(--accent, #ca7210) 30%, var(--surface, #fdfcf9));
  }
</style>
