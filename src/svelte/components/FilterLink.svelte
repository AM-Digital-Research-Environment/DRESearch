<script lang="ts">
  import type { Snippet } from 'svelte';
  import { cardFilters, filterToggleLabel } from '../lib/cardFilters';

  /**
   * An inline "click to filter" value — an author, editor, venue, publisher,
   * place, project or language sitting inside a sentence of running text. It
   * is a toggle (lib/cardFilters.ts): pressed while the value is an active
   * filter, named for what pressing it does ("Filter by Author: …" / "Remove
   * filter Author: …").
   *
   * Why a span with role="button" and not a real <button>: a <button> is an
   * atomic inline-block, so its label can never break across the paragraph's
   * line boxes — `display: inline` does not change that (measured in Chromium:
   * one box, against three for the span in a 160px column), and
   * `display: contents` drops the button from the focus order. On a narrow
   * screen that wrecks a citation twice over — a long venue takes the full
   * column width and forces itself onto its own line (stranding orphans like
   * " (eds.)," and ", pp. 25–48." on lines of their own), and the UA's
   * `text-align: center` for buttons centres the value's own wrapped lines
   * against the left-aligned text around them. A span fragments like the text
   * it sits in, so the reference reads as one paragraph at every width.
   * Keyboard and screen-reader semantics are restored by role + tabindex +
   * Enter/Space, and the toggle state by aria-pressed.
   */

  interface Props {
    /** The filter field this value belongs to, e.g. "creator_ss". */
    field: string;
    /** The raw filter value (the visible text may carry highlight marks). */
    value: string;
    children: Snippet;
  }

  const { field, value, children }: Props = $props();
  const filters = cardFilters();
  const active = $derived(filters.isActive(field, value));
  const label = $derived(filterToggleLabel(active, filters.label(field), value));

  function handleKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    // Enter does nothing on a span, and Space would scroll the page.
    event.preventDefault();
    filters.toggle(field, value);
  }
</script>

<span
  class="dre-filter-link"
  role="button"
  tabindex="0"
  aria-pressed={active}
  aria-label={label}
  onclick={() => filters.toggle(field, value)}
  onkeydown={handleKeydown}>{@render children()}</span
>

<style>
  /* Plain underlined text that turns brand-coloured on hover. No chrome to
     reset — a span has none — and nothing that would make it atomic again
     (no display, padding or border). */
  .dre-filter-link {
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 2px;
    text-decoration-color: color-mix(in srgb, currentColor 35%, transparent);
    /* A single overlong token (a run-on publisher string) breaks rather than
       pushing the card's text past its edge. */
    overflow-wrap: break-word;
  }
  .dre-filter-link:hover {
    color: var(--primary, #007a50);
    text-decoration-color: currentColor;
  }
  /* Pressed: the value is an active filter. A full-strength, thicker underline
     in the readable brand text colour — still text, so it still wraps. */
  .dre-filter-link[aria-pressed='true'] {
    color: var(--primary-text, #006440);
    text-decoration-color: currentColor;
    text-decoration-thickness: 2px;
  }
  .dre-filter-link:focus-visible {
    outline: 2px solid var(--focus-color, #007a50);
    outline-offset: 2px;
    border-radius: var(--radius-sm, 0.375rem);
    /* Repeat the outline on every line box when the value wraps, instead of
       drawing one ring around the union of the fragments. */
    -webkit-box-decoration-break: clone;
    box-decoration-break: clone;
  }
</style>
