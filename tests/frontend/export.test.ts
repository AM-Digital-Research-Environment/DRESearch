import { describe, expect, it } from 'vitest';
import { serialize, type ExportMeta } from '../../src/svelte/lib/export';
import type { Doc } from '../../src/svelte/lib/types';

const meta: ExportMeta = {
  query: 'history',
  found: 1,
  filters: {},
  yearFrom: null,
  yearTo: null,
  facetLabels: {},
};

describe('export serializers', () => {
  it('prevents newlines from injecting RIS fields', () => {
    const doc: Doc = { id: '1', title: 'Title\r\nER  - injected', author_ss: ['Doe\nAU  - Bad'] };
    const ris = serialize('ris', [doc], meta, 'publication', '/s/site/item');
    expect(ris).not.toContain('\nER  - injected');
    expect(ris).not.toContain('\nAU  - Bad');
  });

  it('keeps one plain-text record per line', () => {
    const doc: Doc = { id: '3', title: 'Real title\n- Forged record', author_ss: ['Doe'] };
    const txt = serialize('txt', [doc], meta, 'publication', '/s/site/item');
    expect(txt).not.toContain('\n- Forged record');
    expect(txt.split('\n').filter((line) => line.startsWith('- '))).toHaveLength(1);
  });

  it('leaves BibTeX doi and url verbatim', () => {
    const doc: Doc = { id: '4', title: 'A_title', doi_s: '10.1000/abc_def%1' };
    const bib = serialize('bibtex', [doc], meta, 'publication', '/s/site/item');
    expect(bib).toContain('doi = {10.1000/abc_def%1}');
    expect(bib).toContain('title = {A\\_title}');
  });

  it('cites a page range as pages and a page count as a total', () => {
    const chapter: Doc = { id: '5', title: 'Chapter', type_s: 'Book Section', pages_s: '185–209' };
    const book: Doc = { id: '6', title: 'Monograph', type_s: 'Book', pages_s: '370 pp.' };
    const ris = serialize('ris', [chapter, book], meta, 'publication', '/s/site/item');
    expect(ris).toContain('SP  - 185');
    expect(ris).toContain('EP  - 209');
    expect(ris).not.toContain('370 pp.');
    const bib = serialize('bibtex', [chapter, book], meta, 'publication', '/s/site/item');
    expect(bib).toContain('pages = {185--209}');
    expect(bib).toContain('pagetotal = {370}');
    expect(bib).not.toContain('pages = {370');
  });

  it('keeps a corporate creator whole in a BibTeX name list', () => {
    const doc: Doc = {
      id: '7',
      title: 'Report',
      author_ss: ['Ministry of Health and Public Hygiene', 'Doe, Jane'],
    };
    const bib = serialize('bibtex', [doc], meta, 'publication', '/s/site/item');
    expect(bib).toContain('author = {{Ministry of Health and Public Hygiene} and Doe, Jane}');
  });

  it('drops unsafe external media URLs', () => {
    const doc: Doc = { id: '2', title: 'Episode', url_s: 'javascript:alert(1)' };
    const ris = serialize('ris', [doc], meta, 'podcast', '/s/site/item');
    expect(ris).not.toContain('javascript:');
    expect(ris).toContain(`UR  - ${window.location.origin}/s/site/item/2`);
  });
});
