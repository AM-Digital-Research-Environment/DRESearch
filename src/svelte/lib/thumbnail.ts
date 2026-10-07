/**
 * Where a card shows its thumbnail:
 *   - list:    a small box beside the text (research items, podcast logos, video posters)
 *   - gallery: the full-width image on top of a gallery card
 *   - avatar:  a fixed 3.25rem square or circle (people, organisations)
 */
export type ThumbnailSlot = 'list' | 'gallery' | 'avatar';

export interface ThumbnailImage {
  src: string;
  srcset?: string;
  sizes?: string;
}

/**
 * Omeka's derivative widths (its default thumbnail types): `square` is a 200 px
 * centre crop, `medium` and `large` fit the longer side in 200 and 800 px.
 */
const DERIVATIVE = /\/files\/(square|medium|large)\//;
const MEDIUM_WIDTH = 200;
const LARGE_WIDTH = 800;

/**
 * Gallery cards fill `repeat(auto-fill, minmax(min(100%, 14rem), 1fr))` in the
 * results column: one column on phones, about 18–20rem from there up.
 */
const GALLERY_SIZES = '(max-width: 40rem) 100vw, 20rem';

function derivative(url: string, type: 'square' | 'medium' | 'large'): string {
  return url.replace(DERIVATIVE, `/files/${type}/`);
}

/**
 * The image attributes for a card slot. Selects an existing Omeka derivative and
 * never guesses an IIIF URL: a thumbnail that is not an Omeka derivative is used
 * as it is.
 *
 * Only the gallery gets a `srcset`. The list and avatar boxes are at most 7rem,
 * which the 200 px derivatives cover; offering `large` there would make a 2x
 * screen download the 800 px image for every row of a 20-result list.
 */
export function thumbnailFor(
  url: string | undefined,
  slot: ThumbnailSlot,
): ThumbnailImage | undefined {
  if (!url) return undefined;
  if (!DERIVATIVE.test(url)) return { src: url };
  if (slot === 'avatar') return { src: derivative(url, 'square') };
  if (slot === 'list') return { src: derivative(url, 'medium') };
  return {
    src: derivative(url, 'large'),
    srcset: `${derivative(url, 'medium')} ${MEDIUM_WIDTH}w, ${derivative(url, 'large')} ${LARGE_WIDTH}w`,
    sizes: GALLERY_SIZES,
  };
}
