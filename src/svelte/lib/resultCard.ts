import type { Doc } from './types';
import { firstMarked } from './highlight';
import { externalIds, type ExternalId } from './identifiers';
import {
  membersLabel,
  peopleLabel,
  projectsLabel,
  publicationsLabel,
  researchItemsLabel,
  t,
} from './i18n';

/**
 * The fields a result card displays, derived from one indexed document.
 *
 * The RULES the cards follow — which highlight becomes the snippet, how a
 * publication's reference line is punctuated, which association counts show,
 * who leads a research section — used to be written inline in each card
 * component, reachable by a test only by mounting it. They are plain functions
 * of the document here (IwacSearch's lib/resultCard.ts is the model), covered
 * by tests/frontend/resultCard.test.ts. Each card keeps its markup, styles and
 * interactions, and calls these from `$derived`.
 */

/** The Omeka page of a record: `${itemUrlBase}/${id}`. */
export function recordUrl(itemUrlBase: string, id: string): string {
  return `${itemUrlBase}/${encodeURIComponent(id)}`;
}

/** The card's name: the title, or "Untitled" for a record without one. */
export function cardTitle(doc: Doc): string {
  return doc.title || t('untitled');
}

/** The title with its query match marked, or null to show the plain title. */
export function titleMarkup(doc: Doc): string | null {
  return doc._highlights?.title?.[0] ?? null;
}

/**
 * The body snippet: the first of `fields` that matched the query (centred on
 * the match), else the first of them that has text, shown plainly.
 */
export function cardSnippet(doc: Doc, fields: readonly ('abstract' | 'description')[]): string {
  const marked = firstMarked(doc, [...fields]);
  if (marked !== null) return marked;
  for (const field of fields) {
    const text = (doc[field] ?? '').trim();
    if (text) return text;
  }
  return '';
}

/** A project's run: "2019", or "2019 – 2025"; '' when it has no start. */
export function yearRange(start: number | undefined, end: number | undefined): string {
  if (start == null) return '';
  return end != null && end !== start ? `${start} – ${end}` : String(start);
}

/** A named contributor with a link to their own record when the index carries its id. */
export interface LinkedPerson {
  name: string;
  /** Their record's page, or null for a name the catalogue does not link. */
  href: string | null;
}

/**
 * Pair a list of names with its parallel id list ("" where the name is an
 * unlinked literal), as the podcast hosts and the video speakers are indexed.
 */
export function linkedPeople(
  itemUrlBase: string,
  names: readonly string[] | undefined,
  ids: readonly string[] | undefined,
): LinkedPerson[] {
  const idList = ids ?? [];
  return (names ?? []).map((name, i) => {
    const id = idList[i] ?? '';
    return { name, href: id ? recordUrl(itemUrlBase, id) : null };
  });
}

/** A count an authority card can show, in the order the card lists them. */
export type AssociationKind = 'projects' | 'items' | 'publications' | 'people' | 'members';

const ASSOCIATIONS: Record<AssociationKind, (doc: Doc) => [number, (n: number) => string]> = {
  projects: (doc) => [doc.project_count ?? 0, projectsLabel],
  items: (doc) => [doc.item_count ?? 0, researchItemsLabel],
  publications: (doc) => [doc.publication_count ?? 0, publicationsLabel],
  people: (doc) => [doc.people_count ?? 0, peopleLabel],
  members: (doc) => [doc.member_count ?? 0, membersLabel],
};

/** The pluralised association counts of `kinds`, leaving out the zero ones. */
export function associationCounts(doc: Doc, kinds: readonly AssociationKind[]): string[] {
  const out: string[] = [];
  for (const kind of kinds) {
    const [n, label] = ASSOCIATIONS[kind](doc);
    if (n > 0) out.push(label(n));
  }
  return out;
}

/**
 * Who leads a research section: its PIs (phase 1), else its spokesperson
 * (phase 2), with the line's label; null when neither is recorded.
 */
