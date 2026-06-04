<script lang="ts">
  import type { Doc } from '../lib/types';
  import { t, matchFieldLabel } from '../lib/i18n';
  import Highlight from './Highlight.svelte';

  /**
   * A compact "Matched in …" line for query matches that fall in a searchable
   * field the card doesn't otherwise display (e.g. a Subject or Tag on a research
   * item) — so the reader always sees *why* a result matched. Fields the card
   * already shows highlighted are passed in `exclude`. Renders nothing when there
   * are no off-card matches (the common case), so it's safe to drop on any card.
   */
  interface Props {
    doc: Doc;
    /** Fields the card already surfaces highlighted (omit them here). */
    exclude?: string[];
  }

  const { doc, exclude = [] }: Props = $props();

  const items = $derived.by(() => {
    const hl = doc._highlights ?? {};
    const skip = new Set(exclude);
    const out: { field: string; label: string; snippet: string }[] = [];
    for (const [field, snippets] of Object.entries(hl)) {
      if (skip.has(field) || !snippets || snippets.length === 0) {
        continue;
      }
      out.push({ field, label: matchFieldLabel(field), snippet: snippets[0] });
    }
    return out;
  });
</script>

{#if items.length > 0}
  <p class="dre-match">
    <span class="dre-match__label">{t('matched_in')}</span>
    {#each items as it, i (it.field)}{#if i > 0}<span class="dre-match__sep"> · </span>{/if}<span
        class="dre-match__field">{it.label}:</span
      >
      <Highlight value={it.snippet} />{/each}
  </p>
{/if}

<style>
  .dre-match {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-xs, 0.78rem);
    line-height: 1.5;
    color: var(--ink-light, var(--ink, #444));
  }
  .dre-match__label {
    color: var(--muted, #666);
    font-weight: 700;
    font-size: var(--text-xs, 0.72rem);
    letter-spacing: 0.04em;
    text-transform: uppercase;
    margin-inline-end: 0.35rem;
  }
  .dre-match__field {
    color: var(--muted, #666);
    font-weight: 600;
  }
  .dre-match__sep {
    color: var(--muted, #999);
  }
</style>
