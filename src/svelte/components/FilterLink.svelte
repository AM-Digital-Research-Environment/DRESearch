<script lang="ts">
  import type { Snippet } from 'svelte';

  /**
   * An inline "click to filter" value — an author, editor, venue, publisher,
   * place, project or language sitting inside a sentence of running text.
   *
   * Why a span with role="button" and not a real <button>: a <button> is an
   * atomic inline-block, so its label can never break across the paragraph's
   * line boxes. On a narrow screen that wrecks a citation twice over — a long
   * venue takes the full column width and forces itself onto its own line
   * (stranding orphans like " (eds.)," and ", pp. 25–48." on lines of their
   * own), and the UA's `text-align: center` for buttons centres the value's own
   * wrapped lines against the left-aligned text around them. A span fragments
   * like the text it sits in, so the reference reads as one paragraph at every
   * width. Keyboard and screen-reader semantics are restored by
   * role + tabindex + Enter/Space.
   */

  interface Props {
    /** Apply the filter this value stands for. */
    onclick: () => void;
    children: Snippet;
  }

  const { onclick, children }: Props = $props();

  function handleKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    // Enter does nothing on a span, and Space would scroll the page.
    event.preventDefault();
    onclick();
  }
</script>

<span class="dre-filter-link" role="button" tabindex="0" {onclick} onkeydown={handleKeydown}
  >{@render children()}</span
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
  .dre-filter-link:focus-visible {
    outline: none;
    border-radius: var(--radius-sm, 0.375rem);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
    /* Repeat the ring on every line box when the value wraps, instead of
       drawing one ring around the union of the fragments. */
    -webkit-box-decoration-break: clone;
    box-decoration-break: clone;
  }
</style>