export function sectionLeaders(doc: Doc): { label: string; names: string[] } | null {
  const pis = doc.pi_ss ?? [];
  if (pis.length > 0) return { label: t('pis_label'), names: pis };
  const spokespeople = doc.spokesperson_ss ?? [];
  if (spokespeople.length > 0) return { label: t('spokesperson_label'), names: spokespeople };
  return null;
}

/** A trimmed type value, '' when there is none (the organisation and term tags). */
export function cardType(doc: Doc): string {
  return (doc.type_s ?? '').trim();
}

/**
 * A publication's reconciled identifiers, without its DOI: the DOI already
 * has its own link in the card's footer.
 */
export function publicationIds(doc: Doc): ExternalId[] {
  return externalIds(doc.same_as_ss ?? []).filter((ext) => ext.key !== 'doi');
}

/** The bibliographic reference line of a publication card, as its parts. */
export interface PublicationReference {
  /** The journal or book (the venue), or ''. */
  container: string;
  publisher: string;
  /** "vol. 4(2), pp. 25–48", or ''. */
  metrics: string;
  /** What follows the venue: ", " before the metrics, ". " before a publisher, else ''. */
  sepAfterVenue: string;
  /** What follows the metrics before a publisher: ". ", or " " when they already end on a full stop. */
  sepAfterMetrics: string;
  /** No venue: the editors are the byline ("Ed. by …"). */
  editorsAsByline: boolean;
  /** A venue: the editors sit in the reference ("In: X (eds.), Venue"). */
  editorsInRef: boolean;
  /** "ed." or "eds." */
  editorsLabel: string;
  hasReference: boolean;
}

/**
 * Volume, issue and pages: "vol. 4(2)", "vol. 4" or "no. 2", then "pp. 25–48".
 * A range takes the "pp." prefix; a monograph's page COUNT already reads
 * "370 pp." (PublicationMapper), and prefixing it gave "pp. 370 pp.".
 */
export function publicationMetrics(doc: Doc): string {
  const bits: string[] = [];
  const vol = doc.volume_s ?? '';
  const issue = doc.issue_s ?? '';
  if (vol && issue) bits.push(`${t('vol_short')} ${vol}(${issue})`);
  else if (vol) bits.push(`${t('vol_short')} ${vol}`);
  else if (issue) bits.push(`${t('no_short')} ${issue}`);
  const pages = doc.pages_s ?? '';
  if (pages) bits.push(/\spp\.$/.test(pages) ? pages : `${t('pp_short')} ${pages}`);
  return bits.join(', ');
}

/**
 * The reference line's parts, separators included. Every part is optional, so
 * a separator is written only between two parts that exist — a stray ", " or
 * a doubled full stop reads as missing data. Separators are TRAILING (each ends
 * in its own space), so whitespace the template puts between the filter values
 * collapses into it instead of surfacing as a space before a comma.
 */
export function publicationReference(doc: Doc): PublicationReference {
  const editors = doc.editor_ss ?? [];
  const container = doc.container_ss?.[0] ?? '';
  const publisher = doc.publisher_ss?.[0] ?? '';
  const metrics = publicationMetrics(doc);
  const sepAfterVenue = !container ? '' : metrics ? ', ' : publisher ? '. ' : '';
  const sepAfterMetrics = metrics && publisher ? (metrics.endsWith('.') ? ' ' : '. ') : '';
  return {
    container,
    publisher,
    metrics,
    sepAfterVenue,
    sepAfterMetrics,
    editorsAsByline: editors.length > 0 && !container,
    editorsInRef: editors.length > 0 && container !== '',
    // A bibliographic abbreviation, not a count: "ed." for one editor, "eds." for more.
    editorsLabel: editors.length > 1 ? t('eds_short') : t('ed_short'),
    hasReference: Boolean(container || metrics || publisher),
  };
}
