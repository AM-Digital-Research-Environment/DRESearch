import { fireEvent, render } from '@testing-library/svelte';
import axe from 'axe-core';
import { describe, expect, it, vi } from 'vitest';
import PublicationCard from '../../src/svelte/components/PublicationCard.svelte';
import type { Doc } from '../../src/svelte/lib/types';

/**
 * Inline "click to filter" values are spans with role="button", not <button>s,
 * so they can break across the line boxes of the sentence they sit in. A real
 * <button> is an atomic inline-block that the UA also centres, which wrecked
 * bibliographic references on narrow screens. These tests pin the behaviour a
 * <button> gave us for free — pointer, keyboard, and exposed role.
 */

const doc: Doc = {
  id: '7392',
  kind: 'publication',
  title: 'Repenser la catégorisation religieuse',
  author_ss: ['Madore, Frédérick'],
  editor_ss: ['Pérouse de Montclos, Marc-Antoine'],
  container_ss: ["L'Afrique des religions à l'épreuve des chiffres et des catégorisations"],
  publisher_ss: ['Maisonneuve & Larose-Hémisphères éditions'],
  pages_s: '25–48',
} as unknown as Doc;

function renderCard() {
  const onAddFilter = vi.fn();
  render(PublicationCard, { doc, itemUrlBase: '/s/site/item', onAddFilter });
  return onAddFilter;
}

describe('inline filter values', () => {
  it('exposes every clickable value as a button to assistive tech', () => {
    renderCard();
    const labels = [...document.querySelectorAll('[role="button"]')].map((el) =>
      el.textContent?.trim(),
    );
    expect(labels).toEqual([
      'Madore, Frédérick',
      'Pérouse de Montclos, Marc-Antoine',
      "L'Afrique des religions à l'épreuve des chiffres et des catégorisations",
      'Maisonneuve & Larose-Hémisphères éditions',
    ]);
    // A span only reaches the tab order if we put it there.
    for (const el of document.querySelectorAll('[role="button"]')) {
      expect(el).toHaveAttribute('tabindex', '0');
    }
  });

  it('applies the filter on click', async () => {
    const onAddFilter = renderCard();
    const author = document.querySelectorAll('[role="button"]')[0] as HTMLElement;
    await fireEvent.click(author);
    expect(onAddFilter).toHaveBeenCalledWith('creator_ss', 'Madore, Frédérick');
  });

  it.each([['Enter'], [' ']])('applies the filter on %s', async (key) => {
    const onAddFilter = renderCard();
    const venue = document.querySelectorAll('[role="button"]')[2] as HTMLElement;
    await fireEvent.keyDown(venue, { key });
    expect(onAddFilter).toHaveBeenCalledWith(
      'container_ss',
      "L'Afrique des religions à l'épreuve des chiffres et des catégorisations",
    );
  });

  it('ignores other keys', async () => {
    const onAddFilter = renderCard();
    const author = document.querySelectorAll('[role="button"]')[0] as HTMLElement;
    await fireEvent.keyDown(author, { key: 'a' });
    await fireEvent.keyDown(author, { key: 'ArrowDown' });
    expect(onAddFilter).not.toHaveBeenCalled();
  });

  it('has no detectable axe violations', async () => {
    renderCard();
    const result = await axe.run(document.body, { rules: { region: { enabled: false } } });
    expect(result.violations).toEqual([]);
  });

  it('keeps the reference line punctuated as one sentence', () => {
    // The template packs the separators tight against the tags so no stray
    // space ever surfaces before a comma. It survives a markup change only if
    // someone checks — hence this.
    const onAddFilter = vi.fn();
    render(PublicationCard, {
      doc: {
        ...doc,
        editor_ss: ['Pérouse de Montclos, Marc-Antoine', 'Dasré, Aurélien'],
        container_ss: ["L'Afrique des religions"],
        volume_s: '4',
        issue_s: '2',
      } as unknown as Doc,
      itemUrlBase: '/s/site/item',
      onAddFilter,
    });
    expect(document.querySelector('.dre-bcard__ref')?.textContent).toBe(
      "In: Pérouse de Montclos, Marc-Antoine; Dasré, Aurélien (eds.), L'Afrique des religions, " +
        'vol. 4(2), pp. 25–48. Maisonneuve & Larose-Hémisphères éditions',
    );
    expect(document.querySelector('.dre-bcard__authors')?.textContent).toBe('Madore, Frédérick');
  });
});

describe('publication languages', () => {
  it('shows every language and applies the language filter by pointer and keyboard', async () => {
    const onAddFilter = vi.fn();
    const { getByRole } = render(PublicationCard, {
      doc: { ...doc, language_ss: ['English', 'French'] },
      itemUrlBase: '/s/site/item',
      onAddFilter,
    });
    await fireEvent.click(getByRole('button', { name: 'English' }));
    expect(onAddFilter).toHaveBeenLastCalledWith('language_ss', 'English');
    await fireEvent.keyDown(getByRole('button', { name: 'French' }), { key: 'Enter' });
    expect(onAddFilter).toHaveBeenLastCalledWith('language_ss', 'French');
  });

  it('omits the language row when metadata is absent', () => {
    const { container } = render(PublicationCard, {
      doc,
      itemUrlBase: '/s/site/item',
      onAddFilter: vi.fn(),
    });
    expect(container.querySelector('.dre-bcard__languages')).toBeNull();
  });
});

describe('publication footer', () => {
  const monograph = {
    ...doc,
    editor_ss: [],
    container_ss: ['Afrikanische Literaturen Transmedial, Transregional, Transkulturell'],
    volume_s: '1',
    pages_s: '370 pp.',
    publisher_ss: ['Akademische Verlagsanstalt'],
  } as unknown as Doc;

  it('prints a page count once, without a doubled full stop', () => {
    render(PublicationCard, { doc: monograph, itemUrlBase: '/s/site/item', onAddFilter: vi.fn() });
    expect(document.querySelector('.dre-bcard__ref')?.textContent).toBe(
      'Afrikanische Literaturen Transmedial, Transregional, Transkulturell, ' +
        'vol. 1, 370 pp. Akademische Verlagsanstalt',
    );
  });

  it('links the open-access full text beside the DOI and Cite', () => {
    const { container, getByRole } = render(PublicationCard, {
      doc: {
        ...monograph,
        has_fulltext: 'Yes',
        fulltext_url_s: 'https://epub.uni-bayreuth.de/id/eprint/9405/',
        doi_s: 'https://doi.org/10.1007/s12115-026-01169-1',
      },
      itemUrlBase: '/s/site/item',
      onAddFilter: vi.fn(),
    });
    expect(getByRole('link', { name: 'Full text' }).getAttribute('href')).toBe(
      'https://epub.uni-bayreuth.de/id/eprint/9405/',
    );
    const actions = container.querySelector('.dre-bcard__actions');
    // One row, in reading order; Cite is labelled by its <summary>.
    expect(
      [...(actions?.children ?? [])].map((el) =>
        (el.querySelector('summary') ?? el).textContent?.trim(),
      ),
    ).toEqual(['Full text', 'DOI', 'Cite']);
  });

  it('keeps Cite in the footer when there is no full text, DOI or keyword', () => {
    const { container, queryByRole } = render(PublicationCard, {
      doc: monograph,
      itemUrlBase: '/s/site/item',
      onAddFilter: vi.fn(),
    });
    expect(queryByRole('link', { name: 'Full text' })).toBeNull();
    expect(container.querySelector('.dre-bcard__footer .dre-cite')).not.toBeNull();
  });
});
