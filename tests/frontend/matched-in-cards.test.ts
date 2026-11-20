import { render } from '@testing-library/svelte';
import { describe, expect, it } from 'vitest';
import ChronicleCard from '../../src/svelte/components/ChronicleCard.svelte';
import PublicationCard from '../../src/svelte/components/PublicationCard.svelte';
import { HL_END, HL_START } from '../../src/svelte/lib/highlight';
import { cappedChips } from '../../src/svelte/lib/resultCard';
import type { Doc } from '../../src/svelte/lib/types';

/**
 * "Matched in" names a match the card does not already show marked. A field
 * the card shows (and marks) is left out of it, so a match is never printed
 * twice, and never hidden either.
 */
const mark = (s: string): string => `${HL_START}${s}${HL_END}`;

describe('the Matched in line on a card', () => {
  it('a publication marks its publisher in the reference line, not twice', () => {
    const { container } = render(PublicationCard, {
      doc: {
        id: '1',
        title: 'A book',
        publisher_ss: ['Brill'],
        _highlights: { publisher_ss: [mark('Brill')] },
      } as unknown as Doc,
      itemUrlBase: '/s/site/item',
    });
    expect(container.querySelector('mark')?.textContent).toBe('Brill');
    expect(container.textContent).not.toContain('Matched in');
  });

  it('a publication shows the keyword it matched even past the chip cap', () => {
    const keywords = Array.from({ length: 12 }, (_, i) => `Keyword ${i + 1}`);
    const { container } = render(PublicationCard, {
      doc: {
        id: '2',
        title: 'A heavily tagged article',
        keyword_ss: keywords,
        _highlights: { keyword_ss: [mark('Keyword 11')] },
      } as unknown as Doc,
      itemUrlBase: '/s/site/item',
    });
    expect([...container.querySelectorAll('mark')].map((m) => m.textContent)).toEqual([
      'Keyword 11',
    ]);
  });

  it('a chronicle marks its translated title and places where it shows them', () => {
    const { container } = render(ChronicleCard, {
      doc: {
        id: '3',
        title: 'Ein Gedicht',
        alternative_s: 'A poem',
        place_ss: ['Bayreuth', 'Eldoret'],
        _highlights: { alternative_s: [mark('A poem')], place_ss: [mark('Eldoret')] },
      } as unknown as Doc,
      itemUrlBase: '/s/site/item',
    });
    expect([...container.querySelectorAll('mark')].map((m) => m.textContent)).toEqual([
      'A poem',
      'Eldoret',
    ]);
    expect(container.textContent).not.toContain('Matched in');
  });

  it("a chronicle's full text, which the card does not show, is named in the line", () => {
    const { container } = render(ChronicleCard, {
      doc: {
        id: '4',
        title: 'An essay',
        has_content: true,
        _highlights: { content: [`…the ${mark('river')} at dusk…`] },
      } as unknown as Doc,
      itemUrlBase: '/s/site/item',
    });
    expect(container.textContent).toContain('Matched in');
    expect(container.querySelector('mark')?.textContent).toBe('river');
  });
});

describe('cappedChips', () => {
  it('puts the matched values first and keeps the rest in order', () => {
    const marked = new Map([['d', mark('d')]]);
    expect(cappedChips(['a', 'b', 'c', 'd'], marked, 3)).toEqual(['d', 'a', 'b']);
    expect(cappedChips(['a', 'b'], new Map(), 3)).toEqual(['a', 'b']);
  });
});
