import { render } from '@testing-library/svelte';
import axe from 'axe-core';
import { describe, expect, it, vi } from 'vitest';
import PersonCard from '../../src/svelte/components/PersonCard.svelte';
import PublicationCard from '../../src/svelte/components/PublicationCard.svelte';
import TermCard from '../../src/svelte/components/TermCard.svelte';
import { externalIds, parseExternalId } from '../../src/svelte/lib/identifiers';
import type { Doc } from '../../src/svelte/lib/types';

/**
 * Reconciled identifiers: the indexer ships dcterms:identifier URIs as
 * same_as_ss, and the cards name the register from the host, as the theme's
 * helper/RecordLinkedData.php does on the record page.
 */
describe('parseExternalId', () => {
  it('names the register and the identifier it holds', () => {
    expect(parseExternalId('https://orcid.org/0000-0002-1825-0097')).toMatchObject({
      key: 'orcid',
      label: 'ORCID',
      id: '0000-0002-1825-0097',
    });
    expect(parseExternalId('http://www.wikidata.org/entity/Q42')).toMatchObject({
      key: 'wikidata',
      id: 'Q42',
    });
    expect(parseExternalId('https://d-nb.info/gnd/118540238')).toMatchObject({
      key: 'gnd',
      id: '118540238',
    });
    expect(parseExternalId('http://id.loc.gov/authorities/subjects/sh85104779')).toMatchObject({
      key: 'loc',
      label: 'Library of Congress',
      id: 'sh85104779',
    });
    expect(parseExternalId('https://doi.org/10.1163/9789004512345')).toMatchObject({
      key: 'doi',
      id: '10.1163/9789004512345',
    });
    expect(parseExternalId('https://sws.geonames.org/2950159/about.rdf')).toMatchObject({
      key: 'geonames',
      id: '2950159',
    });
  });

  it('ignores unknown hosts, non-http schemes and literals', () => {
    expect(parseExternalId('https://unknown.example.org/x/1')).toBeNull();
    expect(parseExternalId('javascript:alert(1)')).toBeNull();
    expect(parseExternalId('eprints-12345')).toBeNull();
    expect(parseExternalId('https://orcid.org/')).toBeNull();
    expect(parseExternalId(undefined)).toBeNull();
  });

  it('dedupes and orders DOI, ORCID, Wikidata, GND, Library of Congress', () => {
    const ids = externalIds([
      'https://d-nb.info/gnd/118540238',
      'http://www.wikidata.org/entity/Q42',
      'https://www.wikidata.org/entity/q42',
      'https://orcid.org/0000-0002-1825-0097',
      'https://viaf.org/viaf/12345/',
    ]);
    expect(ids.map((i) => i.label)).toEqual(['ORCID', 'Wikidata', 'GND', 'VIAF']);
  });
});

describe('identifier links on cards', () => {
  const person = {
    id: '42',
    title: 'Vierke, Ulf',
    same_as_ss: [
      'https://d-nb.info/gnd/132450585',
      'https://orcid.org/0000-0002-1825-0097',
      'http://www.wikidata.org/entity/Q1234567',
    ],
  } as unknown as Doc;

  it('a person card links each register, marked, with its name for screen readers', async () => {
    const { container, getByRole } = render(PersonCard, {
      doc: person,
      itemUrlBase: '/s/site/item',
      onAddFilter: vi.fn(),
    });
    const list = getByRole('list', { name: 'Identifiers in other registers' });
    const links = [...list.querySelectorAll('a')];
    expect(links.map((a) => a.textContent)).toEqual([
      'ORCID 0000-0002-1825-0097',
      'Wikidata Q1234567',
      'GND 132450585',
    ]);
    expect(links[0]?.getAttribute('href')).toBe('https://orcid.org/0000-0002-1825-0097');
    expect(links[0]?.getAttribute('rel')).toBe('noopener noreferrer');
    expect(links.every((a) => a.querySelector('svg[aria-hidden="true"]'))).toBe(true);
    const results = await axe.run(container, { rules: { region: { enabled: false } } });
    expect(results.violations).toEqual([]);
  });

  it('a card without identifiers renders no list', () => {
    const { queryByRole } = render(TermCard, {
      doc: { id: '7', title: 'Popular music' } as unknown as Doc,
      itemUrlBase: '/s/site/item',
      profile: 'research_subjects',
      onAddFilter: vi.fn(),
    });
    expect(queryByRole('list', { name: 'Identifiers in other registers' })).toBeNull();
  });

  it('a publication shows its Wikidata item beside the marked DOI pill, never the DOI twice', () => {
    const { container } = render(PublicationCard, {
      doc: {
        id: '9',
        title: 'Mobile Sounds',
        doi_s: 'https://doi.org/10.1163/9789004512345',
        same_as_ss: [
          'http://www.wikidata.org/entity/Q98765432',
          'https://doi.org/10.1163/9789004512345',
        ],
      } as unknown as Doc,
      itemUrlBase: '/s/site/item',
      onAddFilter: vi.fn(),
    });
    const actions = container.querySelector('.dre-bcard__actions');
    expect(
      [...(actions?.children ?? [])].map((el) =>
        (el.querySelector('summary') ?? el).textContent?.trim(),
      ),
    ).toEqual(['Wikidata Q98765432', 'DOI', 'Cite']);
    expect(actions?.querySelector('a.dre-shell__external svg.dre-mark--doi')).not.toBeNull();
  });
});
