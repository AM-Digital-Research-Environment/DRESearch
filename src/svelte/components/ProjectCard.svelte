<script lang="ts">
  import type { Doc } from '../lib/types';
  import { cardTitleTag } from '../lib/headings';
  import { t, researchItemsLabel } from '../lib/i18n';
  import { firstMarked, markedLookup } from '../lib/highlight';
  import FilterLink from './FilterLink.svelte';
  import Highlight from './Highlight.svelte';
  import MatchedIn from './MatchedIn.svelte';
  import RecordLink from './RecordLink.svelte';
  import '../styles/card.css';

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
   * A PI name filters by that person; the arrow beside it (when the PI is a
   * linked person, `pi_ids`) opens their Omeka page. Section / institution
   * chips add that value as a facet filter (onAddFilter).
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
    onAddFilter: (field: string, value: string) => void;
  }

  const { doc, itemUrlBase, onAddFilter }: Props = $props();
  const titleTag = cardTitleTag();

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

<article class="dre-shell dre-pcard">
  <div class="dre-shell__body">
    <header class="dre-shell__head">
      {#if yearRange}
        <span class="dre-shell__eyebrow">{yearRange}</span>
      {/if}
      {#if itemCount > 0}
        <span class="dre-pcard__count">{researchItemsLabel(itemCount)}</span>
      {/if}
    </header>

    <svelte:element this={titleTag} class="dre-shell__title">
      <a href={url}><Highlight value={titleHl ?? title} /></a>
    </svelte:element>

    {#if pis.length > 0}
      <p class="dre-shell__line">
        <span class="dre-pcard__pi-label">{t('pi_label')}</span>
        {#each pis as pi, i (pi + '|' + i)}{i > 0 ? ', ' : ''}<FilterLink
            onclick={() => onAddFilter('people_ss', pi)}
            ><Highlight value={piHl.get(pi) ?? pi} /></FilterLink
          ><RecordLink {itemUrlBase} id={doc.pi_ids?.[i]} name={pi} />{/each}
      </p>
    {/if}

    {#if snippet}
      <p class="dre-shell__snippet dre-pcard__snippet"><Highlight value={snippet} /></p>
    {/if}

    {#if sections.length > 0 || institutions.length > 0}
      <ul class="dre-shell__chips">
        {#each sections as s (s)}
          <li>
            <button
              type="button"
              class="dre-shell__chip dre-shell__chip--accent"
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
              class="dre-shell__chip"
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
  /* The shell, title, byline, snippet and chips are styles/card.css. */
  .dre-pcard__count {
    display: inline-flex;
    align-items: center;
    min-height: 1.5rem;
    padding: 0 var(--space-2, 0.5rem);
    background: color-mix(in srgb, var(--primary, #007a50) 12%, var(--surface, #fdfcf9));
    color: var(--ink-strong, #261d15);
    border-radius: var(--radius-full, 9999px);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
  }
  .dre-pcard__pi-label {
    font-weight: 700;
    font-size: var(--text-xs, 0.8125rem);
    letter-spacing: var(--tracking-wide, 0.04em);
    text-transform: uppercase;
    color: var(--muted, #716a66);
    margin-inline-end: 0.15rem;
  }
  /* The PI names are FilterLink spans — see that component for the styling. */
  .dre-pcard__snippet {
    -webkit-line-clamp: 2;
    line-clamp: 2;
  }
</style>
