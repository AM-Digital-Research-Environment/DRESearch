<script lang="ts">
  interface Props {
    values: number[];
    label: string;
  }
  const { values, label }: Props = $props();
  const max = $derived(Math.max(1, ...values));
</script>

<svg class="dre-spark" viewBox="0 0 34 14" role="img" aria-label={label}>
  {#each values as value, i (i)}
    <rect
      x={i * 17 + 2}
      y={13 - (value / max) * 12}
      width="13"
      height={(value / max) * 12}
      rx="1"
    />
  {/each}
</svg>

<style>
  .dre-spark {
    flex: none;
    width: 2.125rem;
    height: 0.875rem;
    fill: var(--type-entity-term, var(--primary, #007a50));
    opacity: 0.78;
  }
  @media (prefers-color-scheme: dark) {
    .dre-spark {
      opacity: 0.95;
    }
  }
</style>
