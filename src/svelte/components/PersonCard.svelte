<script lang="ts">
  import type { Doc } from '../lib/types';
  import { t, researchItemsLabel, publicationsLabel } from '../lib/i18n';

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
   * Name links to the person's Omeka page; role and affiliation chips are buttons
   * that add that value as a facet filter (onAddFilter).
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
    onAddFilter: (field: string, value: string) => void;
  }

  const { doc, itemUrlBase, onAddFilter }: Props = $props();

  const url = $derived(`${itemUrlBase}/${encodeURIComponent(doc.id)}`);
  const name = $derived(doc.title || t('untitled'));
  const affiliations = $derived(doc.affiliation_ss ?? []);
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
</script>

<article class="dre-person" class:dre-person--no-thumb={!doc.thumbnail_url}>
  {#if doc.thumbnail_url}
    <a class="dre-person__avatar" href={url} tabindex="-1" aria-hidden="true">
      <img src={doc.thumbnail_url} alt="" loading="lazy" />
    </a>
  {/if}

  <div class="dre-person__body">
    <h3 class="dre-person__name">
      <a href={url}>{name}</a>
    </h3>

    {#if affiliations.length > 0}
      <p class="dre-person__affil">{affiliations.join('; ')}</p>
    {/if}

    {#if roles.length > 0}
      <ul class="dre-person__chips">
        {#each roles as role (role)}
          <li>
            <button
              type="button"
              class="dre-person__chip dre-person__chip--role"
              onclick={() => onAddFilter('roles_ss', role)}
            >
              {role}
            </button>
          </li>
        {/each}
        {#each affiliations as aff (aff)}
          <li>
            <button
              type="button"
              class="dre-person__chip"
              onclick={() => onAddFilter('affiliation_ss', aff)}
            >
              {aff}
            </button>
          </li>
        {/each}
      </ul>
    {/if}

    {#if counts.length > 0}
      <p class="dre-person__counts">{counts.join(' · ')}</p>
    {/if}
  </div>
</article>

<style>
  .dre-person {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--space-md, 1rem);
    align-items: start;
    padding: var(--space-md, 1rem);
    background: var(--surface, #fff);
    border: 1px solid var(--border-light, #eee);
    border-radius: var(--radius-lg, 0.75rem);
    box-shadow: var(--shadow-xs, 0 1px 2px rgba(0, 0, 0, 0.04));
    transition:
      border-color var(--transition-base, 200ms ease),
      box-shadow var(--transition-base, 200ms ease);
  }
  .dre-person:hover {
    border-color: color-mix(in srgb, var(--primary, #2a4d8f) 40%, var(--border, #ccc));
    box-shadow: var(--shadow-md, 0 4px 12px rgba(0, 0, 0, 0.08));
  }
  .dre-person--no-thumb {
    grid-template-columns: 1fr;
  }

  .dre-person__avatar {
    display: block;
    width: 3.25rem;
    height: 3.25rem;
    border-radius: 50%;
    overflow: hidden;
    background: var(--surface-sunken, #f5f5f5);
    border: 1px solid var(--border-light, #eee);
  }
  .dre-person__avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .dre-person__body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-person__name {
    margin: 0;
    font-size: var(--text-lg, 1.125rem);
    line-height: 1.3;
    font-family: var(--font-display, Georgia, serif);
    color: var(--ink-strong, var(--ink, #222));
  }
  .dre-person__name a {
    color: inherit;
    text-decoration: none;
  }
  .dre-person__name a:hover {
    color: var(--primary, #2a4d8f);
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .dre-person__affil {
    margin: 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #444));
  }
  .dre-person__chips {
    list-style: none;
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-person__chip {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: var(--surface-sunken, #f5f5f5);
    color: var(--ink-light, var(--ink, #444));
    border: none;
    border-radius: var(--radius-sm, 0.375rem);
    font-family: inherit;
    font-size: var(--text-xs, 0.75rem);
    font-weight: 500;
    line-height: 1.5;
    cursor: pointer;
    transition:
      background var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease);
  }
  .dre-person__chip:hover {
    background: color-mix(in srgb, var(--primary, #2a4d8f) 18%, var(--surface, #fff));
    color: var(--ink-strong, var(--ink, #222));
  }
  .dre-person__chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(42, 77, 143, 0.3));
  }
  .dre-person__chip--role {
    background: color-mix(in srgb, var(--primary, #2a4d8f) 14%, var(--surface, #fff));
    color: var(--ink-strong, var(--ink, #222));
    font-weight: 600;
  }
  .dre-person__chip--role:hover {
    background: color-mix(in srgb, var(--primary, #2a4d8f) 28%, var(--surface, #fff));
  }
  .dre-person__counts {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-xs, 0.78rem);
    color: var(--muted, #666);
    font-variant-numeric: tabular-nums;
  }

  @media (max-width: 32rem) {
    .dre-person {
      grid-template-columns: 1fr;
      gap: var(--space-sm, 0.5rem);
    }
  }
</style>
