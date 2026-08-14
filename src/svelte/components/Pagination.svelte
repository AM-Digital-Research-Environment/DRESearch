<script lang="ts">
  import { t } from '../lib/i18n';
  interface Props {
    found: number;
    page: number;
    perPage: number;
    onPageChange: (next: number) => void;
  }
  const { found, page, perPage, onPageChange }: Props = $props();
  const total = $derived(Math.min(100, Math.max(1, Math.ceil(found / Math.max(1, perPage)))));
  const pages = $derived.by(() => {
    const out: number[] = [];
    for (let n = Math.max(1, page - 2); n <= Math.min(total, page + 2); n++) out.push(n);
    return out;
  });
  function go(next: number): void {
    if (next >= 1 && next <= total && next !== page) onPageChange(next);
  }
</script>

{#if total > 1}<nav class="dre-pager" aria-label={t('pagination')}>
    <button
      type="button"
      disabled={page <= 1}
      aria-label={t('previous_page')}
      onclick={() => go(page - 1)}>‹</button
    >
    {#if (pages[0] ?? 1) > 1}<button type="button" onclick={() => go(1)}>1</button
      >{#if (pages[0] ?? 1) > 2}<span aria-hidden="true">…</span>{/if}{/if}
    {#each pages as value (value)}<button
        type="button"
        class:active={value === page}
        aria-current={value === page ? 'page' : undefined}
        onclick={() => go(value)}>{value}</button
      >{/each}
    {#if (pages.at(-1) ?? 1) < total}{#if (pages.at(-1) ?? 1) < total - 1}<span aria-hidden="true"
          >…</span
        >{/if}<button type="button" onclick={() => go(total)}>{total}</button>{/if}
    <button
      type="button"
      disabled={page >= total}
      aria-label={t('next_page')}
      onclick={() => go(page + 1)}>›</button
    >
  </nav>{/if}

<style>
  .dre-pager {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 0.25rem;
    margin-top: 1rem;
  }
  .dre-pager button {
    min-width: 2.25rem;
    height: 2.25rem;
    margin: 0;
    padding: 0 0.5rem;
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfcf9);
    color: var(--ink, #3c342d);
    font: inherit;
    cursor: pointer;
  }
  .dre-pager button:hover:not(:disabled):not(.active) {
    border-color: var(--primary, #007a50);
    color: var(--primary, #007a50);
  }
  .dre-pager button.active {
    background: var(--primary, #007a50);
    border-color: var(--primary, #007a50);
    color: var(--primary-contrast, #fcfcf9);
    font-weight: 700;
  }
  .dre-pager button:disabled {
    opacity: 0.45;
    cursor: default;
  }
  .dre-pager span {
    color: var(--muted, #716a66);
  }
</style>
