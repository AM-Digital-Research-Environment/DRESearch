export type ThumbnailSlot = 'list' | 'gallery';

/** Select an existing Omeka derivative; never guesses an IIIF URL. */
export function thumbnailFor(url: string | undefined, slot: ThumbnailSlot): string | undefined {
  if (!url) return undefined;
  const derivative = slot === 'gallery' ? 'large' : 'medium';
  return url.replace(/\/files\/(square|medium|large)\//, `/files/${derivative}/`);
}
