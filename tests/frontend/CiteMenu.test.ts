import { fireEvent, render, screen, waitFor } from '@testing-library/svelte';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CiteMenu from '../../src/svelte/components/CiteMenu.svelte';

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('per-record citations', () => {
  it('copies one record as BibTeX and announces it', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } });
    render(CiteMenu, {
      doc: {
        id: '7',
        title: 'Swahili poetry',
        creator_ss: ['Vierke, Clarissa'],
        year: 2014,
      },
      kind: 'publication',
      itemUrlBase: 'https://example.org/s/amira/item',
    });
    await fireEvent.click(screen.getByRole('button', { name: 'Copy BibTeX' }));
    await waitFor(() => expect(screen.getByRole('status').textContent).toBe('Copied'));
    const bibtex = writeText.mock.calls[0]?.[0] as string;
    expect(bibtex).toMatch(/^@\w+\{/);
    expect(bibtex).toContain('Swahili poetry');
    expect(bibtex).toContain('https://example.org/s/amira/item/7');
    await fireEvent.click(screen.getByRole('button', { name: 'Copy RIS' }));
    await waitFor(() => expect(writeText).toHaveBeenCalledTimes(2));
    expect(writeText.mock.calls[1]?.[0]).toMatch(/\r\nER {2}- \r\n$/);
  });
});
