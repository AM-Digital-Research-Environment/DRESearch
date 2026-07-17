import type { ActiveFilters, CardKind, Doc } from './types';
import { matchFieldLabel, t } from './i18n';
import { safeExternalUrl } from './text';

/**
 * Client-side serializers for the result-export menu: plain text, JSON, RIS
 * (Zotero / EndNote) and BibTeX, built from the citation metadata the export
 * fetch ships (see SearchProxy::export / QueryBuilder::export server-side).
 *
 * DRE spans many corpora, so serialization is `kind`-aware:
 *   - publications are the rich bibliographic case — authors / editors / venue
 *     (container_ss = dcterms:isPartOf) / publisher / volume / issue / pages /
 *     DOI map to real @article/@incollection/@book/… entries, keyed off the
 *     dcterms:type vocabulary;
 *   - research items carry creators + year + subjects (a @misc / GEN work);
 *   - podcasts / videos are SOUND / VIDEO records (series / playlist as the
 *     container, the external listen/watch link alongside the Omeka page);
 *   - people / organisations / sections / authority terms aren't citeable works
 *     but still export as @misc / GEN rows (most useful as txt / json), so the
 *     button behaves consistently on every corpus.
 *
 * The canonical URL is the Omeka item page (`${item_url_base}/${id}`, made
 * absolute against the current origin) — the export is otherwise self-contained
 * and does not depend on any companion SEO/citation module at runtime.
 */

export type ExportFormat = 'txt' | 'json' | 'ris' | 'bibtex';

export interface ExportMeta {
  /** The free-text query the export was run with ('' = browse). */
  query: string;
  /** Total matches in the result set (may exceed the exported count). */
  found: number;
  /** Active facet filters (field => selected values) at export time. */
  filters: ActiveFilters;
  /** Active year-range bounds (null = unconstrained at that end). */
  yearFrom: number | null;
  yearTo: number | null;
  /** field => human label, so the header reads "Type" not "type_s". */
  facetLabels: Record<string, string>;
}

/**
 * Hard cap on exported hits — mirror of QueryBuilder::EXPORT_MAX_HITS (the
 * server pages there). Drives the "exports the first N" hint in the menu.
 */
export const EXPORT_MAX_HITS = 1000;

export const EXPORT_FORMATS: ReadonlyArray<{
  format: ExportFormat;
  extension: string;
  mime: string;
}> = [
  { format: 'txt', extension: 'txt', mime: 'text/plain;charset=utf-8' },
  { format: 'json', extension: 'json', mime: 'application/json;charset=utf-8' },
  { format: 'ris', extension: 'ris', mime: 'application/x-research-info-systems;charset=utf-8' },
  { format: 'bibtex', extension: 'bib', mime: 'application/x-bibtex;charset=utf-8' },
];

export function serialize(
  format: ExportFormat,
  docs: Doc[],
  meta: ExportMeta,
  kind: CardKind,
  itemUrlBase: string,
): string {
  switch (format) {
    case 'json':
      return toJson(docs, meta);
    case 'ris':
      return toRis(docs, kind, itemUrlBase);
    case 'bibtex':
      return toBibtex(docs, kind, itemUrlBase);
    default:
      return toTxt(docs, meta, kind, itemUrlBase);
  }
}

