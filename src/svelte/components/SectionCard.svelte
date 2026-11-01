<script lang="ts">
  import type { Doc } from '../lib/types';
  import { cardTitleTag } from '../lib/headings';
  import { projectsLabel, membersLabel } from '../lib/i18n';
  import { markedLookup } from '../lib/highlight';
  import {
    cardSnippet,
    cardTitle,
    recordUrl,
    sectionLeaders,
    titleMarkup,
  } from '../lib/resultCard';
  import FilterLink from './FilterLink.svelte';
  import Highlight from './Highlight.svelte';
  import FilterButton from './FilterButton.svelte';
  import MatchedIn from './MatchedIn.svelte';
  import '../styles/card.css';

  /**
   * One research-section card:
   *
   *   ┌────────────────────────────────────────────────┐
   *   │ [Phase 1]                              17 projects │
   *   │ Arts & Aesthetics                                  │
   *   │ PIs  Fendler, Ute, Ritzer, Ivo, …                  │  ← or "Spokesperson"
   *   │ 26 members                                          │
   *   │ Abstract, clamped to a few lines…                  │
   *   └────────────────────────────────────────────────┘
   *
   * The phase chip and each leader (PI / spokesperson) are buttons that add a
   * filter — phase adds the Phase facet, a leader adds them as an Associated
   * person (people_ss). Name links to the section's Omeka page.
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
  const phase = $derived(doc.phase_s ?? '');
  const projectCount = $derived(doc.project_count ?? 0);
  const memberCount = $derived(doc.member_count ?? 0);
  // Abstract: the matched window when it matched, else the plain abstract.
  const snippet = $derived(cardSnippet(doc, ['abstract']));
  // Leaders come from either pi_ss or spokesperson_ss — merge both highlight maps.
  const leaderHl = $derived(
    new Map([...markedLookup(doc, 'pi_ss'), ...markedLookup(doc, 'spokesperson_ss')]),
  );

  // Leaders — PIs for a Phase 1 section, a spokesperson for Phase 2 (the two are
  // mutually exclusive in the source data; External has neither).
  const leaders = $derived(sectionLeaders(doc));
</script>

<article class="dre-shell dre-scard">
  <div class="dre-shell__body">
    <header class="dre-shell__head">
      {#if phase}
        <FilterButton field="phase_s" value={phase} class="dre-scard__phase">
          {phase}
        </FilterButton>
      {/if}
      {#if projectCount > 0}
        <span class="dre-scard__count">{projectsLabel(projectCount)}</span>
      {/if}
    </header>

    <svelte:element this={titleTag} class="dre-shell__title">
      <a href={url}><Highlight value={titleHl ?? title} /></a>
    </svelte:element>

    {#if leaders}
      <p class="dre-shell__line">
        <span class="dre-scard__leaders-label">{leaders.label}</span>
        {#each leaders.names as nm, i (nm + '|' + i)}{i > 0 ? ', ' : ''}<FilterLink
            field="people_ss"
            value={nm}><Highlight value={leaderHl.get(nm) ?? nm} /></FilterLink
          >{/each}
      </p>
    {/if}

    {#if memberCount > 0}
      <p class="dre-shell__counts">{membersLabel(memberCount)}</p>
    {/if}

    {#if snippet}
      <p class="dre-shell__snippet"><Highlight value={snippet} /></p>
    {/if}

    <MatchedIn {doc} exclude={['title', 'abstract', 'pi_ss', 'spokesperson_ss']} />
  </div>
</article>

<style>
  /* The shell, title, byline, counts and snippet are styles/card.css. */
  /* The phase chip is FilterButton's <button>, so its rules reach into that
     component (:global), kept to this card by the .dre-scard ancestor. */
  .dre-scard :global(.dre-scard__phase) {
    display: inline-flex;
    align-items: center;
    min-height: 1.5rem;
    padding: 0 var(--space-2, 0.5rem);
    background: color-mix(in srgb, var(--primary, #007a50) 14%, var(--surface, #fdfcf9));
    color: var(--ink-strong, #261d15);
    border: none;
    border-radius: var(--radius-full, 9999px);
    font: inherit;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: var(--tracking-wide, 0.04em);
    text-transform: uppercase;
    white-space: nowrap;
    cursor: pointer;
    transition: background var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-scard :global(.dre-scard__phase:hover) {
    background: color-mix(in srgb, var(--primary, #007a50) 28%, var(--surface, #fdfcf9));
  }
  .dre-scard :global(.dre-scard__phase[aria-pressed='true']) {
    background: color-mix(in srgb, var(--primary, #007a50) 24%, var(--surface, #fdfcf9));
    box-shadow: inset 0 0 0 2px var(--primary, #007a50);
  }
  .dre-scard :global(.dre-scard__phase:focus-visible) {
    outline: 2px solid var(--focus-color, #007a50);
    outline-offset: 2px;
  }
  .dre-scard__count {
    color: var(--muted, #716a66);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: var(--tracking-wide, 0.04em);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }
  .dre-scard__leaders-label {
    font-weight: 700;
    font-size: var(--text-xs, 0.8125rem);
    letter-spacing: var(--tracking-wide, 0.04em);
    text-transform: uppercase;
    color: var(--muted, #716a66);
    margin-inline-end: 0.3rem;
  }
  /* The leader names are FilterLink spans — see that component for the styling. */
</style>
