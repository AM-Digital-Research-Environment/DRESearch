import { getContext, setContext } from 'svelte';

/**
 * Heading levels inside a search surface, per the shared contract (DRE-theme
 * docs/DESIGN-INTEGRATION.md, "Shared widgets"): a page block's title is h2
 * and the headings inside it are h3; a surface with no title of its own (an
 * untitled block, the federated page under its h1) promotes them to h2.
 *
 * The block template says which through `data-dre-heading-level`; App passes
 * it to its own headings ("Search results", "Filters") and, through this
 * context, to every card title one level below.
 */
export type HeadingLevel = 2 | 3;

const KEY = Symbol('dre-heading-level');

/** The level from the mount node's data attribute; anything else is 2. */
export function parseHeadingLevel(raw: string | undefined): HeadingLevel {
  return raw === '3' ? 3 : 2;
}

/** Publish the surface's heading level to the cards below (call during init). */
export function provideHeadingLevel(level: HeadingLevel): void {
  setContext(KEY, level);
}

/** A result card's title tag: one level below its surface's headings. */
export function cardTitleTag(): 'h3' | 'h4' {
  return getContext<HeadingLevel | undefined>(KEY) === 3 ? 'h4' : 'h3';
}