/** Trigger a browser download of `content` as `filename`. */
export function download(filename: string, mime: string, content: string): void {
  const blob = new Blob([content], { type: mime });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  // Keep the object URL alive through the browser's asynchronous download handoff.
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function exportFilename(extension: string): string {
  const stamp = new Date().toISOString().slice(0, 10);
  return `dre-search-${stamp}.${extension}`;
}

// ─── Shared field helpers ────────────────────────────────────────────────

/** Absolute Omeka item URL — the stable citation target for every kind. */
function itemUrl(d: Doc, base: string): string {
  if (!d.id) return '';
  const path = `${base.replace(/\/+$/, '')}/${encodeURIComponent(d.id)}`;
  try {
    return new URL(path, window.location.origin).href;
  } catch {
    return path;
  }
}

/** Every link for a doc: the Omeka page first, then any external media link. */
function links(d: Doc, kind: CardKind, base: string): string[] {
  const out = [itemUrl(d, base)];
  if ((kind === 'podcast' || kind === 'video') && d.url_s) {
    out.push(safeExternalUrl(d.url_s));
  }
  return out.filter((s) => s !== '');
}

/** Primary creators (the "author" line), by corpus kind. */
function creators(d: Doc, kind: CardKind): string[] {
  switch (kind) {
    case 'publication':
      return d.author_ss ?? [];
    case 'item':
      return d.creator_ss ?? [];
    case 'podcast':
      return d.host_ss ?? [];
    case 'video':
      return d.speaker_ss ?? [];
    case 'project':
      return d.pi_ss ?? [];
    default:
      return [];
  }
}

/** Secondary creators (editors / guests) → RIS A2, BibTeX editor. */
function editors(d: Doc, kind: CardKind): string[] {
  if (kind === 'publication') return d.editor_ss ?? [];
  if (kind === 'podcast') return d.guest_ss ?? [];
  return [];
}

/** Container / venue: journal or book (publications), series, playlist. */
function container(d: Doc, kind: CardKind): string {
  if (kind === 'publication') return (d.container_ss?.[0] ?? '').trim();
  if (kind === 'podcast') return (d.series_s ?? '').trim();
  if (kind === 'video') return (d.playlist_s ?? '').trim();
  return '';
}

/** Subject keywords, union across the corpora that carry any. */
function keywords(d: Doc): string[] {
  const all = [...(d.keyword_ss ?? []), ...(d.subject_ss ?? []), ...(d.tag_ss ?? [])];
  return Array.from(new Set(all.map((s) => s.trim()).filter((s) => s !== '')));
}

function year(d: Doc): string {
  if (d.year) return String(d.year);
  if (d.year_start) return String(d.year_start);
  return '';
}

/** "185–209" → [start, end]; single page → [page, '']. */
function pageRange(d: Doc): [string, string] {
  const pages = (d.pages_s ?? '').trim();
  if (!pages) return ['', ''];
  const m = pages.split(/[–—-]/).map((s) => s.trim());
  return [m[0] ?? '', m[1] ?? ''];
}

/** Bare DOI (no resolver prefix) for the RIS DO / BibTeX doi fields. */
function bareDoi(d: Doc): string {
  return (d.doi_s ?? '').replace(/^https?:\/\/(dx\.)?doi\.org\//i, '').trim();
}

/**
 * Coarse publication class from the dcterms:type vocabulary (free-text, so
 * matched by substring rather than an exact map). Falls back to "article" when a
 * venue is present, else "other".
 */
type PubClass = 'article' | 'chapter' | 'book' | 'thesis' | 'report' | 'conference' | 'other';
function pubClass(d: Doc, kind: CardKind): PubClass {
  const s = (d.type_s ?? '').toLowerCase();
  if (/(chapter|chapitre|contribution|kapitel)/.test(s)) return 'chapter';
  if (/(thesis|dissertation|th[èe]se|phd|doctoral|habilitation)/.test(s)) return 'thesis';
  if (/(report|rapport|working\s*paper|preprint)/.test(s)) return 'report';
  if (/(conference|proceeding|communication|congr[èe]s|symposium)/.test(s)) return 'conference';
  // "Book review" is a review article, not a book — catch it before `book`.
  if (/(review|compte\s*rendu|rezension)/.test(s)) return 'article';
  if (/(book|monograph|livre|ouvrage|buch|edited\s*volume)/.test(s)) return 'book';
  if (/(article|journal|revue|essay|paper|aufsatz)/.test(s)) return 'article';
  return container(d, kind) ? 'article' : 'other';
}

// ─── Search-context helpers (recorded in the export header) ────────────────

/** Whether any facet filter is active. */
function hasFilters(filters: ActiveFilters): boolean {
  return Object.values(filters ?? {}).some((v) => v && v.length > 0);
}

/** Human-readable label for a year-range constraint, or '' when unbounded. */
function yearLabel(from: number | null, to: number | null): string {
  if (from != null && to != null) return from === to ? String(from) : `${from}–${to}`;
  if (from != null) return `≥ ${from}`;
  if (to != null) return `≤ ${to}`;
  return '';
}

/** Indented "Label: v1, v2" lines for each active filter, plus the year range. */
function filterLines(meta: ExportMeta): string[] {
  const lines: string[] = [];
  for (const [field, values] of Object.entries(meta.filters ?? {})) {
    if (!values || values.length === 0) continue;
    const label = meta.facetLabels?.[field] ?? matchFieldLabel(field);
    lines.push(`  ${label}: ${values.join(', ')}`);
  }
  const yr = yearLabel(meta.yearFrom, meta.yearTo);
  if (yr) lines.push(`  ${t('year_label')}: ${yr}`);
  return lines;
}

// ─── Plain text ──────────────────────────────────────────────────────────

function toTxt(docs: Doc[], meta: ExportMeta, kind: CardKind, base: string): string {
  const origin = typeof window !== 'undefined' ? window.location.origin : '';
  const lines: string[] = [
    `DRE Search${origin ? ' — ' + origin : ''}`,
    meta.query ? `${t('export_query_label')}: ${meta.query}` : t('export_browse_label'),
  ];
  const fl = filterLines(meta);
  if (fl.length > 0) {
    lines.push(`${t('export_filters_label')}:`, ...fl);
  }
  lines.push(`${t('export_count_label')}: ${docs.length} / ${meta.found}`, '');
  for (const d of docs) {
    const parts: string[] = [];
    const authors = creators(d, kind).join(', ');
    const y = year(d);
    parts.push(`${authors ? authors + ' ' : ''}${y ? `(${y})` : ''}`.trim());
    parts.push(d.title ?? '');
    const cont = container(d, kind);
    if (cont) {
      const vol = (d.volume_s ?? '').trim();
      const iss = (d.issue_s ?? '').trim();
      const volIss = vol && iss ? `${vol}(${iss})` : vol || (iss ? `(${iss})` : '');
      const pages = (d.pages_s ?? '').trim();
      parts.push([cont, volIss, pages].filter(Boolean).join(', '));
    }
    if (d.type_s) {
      parts.push(`[${d.type_s}]`);
    }
    const url = links(d, kind, base)[0];
    if (url) parts.push(url);
    lines.push('- ' + parts.filter(Boolean).join('. '));
  }
  return lines.join('\n') + '\n';
}

// ─── JSON ────────────────────────────────────────────────────────────────

function toJson(docs: Doc[], meta: ExportMeta): string {
  return JSON.stringify(
    {
      source: 'DRE Search',
      site: typeof window !== 'undefined' ? window.location.origin : null,
      exported_at: new Date().toISOString(),
      query: meta.query || null,
      filters: hasFilters(meta.filters) ? meta.filters : null,
      year_from: meta.yearFrom ?? null,
      year_to: meta.yearTo ?? null,
      total_found: meta.found,
      exported: docs.length,
      results: docs,
    },
    null,
    2,
  );
}

// ─── RIS ─────────────────────────────────────────────────────────────────

const RIS_PUB_TYPES: Record<PubClass, string> = {
  article: 'JOUR',
  chapter: 'CHAP',
  book: 'BOOK',
  thesis: 'THES',
  report: 'RPRT',
  conference: 'CONF',
  other: 'GEN',
};

/** Research-item dcterms:type → RIS TY (the primary-source media kinds). */
const RIS_ITEM_TYPES: Record<string, string> = {
  manuscript: 'MANSCPT',
  image: 'FIGURE',
  audio: 'SOUND',
  'moving image': 'VIDEO',
  dataset: 'DATA',
  cartographic: 'MAP',
};

function risType(d: Doc, kind: CardKind): string {
  if (kind === 'publication') return RIS_PUB_TYPES[pubClass(d, kind)];
  if (kind === 'item') return RIS_ITEM_TYPES[(d.type_s ?? '').toLowerCase()] ?? 'GEN';
  if (kind === 'podcast') return 'SOUND';
  if (kind === 'video') return 'VIDEO';
  return 'GEN';
}

function toRis(docs: Doc[], kind: CardKind, base: string): string {
  const out: string[] = [];
  for (const d of docs) {
    const tag = (name: string, value: string | undefined | null): void => {
      const v = (value ?? '').replace(/[\r\n]+/g, ' ').trim();
      if (v !== '') out.push(`${name}  - ${v}`);
    };
    out.push(`TY  - ${risType(d, kind)}`);
    tag('TI', d.title);
    for (const a of creators(d, kind)) tag('AU', a);
    for (const e of editors(d, kind)) tag('A2', e);
    tag('T2', container(d, kind));
    // For chapters the container is the book, so the publisher still goes to PB.
    if (kind === 'publication' && pubClass(d, kind) !== 'article') {
      tag('PB', d.publisher_ss?.[0]);
    }
    tag('VL', d.volume_s);
    tag('IS', d.issue_s);
    const [sp, ep] = pageRange(d);
    tag('SP', sp);
    tag('EP', ep);
    tag('PY', year(d));
    tag('DA', d.date_s);
    tag('LA', d.language_ss?.[0]);
    for (const kw of keywords(d)) tag('KW', kw);
    tag('AB', d.abstract ?? d.description);
    tag('DO', bareDoi(d));
    for (const url of links(d, kind, base)) tag('UR', url);
    tag('ID', d.id);
    out.push('ER  - ', '');
  }
  return out.join('\r\n');
}

// ─── BibTeX ──────────────────────────────────────────────────────────────

const BIBTEX_PUB_TYPES: Record<PubClass, string> = {
  article: 'article',
  chapter: 'incollection',
  book: 'book',
  thesis: 'phdthesis',
  report: 'techreport',
  conference: 'inproceedings',
  other: 'misc',
};

function bibtexType(d: Doc, kind: CardKind): string {
  return kind === 'publication' ? BIBTEX_PUB_TYPES[pubClass(d, kind)] : 'misc';
}

/** Minimal BibTeX escaping for the DRE value space. */
function bib(value: string): string {
  return value
    .replace(/[\\{}]/g, ' ')
    .replace(/([&%#_$])/g, '\\$1')
    .replace(/\s+/g, ' ')
    .trim();
}

function toBibtex(docs: Doc[], kind: CardKind, base: string): string {
  const entries: string[] = [];
  for (const d of docs) {
    const type = bibtexType(d, kind);
    const cls = kind === 'publication' ? pubClass(d, kind) : 'other';
    const fields: Array<[string, string]> = [];
    const add = (name: string, value: string | undefined | null): void => {
      const v = (value ?? '').trim();
      if (v !== '') fields.push([name, bib(v)]);
    };

    add('title', d.title);
    add('author', creators(d, kind).join(' and '));
    add('editor', editors(d, kind).join(' and '));
    add('year', year(d));

    const cont = container(d, kind);
    if (type === 'article') {
      add('journal', cont);
      add('volume', d.volume_s);
      add('number', d.issue_s);
    } else if (type === 'incollection') {
      add('booktitle', cont);
      add('publisher', d.publisher_ss?.[0]);
    } else if (type === 'phdthesis') {
      add('school', d.publisher_ss?.[0]);
    } else if (type === 'techreport') {
      add('institution', d.publisher_ss?.[0]);
    } else if (type === 'book') {
      add('publisher', d.publisher_ss?.[0]);
    } else if (cont) {
      add('howpublished', cont);
    }

    const pages = (d.pages_s ?? '').trim();
    if (pages) add('pages', pages.replace(/[–—]/g, '--'));
    add('language', d.language_ss?.[0]);
    add('keywords', keywords(d).join(', '));
    add('doi', bareDoi(d));
    const all = links(d, kind, base);
    add('url', all[0]);
    if (all.length > 1) add('note', `Available at: ${all[1]}`);
    if (cls === 'other' && d.type_s) add('type', d.type_s);

    const key = `dre${d.id}`;
    const body = fields.map(([n, v]) => `  ${n} = {${v}}`).join(',\n');
    entries.push(`@${type}{${key},\n${body}\n}`);
  }
  return entries.join('\n\n') + '\n';
}
