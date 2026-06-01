<script lang="ts">
  import type { FacetCount } from '../lib/types';
  import { t } from '../lib/i18n';

  interface Props {
    field: string;
    label: string;
    counts: FacetCount[];
    selected: string[];
    onToggle: (field: string, value: string, checked: boolean) => void;
  }

  const { field, label, counts, selected, onToggle }: Props = $props();

  const COLLAPSED = 8;
  let open = $state(true);
  let expanded = $state(false);

  const visible = $derived(expanded ? counts : counts.slice(0, COLLAPSED));
</script>

<section class="dre-facet">
  <button
    type="button"
    class="dre-facet__heading"
    aria-expanded={open}
    onclick={() => (open = !open)}
  >
    <span class="dre-facet__label">{label}</span>
    {#if selected.length > 0}
      <span class="dre-facet__badge">{selected.length}</span>
    {/if}
    <span class="dre-facet__chevron" aria-hidden="true">{open ? '▾' : '▸'}</span>
  </button>

  {#if open}
    <ul class="dre-facet__list">
      {#each visible as c (c.value)}
        <li>
          <label class="dre-facet__option">
            <input
              type="checkbox"
              checked={selected.includes(c.value)}
              onchange={(e) =>
                onToggle(field, c.value, (e.currentTarget as HTMLInputElement).checked)}
            />
            <span class="dre-facet__value">{c.value}</span>
            <span class="dre-facet__count">{c.count.toLocaleString()}</span>
          </label>
        </li>
      {/each}
    </ul>

    {#if counts.length > COLLAPSED}
      <button type="button" class="dre-facet__more" onclick={() => (expanded = !expanded)}>
        {expanded ? t('show_less') : t('show_more', { n: counts.length - COLLAPSED })}
      </button>
    {/if}
  {/if}
</section>

<style>
  .dre-facet {
    padding-block: var(--space-md, 1rem);
    border-bottom: 1px solid var(--border-light, #eee);
  }
  .dre-facet:last-child {
    border-bottom: none;
  }
  .dre-facet__heading {
    display: flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    width: 100%;
    padding: 0;
    background: none;
    border: none;
    cursor: pointer;
    font: inherit;
    color: var(--ink-strong, var(--ink, #222));
    font-size: var(--text-xs, 0.75rem);
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    text-align: start;
  }
  .dre-facet__heading:hover {
    color: var(--primary, #2a4d8f);
  }
  .dre-facet__label {
    flex: 1;
  }
  .dre-facet__badge {
    background: var(--primary, #2a4d8f);
    color: var(--primary-contrast, #fff);
    border-radius: var(--radius-full, 9999px);
    min-width: 1.25rem;
    height: 1.25rem;
    padding: 0 0.4rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: var(--text-xs, 0.7rem);
    font-weight: 600;
    letter-spacing: 0;
  }
  .dre-facet__chevron {
    color: var(--muted, #888);
    font-size: var(--text-xs, 0.75rem);
  }
  .dre-facet__list {
    list-style: none;
    margin: var(--space-sm, 0.5rem) 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
  }
  .dre-facet__option {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    padding: 0.2rem 0.25rem;
    border-radius: var(--radius-sm, 0.375rem);
    cursor: pointer;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink, #222);
  }
  .dre-facet__option:hover {
    background: var(--surface-sunken, #f3f3f3);
  }
  .dre-facet__value {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .dre-facet__count {
    color: var(--muted, #888);
    font-size: var(--text-xs, 0.75rem);
    font-variant-numeric: tabular-nums;
  }
  .dre-facet__more {
    margin-top: var(--space-xs, 0.25rem);
    background: none;
    border: none;
    padding: 0.2rem 0.25rem;
    color: var(--primary, #2a4d8f);
    font-size: var(--text-xs, 0.75rem);
    cursor: pointer;
  }
  .dre-facet__more:hover {
    text-decoration: underline;
  }
</style>
