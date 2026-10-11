import { render } from '@testing-library/svelte';
import { describe, expect, it } from 'vitest';
import CardThumb from '../../src/svelte/components/CardThumb.svelte';
import { glyphFor, glyphNameFor } from '../../src/svelte/lib/typeGlyph';

/**
 * A research item with no image gets a placeholder carrying its resource type's
 * glyph, so a list of media-less records still says what each one is.
 */

describe('type glyphs', () => {
  it('maps every research-item type to its own Lucide glyph', () => {
    const types = [
      'Text',
      'Moving image',
      'Image',
      'Audio',
      'Manuscript',
      'Dataset',
      'Collection',
      'Digital',
      'Bibliography',
      'Cartographic',
      'Multimedia',
    ];
    const names = types.map(glyphNameFor);
    expect(names).toEqual([
      'file-text',
      'film',
      'image',
      'audio-lines',
      'scroll-text',
      'database',
      'archive',
      'monitor',
      'book-open-text',
      'map',
      'layers',
    ]);
    expect(new Set(names).size).toBe(types.length);
  });

  it('ignores case and surrounding space, and falls back to a plain file', () => {
    expect(glyphNameFor('  moving IMAGE ')).toBe('film');
    expect(glyphNameFor('Sculpture')).toBe('file');
    expect(glyphNameFor(undefined)).toBe('file');
  });
});

describe('CardThumb placeholder', () => {
  const base = { href: '/s/amira/item/1', url: undefined, shape: 'item' as const };

  it("draws the type's glyph in a decorative link to the record", () => {
    const { container } = render(CardThumb, { ...base, placeholder: true, type: 'Audio' });
    const box = container.querySelector('.dre-shell__thumb--empty');
    expect(box?.tagName).toBe('A');
    expect(box?.getAttribute('href')).toBe('/s/amira/item/1');
    expect(box?.getAttribute('tabindex')).toBe('-1');
    expect(box?.getAttribute('aria-hidden')).toBe('true');
    const paths = [...container.querySelectorAll('svg path')].map((p) => p.getAttribute('d'));
    expect(paths).toEqual([...glyphFor('Audio')]);
  });

  it('shows the image, not the glyph, when there is one', () => {
    const { container } = render(CardThumb, {
      ...base,
      url: 'https://example.test/files/medium/abc.jpg',
      placeholder: true,
      type: 'Audio',
    });
    expect(container.querySelector('img')).not.toBeNull();
    expect(container.querySelector('.dre-shell__thumb--empty')).toBeNull();
  });

  it('renders nothing without an image unless a placeholder is asked for', () => {
    const { container } = render(CardThumb, base);
    expect(container.querySelector('.dre-shell__thumb')).toBeNull();
  });
});
