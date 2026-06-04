<script lang="ts">
  import { parseHighlight } from '../lib/highlight';

  /**
   * Render a (possibly sentinel-marked) string, wrapping matched runs in <mark>.
   * Every run is emitted as a Svelte text node, so field values are always
   * escaped — there is no {@html} and thus no injection risk. A plain string
   * (no sentinels) renders as-is, so callers can route any value through this.
   */
  interface Props {
    value: string;
  }

  const { value }: Props = $props();

  const segments = $derived(parseHighlight(value ?? ''));
</script>

{#each segments as seg, i (i)}{#if seg.mark}<mark class="dre-hl">{seg.text}</mark
    >{:else}{seg.text}{/if}{/each}

<style>
  .dre-hl {
    /* A soft, brand-tinted marker. Inherit the surrounding colour/weight so the
       highlight reads as emphasis, not a separate chip. */
    background: var(--dre-hl-bg, color-mix(in srgb, var(--accent, #d57912) 30%, transparent));
    color: inherit;
    border-radius: 0.15rem;
    padding: 0 0.05em;
    /* Keep the tint contiguous when a match wraps across lines. */
    box-decoration-break: clone;
    -webkit-box-decoration-break: clone;
  }
</style>
