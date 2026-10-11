/**
 * The line glyph a research item's thumbnail box shows when the item has no
 * image — most research items have no media, so in a list these boxes are the
 * rule rather than the exception. Keyed by the item's resource type (`type_s`,
 * the label of its dcterms:type authority), so a column of empty boxes still
 * says what each record is instead of reading as images that failed to load.
 *
 * One neutral tile for every type, told apart by the glyph alone: resource types
 * get no hue of their own, because the theme reserves hue for entity types
 * (DESIGN.md "Colors", Tertiary), which the facet chips already wear.
 *
 * The glyphs are Lucide 1.55.0 (ISC licence) — the icon set the theme uses —
 * with each rect, circle, ellipse and line rewritten as an equivalent path, so
 * CardThumb renders them as plain <path>s with no {@html}. Draw them in a
 * 24×24 viewBox with round caps and joins, as Lucide does.
 */

/** Each glyph's path data, Lucide names. */
const GLYPHS = {
  'file-text': [
    'M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z',
    'M14 2v5a1 1 0 0 0 1 1h5',
    'M10 9H8',
    'M16 13H8',
    'M16 17H8',
  ],
  film: [
    'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z',
    'M7 3v18',
    'M3 7.5h4',
    'M3 12h18',
    'M3 16.5h4',
    'M17 3v18',
    'M17 7.5h4',
    'M17 16.5h4',
  ],
  image: [
    'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z',
    'M7 9a2 2 0 1 0 4 0a2 2 0 1 0-4 0',
    'm21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21',
  ],
  'audio-lines': ['M2 10v3', 'M6 6v11', 'M10 3v18', 'M14 8v7', 'M18 5v13', 'M22 10v3'],
  'scroll-text': [
    'M15 12h-5',
    'M15 8h-5',
    'M19 17V5a2 2 0 0 0-2-2H4',
    'M8 21h12a2 2 0 0 0 2-2v-1a1 1 0 0 0-1-1H11a1 1 0 0 0-1 1v1a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v2a1 1 0 0 0 1 1h3',
  ],
  database: [
    'M3 5a9 3 0 1 0 18 0a9 3 0 1 0-18 0',
    'M3 5V19A9 3 0 0 0 21 19V5',
    'M3 12A9 3 0 0 0 21 12',
  ],
  archive: [
    'M3 3h18a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z',
    'M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8',
    'M10 12h4',
  ],
  monitor: [
    'M4 3h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z',
    'M8 21h8',
    'M12 17v4',
  ],
  'book-open-text': [
    'M12 5v16',
    'M16 13h2',
    'M16 9h2',
    'M20.001 19A2 2 0 0 0 22 17V5a2 2 0 0 0-1.999-2L16 3.002A5 5 0 0 0 12 5a5 5 0 0 0-4-2H4a2 2 0 0 0-2 2v12a2 2 0 0 0 1.999 2H8a5 5 0 0 1 4 2 5 5 0 0 1 4-2z',
    'M6 13h2',
    'M6 9h2',
  ],
  map: [
    'M14.106 5.553a2 2 0 0 0 1.788 0l3.659-1.83A1 1 0 0 1 21 4.619v12.764a1 1 0 0 1-.553.894l-4.553 2.277a2 2 0 0 1-1.788 0l-4.212-2.106a2 2 0 0 0-1.788 0l-3.659 1.83A1 1 0 0 1 3 19.381V6.618a1 1 0 0 1 .553-.894l4.553-2.277a2 2 0 0 1 1.788 0z',
    'M15 5.764v15',
    'M9 3.236v15',
  ],
  layers: [
    'M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z',
    'M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12',
    'M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17',
  ],
  file: [
    'M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z',
    'M14 2v5a1 1 0 0 0 1 1h5',
  ],
} as const satisfies Record<string, readonly string[]>;

export type GlyphName = keyof typeof GLYPHS;

/**
 * The research-item type vocabulary, lower-cased — the labels of the Type of
 * Resource authorities that dcterms:type links to. A label not listed here
 * (a new or renamed authority) falls back to the plain file glyph.
 */
const BY_TYPE: Record<string, GlyphName> = {
  text: 'file-text',
  'moving image': 'film',
  image: 'image',
  audio: 'audio-lines',
  manuscript: 'scroll-text',
  dataset: 'database',
  collection: 'archive',
  digital: 'monitor',
  bibliography: 'book-open-text',
  cartographic: 'map',
  multimedia: 'layers',
};

/** The glyph for a resource-type label; the plain file glyph when unknown. */
export function glyphNameFor(type: string | undefined): GlyphName {
  return BY_TYPE[(type ?? '').trim().toLowerCase()] ?? 'file';
}

/** The path data of the glyph for a resource-type label. */
export function glyphFor(type: string | undefined): readonly string[] {
  return GLYPHS[glyphNameFor(type)];
}
