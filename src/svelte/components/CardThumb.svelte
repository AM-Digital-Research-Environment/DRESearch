<script lang="ts">
  import { thumbnailFor } from '../lib/thumbnail';
  import '../styles/card.css';

  /**
   * A result card's thumbnail: a decorative link to the record (the title is the
   * accessible link) around an Omeka derivative picked for the box it fills. The
   * box sizes are the `dre-shell__thumb--<shape>` rules in styles/card.css.
   */
  interface Props {
    href: string;
    url: string | undefined;
    shape: 'item' | 'logo' | 'poster' | 'avatar' | 'emblem';
    /** The gallery view: the image spans the card, from the large derivative. */
    gallery?: boolean;
    /** Above the fold (first gallery row): load now rather than lazily. */
    eager?: boolean;
    /** Render an empty box when there is no image, so the text column stays aligned. */
    placeholder?: boolean;
  }

  const { href, url, shape, gallery = false, eager = false, placeholder = false }: Props = $props();

  const image = $derived(
    thumbnailFor(
      url,
      gallery ? 'gallery' : shape === 'avatar' || shape === 'emblem' ? 'avatar' : 'list',
    ),
  );
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
  <div
    class="dre-shell__thumb dre-shell__thumb--{shape} dre-shell__thumb--empty"
    aria-hidden="true"
  ></div>
{/if}
