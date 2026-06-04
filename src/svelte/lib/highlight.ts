/**
 * Match-highlight helpers.
 *
 * The PHP layer marks matched tokens with two Unicode private-use sentinels
 * (NOT <mark>), so the marks can never be confused with HTML in a field value.
 * The client splits a marked string into plain/marked segments and renders the
 * marked ones as <mark> via Svelte text nodes — so a field value containing,
 * say, "<script>" is shown verbatim, never executed (no {@html}). See
 * QueryBuilder::HL_START / SearchProxy::extractHighlights on the server.
 */

import type { Doc } from './types';

// Must match QueryBuilder::HL_START / HL_END. Built from the code points (U+E000,
// U+E001) so the source stays pure ASCII — no invisible characters to mangle.
export const HL_START = String.fromCharCode(0xe000);
export const HL_END = String.fromCharCode(0xe001);

export interface HighlightSegment {
  text: string;
  /** True if this run was a matched token (render as <mark>). */
  mark: boolean;
}

/**
 * Split a sentinel-marked string into ordered plain/marked segments. A string
 * with no sentinels yields a single plain segment, so callers can route every
 * value through {@link parseHighlight} unconditionally.
 */
export function parseHighlight(input: string): HighlightSegment[] {
  const out: HighlightSegment[] = [];
  let i = 0;
  while (i < input.length) {
    const start = input.indexOf(HL_START, i);
    if (start === -1) {
      out.push({ text: input.slice(i), mark: false });
      break;
    }
    if (start > i) {
      out.push({ text: input.slice(i, start), mark: false });
    }
    const end = input.indexOf(HL_END, start + 1);
    if (end === -1) {
      // Unbalanced — treat the remainder as plain (drop the stray start mark).
      out.push({ text: input.slice(start + 1), mark: false });
      break;
    }
    out.push({ text: input.slice(start + 1, end), mark: true });
    i = end + 1;
  }
  return out;
}

/** Strip the sentinels, leaving the plain text — used to match a marked snippet
 *  back to the displayed value it highlights. */
export function stripMarkers(input: string): string {
  return input.split(HL_START).join('').split(HL_END).join('');
}

/**
 * The first marked snippet across `fields`, in order, or null. Used to pick a
 * card's snippet from whichever of abstract/description actually matched.
 */
export function firstMarked(doc: Doc, fields: string[]): string | null {
  const hl = doc._highlights;
  if (!hl) {
    return null;
  }
  for (const f of fields) {
    const arr = hl[f];
    if (arr && arr.length > 0) {
      return arr[0];
    }
  }
  return null;
}

/**
 * A plain-value => marked-snippet lookup for one (array) field, so a card can
 * highlight the matched entries of a list it already renders (a byline, chips)
 * while leaving the rest plain. Keyed on the de-marked text, which equals the
 * displayed value for the short fields highlighted in full.
 */
export function markedLookup(doc: Doc, field: string): Map<string, string> {
  const map = new Map<string, string>();
  for (const snip of doc._highlights?.[field] ?? []) {
    map.set(stripMarkers(snip), snip);
  }
  return map;
}
