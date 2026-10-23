import { safeExternalUrl } from './text';

/**
 * Reconciled identifiers — the records a person, subject, place or
 * organisation is the same as in another register (ORCID, Wikidata, GND,
 * LCSH), and a publication's DOI.
 *
 * The indexer ships them as bare URIs (same_as_ss ← dcterms:identifier, and
 * doi_s); the register and the identifier are read off the host and path here,
 * the same way the theme's helper/RecordLinkedData.php names them on the record
 * page, so a card and the page it links to agree. A URI on a host no reader
 * would recognise is not shown.
 */
export type RegisterKey =
  'doi' | 'orcid' | 'wikidata' | 'gnd' | 'loc' | 'viaf' | 'geonames' | 'ror' | 'isni';

export interface ExternalId {
  key: RegisterKey;
  /** The register's name, e.g. "ORCID". */
  label: string;
  /** The identifier within it, e.g. "0000-0002-1825-0097" or "Q42". */
  id: string;
  href: string;
}

/** In display order: a publication's DOI first, then people's registers. */
const REGISTERS: ReadonlyArray<{ key: RegisterKey; label: string; host: string }> = [
  { key: 'doi', label: 'DOI', host: 'doi.org' },
  { key: 'orcid', label: 'ORCID', host: 'orcid.org' },
  { key: 'wikidata', label: 'Wikidata', host: 'wikidata.org' },
  { key: 'gnd', label: 'GND', host: 'd-nb.info' },
  { key: 'loc', label: 'Library of Congress', host: 'id.loc.gov' },
  { key: 'viaf', label: 'VIAF', host: 'viaf.org' },
  { key: 'geonames', label: 'GeoNames', host: 'geonames.org' },
  { key: 'ror', label: 'ROR', host: 'ror.org' },
  { key: 'isni', label: 'ISNI', host: 'isni.org' },
];

/** Registers with a mark of their own (RegisterMark); the rest get a link glyph. */
const MARKED: ReadonlySet<RegisterKey> = new Set(['doi', 'orcid', 'wikidata', 'gnd', 'loc']);

export function hasMark(key: RegisterKey): boolean {
  return MARKED.has(key);
}

function decode(segment: string): string {
  try {
    return decodeURIComponent(segment);
  } catch {
    return segment;
  }
}

/** `http://www.wikidata.org/entity/Q42` → Wikidata Q42; null for anything else. */
export function parseExternalId(uri: string | null | undefined): ExternalId | null {
  const href = safeExternalUrl(uri);
  if (!href) return null;
  const url = new URL(href);
  const host = url.hostname.toLowerCase().replace(/^www\./, '');
  const register = REGISTERS.find((r) => host === r.host || host.endsWith('.' + r.host));
  if (!register) return null;

  const path = url.pathname.replace(/^\/+|\/+$/g, '');
  const segments = path.split('/');
  // A DOI is its whole path; elsewhere the identifier is the last segment
  // (…/entity/Q42, …/gnd/118540238), except GeoNames' …/2950159/about.rdf.
  let id = register.key === 'doi' ? decode(path) : decode(segments[segments.length - 1] ?? '');
  if (/\.(?:rdf|html)$/.test(id)) {
    id = segments.length > 1 ? decode(segments[segments.length - 2] ?? '') : '';
  }
  return id ? { key: register.key, label: register.label, id, href } : null;
}

/** The known identifiers among some URIs, once each, in register order. */
export function externalIds(...uris: ReadonlyArray<string | null | undefined>[]): ExternalId[] {
  const seen = new Map<string, ExternalId>();
  for (const uri of uris.flat()) {
    const parsed = parseExternalId(uri);
    const key = parsed ? `${parsed.key}|${parsed.id.toLowerCase()}` : '';
    if (parsed && !seen.has(key)) seen.set(key, parsed);
  }
  const rank = (k: RegisterKey): number => REGISTERS.findIndex((r) => r.key === k);
  return [...seen.values()].sort((a, b) => rank(a.key) - rank(b.key));
}
