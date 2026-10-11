<script lang="ts">
  import { thumbnailFor } from '../lib/thumbnail';
  import { glyphFor } from '../lib/typeGlyph';
  import '../styles/card.css';

  /**
   * A result card's thumbnail: a decorative link to the record (the title is the
   * accessible link) around an Omeka derivative picked for the box it fills, or
   * around the type glyph that stands in for a missing image (lib/typeGlyph.ts).
   * The box sizes are the `dre-shell__thumb--<shape>` rules in styles/card.css.
   */
  interface Props {
    href: string;
    url: string | undefined;
    shape: 'item' | 'logo' | 'poster' | 'avatar' | 'emblem';
    /** The gallery view: the image spans the card, from the large derivative. */
    gallery?: boolean;
    /** Above the fold (first gallery row): load now rather than lazily. */
    eager?: boolean;
    /**
     * Render a placeholder box when there is no image, so the text column stays
     * aligned: a neutral tile with the line glyph of the record's `type`.
     */
    placeholder?: boolean;
    /** The resource type the placeholder's glyph stands for (`type_s`). */
    type?: string;
  }

  const {
    href,
    url,
    shape,
    gallery = false,
    eager = false,
    placeholder = false,
    type,
  }: Props = $props();

  const image = $derived(
    thumbnailFor(
      url,
      gallery ? 'gallery' : shape === 'avatar' || shape === 'emblem' ? 'avatar' : 'list',
    ),
  );
  const glyph = $derived(glyphFor(type));
</script>

{#if image}
  <a class="dre-shell__thumb dre-shell__thumb--{shape}" {href} tabindex="-1" aria-hidden="true">
    <img
      src={image.src}
      srcset={image.srcset}
      sizes={image.sizes}
      alt=""
      loading={eager ? 'eager' : 'lazy'}
      fetchpriority={eager && gallery ? 'high' : 'auto'}
      decoding="async"
    />
  </a>
{:else if placeholder}
  <a
    class="dre-shell__thumb dre-shell__thumb--{shape} dre-shell__thumb--empty"
    {href}
    tabindex="-1"
    aria-hidden="true"
  >
    <svg
      class="dre-shell__glyph"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-linecap="round"
      stroke-linejoin="round"
      focusable="false"
    >
      {#each glyph as d (d)}<path {d} />{/each}
    </svg>
  </a>
{/if}
