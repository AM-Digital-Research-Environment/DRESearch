import { describe, expect, it } from 'vitest';
import { thumbnailFor } from '../../src/svelte/lib/thumbnail';

describe('thumbnails', () => {
  it('selects Omeka derivatives without inventing IIIF URLs', () => {
    const source = 'https://example.test/files/medium/abc.jpg';
    expect(thumbnailFor(source, 'list')).toEqual({ src: source });
    expect(thumbnailFor(source, 'avatar')).toEqual({
      src: 'https://example.test/files/square/abc.jpg',
    });
    expect(thumbnailFor(source, 'gallery')).toEqual({
      src: 'https://example.test/files/large/abc.jpg',
      srcset:
        'https://example.test/files/medium/abc.jpg 200w, https://example.test/files/large/abc.jpg 800w',
      sizes: expect.stringContaining('100vw') as unknown as string,
    });
    expect(thumbnailFor('https://example.test/original.jpg', 'gallery')).toEqual({
      src: 'https://example.test/original.jpg',
    });
    expect(thumbnailFor(undefined, 'list')).toBeUndefined();
  });
});
