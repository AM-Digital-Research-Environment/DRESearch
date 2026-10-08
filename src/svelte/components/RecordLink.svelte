<script lang="ts">
  import { t } from '../lib/i18n';

  /**
   * A compact "open this record" link placed beside a value that is also a
   * filter (a PI name, a podcast series, a playlist). The value itself narrows
   * the results; this arrow goes to the linked Omeka record. Rendered only
   * when the index carries the record's id.
   */
  interface Props {
    itemUrlBase: string;
    id: string | undefined;
    /** What the link opens, for its accessible name ("Open Vierke, Ulf"). */
    name: string;
  }

  const { itemUrlBase, id, name }: Props = $props();
</script>

{#if id}
  <a
    class="dre-record-link"
    href={`${itemUrlBase}/${encodeURIComponent(id)}`}
    aria-label={t('open_record', { name })}
    title={t('open_record', { name })}
    ><svg viewBox="0 0 20 20" width="14" height="14" aria-hidden="true"
      ><path
        d="M8 4H4.5A1.5 1.5 0 0 0 3 5.5v10A1.5 1.5 0 0 0 4.5 17h10a1.5 1.5 0 0 0 1.5-1.5V12M11 3h6v6M17 3 9 11"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
      /></svg
    ></a
  >
{/if}

<style>
  .dre-record-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    /* WCAG 2.2 target size (AA): at least 24×24 CSS px. */
    min-width: 1.5rem;
    min-height: 1.5rem;
    margin-inline-start: 0.1rem;
    vertical-align: middle;
    border-radius: var(--radius-sm, 0.375rem);
    color: var(--muted, #716a66);
  }
  .dre-record-link:hover {
    color: var(--primary, #007a50);
  }
  .dre-record-link:focus-visible {
    outline: 2px solid var(--focus-color, #007a50);
    outline-offset: 2px;
  }
</style>
