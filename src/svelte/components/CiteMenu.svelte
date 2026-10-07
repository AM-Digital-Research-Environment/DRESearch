<script lang="ts">
  import type { CardKind, Doc } from '../lib/types';
  import { t } from '../lib/i18n';
  import { download, serialize, type ExportFormat, type ExportMeta } from '../lib/export';

  /**
   * "Cite" for one record: the export serializers applied to a single
   * document, copied to the clipboard or downloaded. A disclosure (summary +
   * buttons), so it stays keyboard-operable without menu semantics.
   */
  interface Props {
    doc: Doc;
    kind: CardKind;
    itemUrlBase: string;
  }

  const { doc, kind, itemUrlBase }: Props = $props();

  let status = $state('');

  const meta: ExportMeta = {
    query: '',
    found: 1,
    filters: {},
    yearFrom: null,
    yearTo: null,
    facetLabels: {},
  };

  // As serialized: trimming would drop the space RIS requires after "ER  -".
  function citation(format: ExportFormat): string {
    return serialize(format, [doc], meta, kind, itemUrlBase);
  }

  async function copy(format: ExportFormat): Promise<void> {
    const text = citation(format);
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      const area = document.createElement('textarea');
      area.value = text;
      document.body.append(area);
      area.select();
      document.execCommand('copy');
      area.remove();
    }
    status = t('citation_copied');
    window.setTimeout(() => (status = ''), 1800);
  }

  function save(): void {
    download(
      `dre-${doc.id}.ris`,
      'application/x-research-info-systems;charset=utf-8',
      citation('ris'),
    );
  }
</script>

<details class="dre-cite">
  <summary>{t('cite')}</summary>
  <div class="dre-cite__actions" role="group" aria-label={t('cite')}>
    <button type="button" onclick={() => copy('bibtex')}>{t('copy_bibtex')}</button>
    <button type="button" onclick={() => copy('ris')}>{t('copy_ris')}</button>
    <button type="button" onclick={save}>{t('download_ris')}</button>
  </div>
  <p class="dre-cite__status" role="status" aria-live="polite">{status}</p>
</details>

<style>
  .dre-cite {
    display: inline-block;
    font-size: var(--text-xs, 0.8125rem);
  }
  .dre-cite summary {
    display: inline-flex;
    align-items: center;
    min-height: 1.5rem;
    color: var(--primary, #007a50);
    cursor: pointer;
  }
  .dre-cite summary:focus-visible,
  .dre-cite button:focus-visible {
    outline: 2px solid var(--primary, #007a50);
    outline-offset: 2px;
  }
  .dre-cite__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
    margin-block-start: var(--space-xs, 0.25rem);
  }
  .dre-cite button {
    min-height: var(--size-control-lg, 2.75rem);
    margin: 0;
    padding: var(--space-xs, 0.25rem) var(--space-sm, 0.5rem);
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfcf9) !important;
    color: var(--ink, #3c342d);
    font: inherit;
    cursor: pointer;
    box-shadow: none !important;
  }
  .dre-cite button:hover {
    border-color: var(--primary, #007a50);
    color: var(--primary, #007a50);
  }
  .dre-cite__status {
    margin: 0;
    min-height: 1em;
    color: var(--muted, #716a66);
  }
</style>
