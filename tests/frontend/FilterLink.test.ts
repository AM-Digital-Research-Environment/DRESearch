import { fireEvent, render } from '@testing-library/svelte';
import axe from 'axe-core';
import { describe, expect, it, vi } from 'vitest';
import ResultsList from '../../src/svelte/components/ResultsList.svelte';
import type { ActiveFilters, CardKind, Doc } from '../../src/svelte/lib/types';

/**
 * Card filter values are TOGGLES: named for what pressing them does, pressed
 * while the value is an active filter, and pressing an active one removes it.
 *
 * Inline values are spans with role="button", not <button>s, so they can break
 * across the line boxes of the sentence they sit in. A real <button> is an
 * atomic inline-block that the UA also centres, which wrecked bibliographic
 * references on narrow screens. These tests pin the behaviour a <button> gave
 * us for free — pointer, keyboard, exposed role and pressed state.
 *
 * Cards are rendered through ResultsList, which provides the surface's filters
 * to every card (lib/cardFilters.ts).
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

function renderCard(
  card: Doc = doc,
  filters: ActiveFilters = {},
  cardKind: CardKind = 'publication',
  facetLabels: Record<string, string> = { creator_ss: 'Author / Editor' },
) {
  const onToggleFilter = vi.fn();
  const view = render(ResultsList, {
    hits: [card],
    found: 1,
    page: 1,
    perPage: 20,
    itemUrlBase: '/s/site/item',
    cardKind,
    filters,
    facetLabels,
    onPageChange: vi.fn(),
    onToggleFilter,
  });
  return { ...view, onToggleFilter };
}

const inline = () => [...document.querySelectorAll<HTMLElement>('span[role="button"]')];

describe('inline filter values', () => {
  it('exposes every clickable value as a toggle button named for its action', () => {
    renderCard();
    expect(inline().map((el) => el.textContent?.trim())).toEqual([
      'Madore, Frédérick',
      'Pérouse de Montclos, Marc-Antoine',
      "L'Afrique des religions à l'épreuve des chiffres et des catégorisations",
      'Maisonneuve & Larose-Hémisphères éditions',
    ]);
    expect(inline().map((el) => el.getAttribute('aria-label'))).toEqual([
      'Filter by Author / Editor: Madore, Frédérick',
      'Filter by Author / Editor: Pérouse de Montclos, Marc-Antoine',
      "Filter by Journal / Book: L'Afrique des religions à l'épreuve des chiffres et des catégorisations",
      'Filter by Publisher: Maisonneuve & Larose-Hémisphères éditions',
    ]);
    for (const el of inline()) {
      // A span only reaches the tab order if we put it there.
      expect(el).toHaveAttribute('tabindex', '0');
      expect(el).toHaveAttribute('aria-pressed', 'false');
    }
  });

  it('applies the filter on click', async () => {
    const { onToggleFilter } = renderCard();
    await fireEvent.click(inline()[0]!);
    expect(onToggleFilter).toHaveBeenCalledWith('creator_ss', 'Madore, Frédérick');
  });

  it.each([['Enter'], [' ']])('applies the filter on %s', async (key) => {
    const { onToggleFilter } = renderCard();
    await fireEvent.keyDown(inline()[2]!, { key });
    expect(onToggleFilter).toHaveBeenCalledWith(
      'container_ss',
      "L'Afrique des religions à l'épreuve des chiffres et des catégorisations",
    );
  });

  it('ignores other keys', async () => {
    const { onToggleFilter } = renderCard();
    await fireEvent.keyDown(inline()[0]!, { key: 'a' });
    await fireEvent.keyDown(inline()[0]!, { key: 'ArrowDown' });
    expect(onToggleFilter).not.toHaveBeenCalled();
  });

  it('shows an active value as pressed, offering to remove it', async () => {
    const { onToggleFilter, getByRole } = renderCard(doc, { creator_ss: ['Madore, Frédérick'] });
    const author = getByRole('button', {
      name: 'Remove filter Author / Editor: Madore, Frédérick',
    });
    expect(author).toHaveAttribute('aria-pressed', 'true');
    expect(
      getByRole('button', { name: 'Filter by Author / Editor: Pérouse de Montclos, Marc-Antoine' }),
    ).toHaveAttribute('aria-pressed', 'false');
    await fireEvent.click(author);
    expect(onToggleFilter).toHaveBeenCalledWith('creator_ss', 'Madore, Frédérick');
  });

  it('has no detectable axe violations, pressed or not', async () => {
    renderCard(doc, { publisher_ss: ['Maisonneuve & Larose-Hémisphères éditions'] });
    const result = await axe.run(document.body, { rules: { region: { enabled: false } } });
    expect(result.violations).toEqual([]);
  });

  it('keeps the reference line punctuated as one sentence', () => {
    // The template packs the separators tight against the tags so no stray
    // space ever surfaces before a comma. It survives a markup change only if
    // someone checks — hence this.
    renderCard({
      ...doc,
      editor_ss: ['Pérouse de Montclos, Marc-Antoine', 'Dasré, Aurélien'],
      container_ss: ["L'Afrique des religions"],
      volume_s: '4',
      issue_s: '2',
    } as unknown as Doc);
    expect(document.querySelector('.dre-bcard__ref')?.textContent).toBe(
      "In: Pérouse de Montclos, Marc-Antoine; Dasré, Aurélien (eds.), L'Afrique des religions, " +
        'vol. 4(2), pp. 25–48. Maisonneuve & Larose-Hémisphères éditions',
    );
    expect(document.querySelector('.dre-bcard__authors')?.textContent).toBe('Madore, Frédérick');
  });
});

describe('card chips', () => {
  const person = {
    id: '42',
    title: 'Vierke, Ulf',
    roles_ss: ['Principal investigator', 'Author'],
  } as unknown as Doc;

  it('are real buttons that toggle their filter', async () => {
    const { onToggleFilter, getByRole } = renderCard(person, { roles_ss: ['Author'] }, 'person', {
      roles_ss: 'Role',
    });
    const pi = getByRole('button', { name: 'Filter by Role: Principal investigator' });
    const author = getByRole('button', { name: 'Remove filter Role: Author' });
    expect(pi.tagName).toBe('BUTTON');
    expect(pi).toHaveAttribute('aria-pressed', 'false');
    expect(author).toHaveAttribute('aria-pressed', 'true');
    await fireEvent.click(author);
    expect(onToggleFilter).toHaveBeenCalledWith('roles_ss', 'Author');
  });

  it("name a field that is not one of the corpus's facets by its generic label", () => {
    const { getByRole } = renderCard(
      { id: '5', title: 'Lagos', type_s: 'City' } as unknown as Doc,
      {},
      'term',
      {},
    );
    expect(getByRole('button', { name: 'Filter by Type: City' })).toBeTruthy();
  });
});

describe('publication languages', () => {
  it('shows every language and applies the language filter by pointer and keyboard', async () => {
    const { onToggleFilter, getByRole } = renderCard({
      ...doc,
      language_ss: ['English', 'French'],
    });
    await fireEvent.click(getByRole('button', { name: 'Filter by Language: English' }));
    expect(onToggleFilter).toHaveBeenLastCalledWith('language_ss', 'English');
    await fireEvent.keyDown(getByRole('button', { name: 'Filter by Language: French' }), {
      key: 'Enter',
    });
    expect(onToggleFilter).toHaveBeenLastCalledWith('language_ss', 'French');
  });

  it('omits the language row when metadata is absent', () => {
    const { container } = renderCard();
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
    renderCard(monograph);
    expect(document.querySelector('.dre-bcard__ref')?.textContent).toBe(
      'Afrikanische Literaturen Transmedial, Transregional, Transkulturell, ' +
        'vol. 1, 370 pp. Akademische Verlagsanstalt',
    );
  });

  it('links the open-access full text beside the DOI and Cite', () => {
    const { container, getByRole } = renderCard({
      ...monograph,
      has_fulltext: 'Yes',
      fulltext_url_s: 'https://epub.uni-bayreuth.de/id/eprint/9405/',
      doi_s: 'https://doi.org/10.1007/s12115-026-01169-1',
    } as unknown as Doc);
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
    const { container, queryByRole } = renderCard(monograph);
    expect(queryByRole('link', { name: 'Full text' })).toBeNull();
    expect(container.querySelector('.dre-bcard__footer .dre-cite')).not.toBeNull();
  });
});
