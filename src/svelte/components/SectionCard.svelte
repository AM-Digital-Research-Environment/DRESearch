<script lang="ts">
  import type { Doc } from '../lib/types';
  import { t, projectsLabel, membersLabel } from '../lib/i18n';

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
  const phase = $derived(doc.phase_s ?? '');
  const projectCount = $derived(doc.project_count ?? 0);
  const memberCount = $derived(doc.member_count ?? 0);
  const abstract = $derived((doc.abstract ?? '').trim());

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
      <a href={url}>{title}</a>
    </h3>

    {#if leaders}
      <p class="dre-scard__leaders">
        <span class="dre-scard__leaders-label">{leaders.label}</span>
        {#each leaders.names as nm, i (nm + '|' + i)}{i > 0 ? ', ' : ''}<button
            type="button"
            class="dre-scard__person"
            onclick={() => onAddFilter('people_ss', nm)}>{nm}</button
          >{/each}
      </p>
    {/if}

    {#if memberCount > 0}
      <p class="dre-scard__members">{membersLabel(memberCount)}</p>
    {/if}

    {#if abstract}
      <p class="dre-scard__snippet">{abstract}</p>
    {/if}
  </div>
</article>

<style>
  .dre-scard {
    padding: var(--space-md, 1rem);
    background: var(--surface, #fff);
    border: 1px solid var(--border-light, #eee);
    border-radius: var(--radius-lg, 0.75rem);
    box-shadow: var(--shadow-xs, 0 1px 2px rgba(0, 0, 0, 0.04));
    transition:
      border-color var(--transition-base, 200ms ease),
      box-shadow var(--transition-base, 200ms ease);
  }
  .dre-scard:hover {
    border-color: color-mix(in srgb, var(--primary, #2a4d8f) 40%, var(--border, #ccc));
    box-shadow: var(--shadow-md, 0 4px 12px rgba(0, 0, 0, 0.08));
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
    background: color-mix(in srgb, var(--primary, #2a4d8f) 14%, var(--surface, #fff));
    color: var(--ink-strong, var(--ink, #222));
    border: none;
    border-radius: var(--radius-full, 9999px);
    font: inherit;
    font-size: var(--text-xs, 0.7rem);
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
    cursor: pointer;
    transition: background var(--transition-fast, 150ms ease);
  }
  .dre-scard__phase:hover {
    background: color-mix(in srgb, var(--primary, #2a4d8f) 28%, var(--surface, #fff));
    /* Suppress the host primary-button hover lift + green glow (chips are flat). */
    box-shadow: none !important;
    transform: none !important;
  }
  .dre-scard__phase:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(42, 77, 143, 0.3));
  }
  .dre-scard__count {
    color: var(--muted, #666);
    font-size: var(--text-xs, 0.75rem);
    font-weight: 600;
    letter-spacing: 0.04em;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }
  .dre-scard__title {
    margin: 0;
    font-size: var(--text-lg, 1.125rem);
    line-height: 1.35;
    font-family: var(--font-display, Georgia, serif);
    color: var(--ink-strong, var(--ink, #222));
  }
  .dre-scard__title a {
    color: inherit;
    text-decoration: none;
  }
  .dre-scard__title a:hover {
    color: var(--primary, #2a4d8f);
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .dre-scard__leaders {
    margin: 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #444));
  }
  .dre-scard__leaders-label {
    font-weight: 700;
    font-size: var(--text-xs, 0.72rem);
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted, #666);
    margin-inline-end: 0.3rem;
  }
  .dre-scard__person {
    padding: 0;
    border: none;
    /* Host theme styles every <button> as a filled primary button; without
       these the leader name turns into a green pill on hover. !important beats
       the host's higher-specificity :hover/:active states in one place. */
    background: none !important;
    box-shadow: none !important;
    transform: none !important;
    font: inherit;
    cursor: pointer;
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
    text-decoration-color: color-mix(in srgb, currentColor 35%, transparent);
  }
  .dre-scard__person:hover {
    color: var(--primary, #2a4d8f) !important;
    text-decoration-color: currentColor;
  }
  .dre-scard__person:focus-visible {
    outline: none;
    border-radius: var(--radius-sm, 0.375rem);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(42, 77, 143, 0.3)) !important;
  }
  .dre-scard__members {
    margin: 0;
    font-size: var(--text-xs, 0.78rem);
    color: var(--muted, #666);
    font-variant-numeric: tabular-nums;
  }
  .dre-scard__snippet {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #444));
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
</style>
