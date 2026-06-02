<script lang="ts">
  import type { Doc } from '../lib/types';
  import { t, researchItemsLabel, projectsLabel, peopleLabel } from '../lib/i18n';

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

  const url = $derived(`${itemUrlBase}/${encodeURIComponent(doc.id)}`);
  const name = $derived(doc.title || t('untitled'));
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

<article class="dre-org" class:dre-org--no-thumb={!doc.thumbnail_url}>
  {#if doc.thumbnail_url}
    <a class="dre-org__avatar" href={url} tabindex="-1" aria-hidden="true">
      <img src={doc.thumbnail_url} alt="" loading="lazy" />
    </a>
  {/if}

  <div class="dre-org__body">
    <div class="dre-org__head">
      <h3 class="dre-org__name">
        <a href={url}>{name}</a>
      </h3>
      {#if type}
        <button type="button" class="dre-org__type" onclick={() => onAddFilter('type_s', type)}>
          {type}
        </button>
      {/if}
    </div>

    {#if roles.length > 0}
      <ul class="dre-org__chips">
        {#each roles as role (role)}
          <li>
            <button
              type="button"
              class="dre-org__chip dre-org__chip--role"
              onclick={() => onAddFilter('roles_ss', role)}
            >
              {role}
            </button>
          </li>
        {/each}
      </ul>
    {/if}

    {#if counts.length > 0}
      <p class="dre-org__counts">{counts.join(' · ')}</p>
    {/if}
  </div>
</article>

<style>
  .dre-org {
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
  .dre-org:hover {
    border-color: color-mix(in srgb, var(--primary, #2a4d8f) 40%, var(--border, #ccc));
    box-shadow: var(--shadow-md, 0 4px 12px rgba(0, 0, 0, 0.08));
  }
  .dre-org--no-thumb {
    grid-template-columns: 1fr;
  }

  .dre-org__avatar {
    display: block;
    width: 3.25rem;
    height: 3.25rem;
    border-radius: var(--radius-md, 0.5rem);
    overflow: hidden;
    background: var(--surface-sunken, #f5f5f5);
    border: 1px solid var(--border-light, #eee);
  }
  .dre-org__avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .dre-org__body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-org__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
  }
  .dre-org__name {
    margin: 0;
    font-size: var(--text-lg, 1.125rem);
    line-height: 1.3;
    font-family: var(--font-display, Georgia, serif);
    color: var(--ink-strong, var(--ink, #222));
    min-width: 0;
  }
  .dre-org__name a {
    color: inherit;
    text-decoration: none;
  }
  .dre-org__name a:hover {
    color: var(--primary, #2a4d8f);
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .dre-org__type {
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
  .dre-org__type:hover {
    background: color-mix(in srgb, var(--accent, #d57912) 30%, var(--surface, #fff));
  }
  .dre-org__type:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(42, 77, 143, 0.3));
  }
  .dre-org__chips {
    list-style: none;
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-org__chip {
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
  .dre-org__chip:hover {
    background: color-mix(in srgb, var(--primary, #2a4d8f) 18%, var(--surface, #fff));
    color: var(--ink-strong, var(--ink, #222));
  }
  .dre-org__chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(42, 77, 143, 0.3));
  }
  .dre-org__chip--role {
    background: color-mix(in srgb, var(--primary, #2a4d8f) 14%, var(--surface, #fff));
    color: var(--ink-strong, var(--ink, #222));
    font-weight: 600;
  }
  .dre-org__chip--role:hover {
    background: color-mix(in srgb, var(--primary, #2a4d8f) 28%, var(--surface, #fff));
  }
  .dre-org__counts {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-xs, 0.78rem);
    color: var(--muted, #666);
    font-variant-numeric: tabular-nums;
  }

  @media (max-width: 32rem) {
    .dre-org {
      grid-template-columns: 1fr;
      gap: var(--space-sm, 0.5rem);
    }
  }
</style>
