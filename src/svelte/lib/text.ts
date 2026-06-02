/**
 * Text helpers for client-side matching.
 */

/**
 * Lowercase and strip diacritics so client-side substring matching is
 * accent-insensitive — e.g. "Rudiger" matches "Rüdiger", "Jose" matches "José".
 * Works by decomposing characters (NFD) and dropping the combining marks, then
 * lowercasing. This mirrors Typesense's default accent folding on the server, so
 * the facet type-to-filter box behaves like the full-text search bar.
 *
 * (Ligatures/letters with no combining-mark decomposition — ß, æ, ø — are left
 * as-is; those are transliterations, not accents.)
 */
export function foldAccents(input: string): string {
  return input
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase();
}
