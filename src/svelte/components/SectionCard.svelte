<script lang="ts">
  import type { Doc } from '../lib/types';
  import { t, projectsLabel, membersLabel } from '../lib/i18n';
  import { firstMarked, markedLookup } from '../lib/highlight';
  import FilterLink from './FilterLink.svelte';
  import Highlight from './Highlight.svelte';
  import MatchedIn from './MatchedIn.svelte';

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
    onAddFilter: (field: string, value: string) => void;
  }

  const { doc, itemUrlBase, onAddFilter }: Props = $props();

  const url = $derived(`${itemUrlBase}/${encodeURIComponent(doc.id)}`);
  const title = $derived(doc.title || t('untitled'));
  const titleHl = $derived(doc._highlights?.title?.[0] ?? null);
  const phase = $derived(doc.phase_s ?? '');
  const projectCount = $derived(doc.project_count ?? 0);
  const memberCount = $derived(doc.member_count ?? 0);
  // Abstract: the matched window when it matched, else the plain abstract.
  const snippet = $derived(firstMarked(doc, ['abstract']) ?? (doc.abstract ?? '').trim());
  // Leaders come from either pi_ss or spokesperson_ss — merge both highlight maps.
  const leaderHl = $derived(
    new Map([...markedLookup(doc, 'pi_ss'), ...markedLookup(doc, 'spokesperson_ss')]),
  );

  // Leaders — PIs for a Phase 1 section, a spokesperson for Phase 2 (the two are
  // mutually exclusive in the source data; External has neither).
  const leaders = $derived.by(() => {
    const pis = doc.pi_ss ?? [];
    if (pis.length > 0) {
      return { label: t('pis_label'), names: pis };
    }
    const spk = doc.spokesperson_ss ?? [];
    if (spk.length > 0) {
      return { label: t('spokesperson_label'), names: spk };
    }
    return null;
  });
</script>

<article class="dre-scard">
  <div class="dre-scard__body">
    <header class="dre-scard__head">
      {#if phase}
        <button
          type="button"
          class="dre-scard__phase"
          onclick={() => onAddFilter('phase_s', phase)}
        >
          {phase}
        </button>
      {/if}
      {#if projectCount > 0}
        <span class="dre-scard__count">{projectsLabel(projectCount)}</span>
      {/if}
    </header>

    <h3 class="dre-scard__title">
      <a href={url}><Highlight value={titleHl ?? title} /></a>
    </h3>

    {#if leaders}
      <p class="dre-scard__leaders">
        <span class="dre-scard__leaders-label">{leaders.label}</span>
        {#each leaders.names as nm, i (nm + '|' + i)}{i > 0 ? ', ' : ''}<FilterLink
            onclick={() => onAddFilter('people_ss', nm)}
            ><Highlight value={leaderHl.get(nm) ?? nm} /></FilterLink
          >{/each}
      </p>
    {/if}

    {#if memberCount > 0}
      <p class="dre-scard__members">{membersLabel(memberCount)}</p>
    {/if}

    {#if snippet}
      <p class="dre-scard__snippet"><Highlight value={snippet} /></p>
    {/if}

    <MatchedIn {doc} exclude={['title', 'abstract', 'pi_ss', 'spokesperson_ss']} />
  </div>
</article>

<style>
  .dre-scard {
    padding: var(--space-md, 1rem);
    background: var(--surface, #fdfcf9);
    border: 1px solid var(--border-light, #eae8e3);
    border-radius: var(--radius-lg, 0.75rem);
    box-shadow: var(--shadow-xs, 0 1px 2px 0 rgba(52, 37, 26, 0.07));
    transition:
      border-color var(--transition-base, 200ms cubic-bezier(0.25, 1, 0.5, 1)),
      box-shadow var(--transition-base, 200ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-scard:hover {
    border-color: color-mix(in srgb, var(--primary, #007a50) 40%, var(--border, #dbd7d1));
    box-shadow: var(
      --shadow-md,
      0 4px 6px -1px rgba(42, 28, 16, 0.14),
      0 2px 4px -2px rgba(52, 37, 26, 0.07)
    );
  }

  .dre-scard__body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-scard__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    min-height: 1.1rem;
  }
  .dre-scard__phase {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.55rem;
    background: color-mix(in srgb, var(--primary, #007a50) 14%, var(--surface, #fdfcf9));
    color: var(--ink-strong, #261d15);
    border: none;
    border-radius: var(--radius-full, 9999px);
    font: inherit;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
    cursor: pointer;
    transition: background var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-scard__phase:hover {
    background: color-mix(in srgb, var(--primary, #007a50) 28%, var(--surface, #fdfcf9));
  }
  .dre-scard__phase:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }
  .dre-scard__count {
    color: var(--muted, #716a66);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: 0.04em;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }
  .dre-scard__title {
    margin: 0;
    font-size: var(--text-lg, 1.1875rem);
    line-height: var(--leading-snug, 1.25);
    font-family: var(--font-display, 'Spectral', Georgia, 'Times New Roman', serif);
    color: var(--ink-strong, #261d15);
  }
  .dre-scard__title a {
    color: inherit;
    text-decoration: none;
  }
  .dre-scard__title a:hover {
    color: var(--primary, #007a50);
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .dre-scard__leaders {
    margin: 0;
    font-size: var(--text-sm, 0.9375rem);
    color: var(--ink-light, #5f5650);
  }
  .dre-scard__leaders-label {
    font-weight: 700;
    font-size: var(--text-xs, 0.8125rem);
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted, #716a66);
    margin-inline-end: 0.3rem;
  }
  /* The leader names are FilterLink spans — see that component for the styling. */
  .dre-scard__members {
    margin: 0;
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #716a66);
    font-variant-numeric: tabular-nums;
  }
  .dre-scard__snippet {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-sm, 0.9375rem);
    color: var(--ink-light, #5f5650);
    line-height: var(--leading-normal, 1.6);
    display: -webkit-box;
    -webkit-line-clamp: 3;
    line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
</style>
