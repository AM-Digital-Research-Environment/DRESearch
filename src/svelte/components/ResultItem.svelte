<script lang="ts">
  import type { Doc } from '../lib/types';
  import { t } from '../lib/i18n';

  /**
   * One result card:
   *
   *   ┌──────────────────────────────────────────────┐
   *   │ ┌────┐  2021                          TEXT    │
   *   │ │img │  Title of the research item            │
   *   │ │    │  Author A, Author B                    │
   *   │ └────┘  Short abstract / description…         │
   *   │         Project · Bénin · Niger               │
   *   └──────────────────────────────────────────────┘
   */

  interface Props {
    doc: Doc;
    itemUrlBase: string;
  }

  const { doc, itemUrlBase }: Props = $props();

  const url = $derived(`${itemUrlBase}/${encodeURIComponent(doc.id)}`);
  const title = $derived(doc.title || t('untitled'));
  const byline = $derived((doc.creator_ss ?? []).join(', '));
  const abstract = $derived((doc.abstract ?? doc.description ?? '').trim());
  const countries = $derived(doc.country_ss ?? []);
</script>

<article class="dre-card" class:dre-card--no-thumb={!doc.thumbnail_url}>
  {#if doc.thumbnail_url}
    <a class="dre-card__thumb" href={url} tabindex="-1" aria-hidden="true">
      <img src={doc.thumbnail_url} alt="" loading="lazy" />
    </a>
  {/if}

  <div class="dre-card__body">
    <header class="dre-card__head">
      {#if doc.year}
        <span class="dre-card__eyebrow">{doc.year}</span>
      {/if}
      {#if doc.type_s}
        <span class="dre-card__type">{doc.type_s}</span>
      {/if}
    </header>

    <h3 class="dre-card__title">
      <a href={url}>{title}</a>
    </h3>

    {#if byline}
      <p class="dre-card__byline">{byline}</p>
    {/if}

    {#if abstract}
      <p class="dre-card__snippet">{abstract}</p>
    {/if}

    {#if doc.project_s || countries.length > 0}
      <ul class="dre-card__chips">
        {#if doc.project_s}
          <li class="dre-card__chip dre-card__chip--project">{doc.project_s}</li>
        {/if}
        {#each countries as c (c)}
          <li class="dre-card__chip">{c}</li>
        {/each}
      </ul>
    {/if}
  </div>
</article>

<style>
  .dre-card {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--space-md, 1rem);
    padding: var(--space-md, 1rem);
    background: var(--surface, #fff);
    border: 1px solid var(--border-light, #eee);
    border-radius: var(--radius-lg, 0.75rem);
    box-shadow: var(--shadow-xs, 0 1px 2px rgba(0, 0, 0, 0.04));
    transition:
      border-color var(--transition-base, 200ms ease),
      box-shadow var(--transition-base, 200ms ease);
  }
  .dre-card:hover {
    border-color: color-mix(in srgb, var(--primary, #2a4d8f) 40%, var(--border, #ccc));
    box-shadow: var(--shadow-md, 0 4px 12px rgba(0, 0, 0, 0.08));
  }
  .dre-card--no-thumb {
    grid-template-columns: 1fr;
  }

  .dre-card__thumb {
    display: block;
    width: 6rem;
    height: 6rem;
    border-radius: var(--radius-sm, 0.375rem);
    overflow: hidden;
    background: var(--surface-sunken, #f5f5f5);
    border: 1px solid var(--border-light, #eee);
  }
  .dre-card__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .dre-card__body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-card__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    min-height: 1.1rem;
  }
  .dre-card__eyebrow {
    color: var(--muted, #666);
    font-size: var(--text-xs, 0.75rem);
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    font-variant-numeric: tabular-nums;
  }
  .dre-card__type {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: color-mix(in srgb, var(--primary, #2a4d8f) 14%, var(--surface, #fff));
    color: var(--ink-strong, var(--ink, #222));
    border-radius: var(--radius-full, 9999px);
    font-size: var(--text-xs, 0.7rem);
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
  }
  .dre-card__title {
    margin: 0;
    font-size: var(--text-lg, 1.125rem);
    line-height: 1.35;
    font-family: var(--font-display, Georgia, serif);
    color: var(--ink-strong, var(--ink, #222));
  }
  .dre-card__title a {
    color: inherit;
    text-decoration: none;
  }
  .dre-card__title a:hover {
    color: var(--primary, #2a4d8f);
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .dre-card__byline {
    margin: 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #444));
  }
  .dre-card__snippet {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #444));
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .dre-card__chips {
    list-style: none;
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
  }
  .dre-card__chip {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    background: var(--surface-sunken, #f5f5f5);
    color: var(--ink-light, var(--ink, #444));
    border-radius: var(--radius-sm, 0.375rem);
    font-size: var(--text-xs, 0.75rem);
    font-weight: 500;
  }
  .dre-card__chip--project {
    background: color-mix(in srgb, var(--accent, #d57912) 16%, var(--surface, #fff));
    color: var(--ink-strong, var(--ink, #222));
    font-weight: 600;
  }

  @media (max-width: 32rem) {
    .dre-card {
      grid-template-columns: 1fr;
      gap: var(--space-sm, 0.5rem);
    }
    .dre-card__thumb {
      width: 100%;
      height: 8rem;
    }
  }
</style>
