import { afterEach, describe, expect, it } from 'vitest';
import { HL_END, HL_START } from '../../src/svelte/lib/highlight';
import { configureI18n } from '../../src/svelte/lib/i18n';
import {
  associationCounts,
  cardSnippet,
  cardTitle,
  cardType,
  linkedPeople,
  publicationIds,
  publicationMetrics,
  publicationReference,
  recordUrl,
  sectionLeaders,
  titleMarkup,
  yearRange,
} from '../../src/svelte/lib/resultCard';
import type { Doc } from '../../src/svelte/lib/types';

/** The card field rules, as plain functions of the indexed document. */

const mark = (s: string) => `${HL_START}${s}${HL_END}`;
const doc = (over: Partial<Doc> = {}): Doc => ({ id: '42', title: 'A record', ...over }) as Doc;

afterEach(() => configureI18n('en'));

describe('shared card fields', () => {
  it('links the record and names it, marking a title match', () => {
    expect(recordUrl('/s/amira/item', 'a b')).toBe('/s/amira/item/a%20b');
    expect(cardTitle(doc())).toBe('A record');
    expect(cardTitle(doc({ title: '' }))).toBe('Untitled');
    expect(titleMarkup(doc())).toBeNull();
    const marked = `A ${mark('record')}`;
    expect(titleMarkup(doc({ _highlights: { title: [marked] } }))).toBe(marked);
  });

  it('takes the snippet from the field that matched, else the first with text', () => {
    const abstract = 'An abstract.';
    const description = `A ${mark('matching')} description.`;
    expect(cardSnippet(doc({ abstract: `  ${abstract} ` }), ['abstract'])).toBe(abstract);
    expect(
      cardSnippet(doc({ abstract, _highlights: { description: [description] } }), [
        'abstract',
        'description',
      ]),
    ).toBe(description);
    // A blank abstract does not hide the description.
    expect(
      cardSnippet(doc({ abstract: ' ', description: 'Seen.' }), ['abstract', 'description']),
    ).toBe('Seen.');
    expect(cardSnippet(doc(), ['abstract'])).toBe('');
  });

  it('trims a type and keeps an absent one empty', () => {
    expect(cardType(doc({ type_s: ' Institution ' }))).toBe('Institution');
    expect(cardType(doc())).toBe('');
  });
});

describe('project run', () => {
  it('reads a single year or a range, and nothing without a start', () => {
    expect(yearRange(2019, 2025)).toBe('2019 – 2025');
    expect(yearRange(2019, 2019)).toBe('2019');
    expect(yearRange(2019, undefined)).toBe('2019');
    expect(yearRange(undefined, 2025)).toBe('');
  });
});

describe('linked people', () => {
  it('pairs names with their parallel ids, linking only the catalogued ones', () => {
    expect(linkedPeople('/item', ['Host, A', 'Guest, B'], ['7', ''])).toEqual([
      { name: 'Host, A', href: '/item/7' },
      { name: 'Guest, B', href: null },
    ]);
    expect(linkedPeople('/item', ['Only, C'], undefined)).toEqual([
      { name: 'Only, C', href: null },
    ]);
    expect(linkedPeople('/item', undefined, ['1'])).toEqual([]);
  });
});

describe('association counts', () => {
  it('lists the non-zero counts in the order asked, pluralised', () => {
    const org = doc({ project_count: 1, item_count: 0, people_count: 12 });
    expect(associationCounts(org, ['projects', 'items', 'people'])).toEqual([
      '1 project',
      '12 people',
    ]);
    expect(
      associationCounts(doc({ item_count: 3, publication_count: 1 }), ['items', 'publications']),
    ).toEqual(['3 research items', '1 publication']);
    expect(associationCounts(doc(), ['items', 'publications'])).toEqual([]);
  });

  it("uses the page locale's plural rules", () => {
    configureI18n('fr', { projects_one: '{n} projet', projects_other: '{n} projets' });
    expect(associationCounts(doc({ project_count: 2 }), ['projects'])).toEqual(['2 projets']);
  });
});

describe('section leaders', () => {
  it('names the PIs, else the spokesperson, else nobody', () => {
    expect(sectionLeaders(doc({ pi_ss: ['A'], spokesperson_ss: ['B'] }))).toEqual({
      label: 'PIs',
      names: ['A'],
    });
    expect(sectionLeaders(doc({ spokesperson_ss: ['B'] }))).toEqual({
      label: 'Spokesperson',
      names: ['B'],
    });
    expect(sectionLeaders(doc({ pi_ss: [] }))).toBeNull();
  });
});

describe('publication reference', () => {
  it('writes volume, issue and pages, without doubling a page count', () => {
    expect(publicationMetrics(doc({ volume_s: '4', issue_s: '2', pages_s: '25–48' }))).toBe(
      'vol. 4(2), pp. 25–48',
    );
    expect(publicationMetrics(doc({ volume_s: '4' }))).toBe('vol. 4');
    expect(publicationMetrics(doc({ issue_s: '2' }))).toBe('no. 2');
    expect(publicationMetrics(doc({ pages_s: '370 pp.' }))).toBe('370 pp.');
    expect(publicationMetrics(doc())).toBe('');
  });

  it('punctuates only between the parts that exist', () => {
    const chapter = publicationReference(
      doc({
        editor_ss: ['Ed, One', 'Ed, Two'],
        container_ss: ['The Book'],
        pages_s: '1–9',
        publisher_ss: ['Press'],
      }),
    );
    expect(chapter).toMatchObject({
      container: 'The Book',
      metrics: 'pp. 1–9',
      sepAfterVenue: ', ',
      sepAfterMetrics: '. ',
      editorsInRef: true,
      editorsAsByline: false,
      editorsLabel: 'eds.',
      hasReference: true,
    });

    const venueThenPublisher = publicationReference(
      doc({ container_ss: ['Journal'], publisher_ss: ['Press'] }),
    );
    expect(venueThenPublisher.sepAfterVenue).toBe('. ');
    expect(venueThenPublisher.sepAfterMetrics).toBe('');

    const monograph = publicationReference(
      doc({ editor_ss: ['Ed, One'], pages_s: '370 pp.', publisher_ss: ['Press'] }),
    );
    expect(monograph).toMatchObject({
      sepAfterVenue: '',
      sepAfterMetrics: ' ',
      editorsAsByline: true,
      editorsInRef: false,
      editorsLabel: 'ed.',
    });

    expect(publicationReference(doc()).hasReference).toBe(false);
  });

  it('leaves the DOI out of the identifier row: the footer links it', () => {
    const ids = publicationIds(
      doc({
        same_as_ss: ['https://doi.org/10.1000/xyz', 'https://www.wikidata.org/wiki/Q42'],
      }),
    );
    expect(ids.map((id) => id.key)).toEqual(['wikidata']);
  });
});
