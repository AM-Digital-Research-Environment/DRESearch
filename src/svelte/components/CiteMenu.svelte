<script lang="ts">
  import type { CardKind, Doc } from '../lib/types';
  import { t } from '../lib/i18n';
  import '../styles/buttons.css';
  import { download, serialize, type ExportFormat, type ExportMeta } from '../lib/export';
  import { announce, COPY_FEEDBACK_MS, surfaceAnnouncer } from '../lib/announce';
  import { detailsPopover } from '../lib/popover';

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

  // The copied button reads "Copied" for two seconds and the theme's shared
  // status region (else the results' status node) says so; `status` is the
  // last resort, for a menu rendered outside any search surface.
  const surface = surfaceAnnouncer();
  let copiedFormat = $state<ExportFormat | null>(null);
  let status = $state('');
  let timer: number | undefined;
  $effect(() => () => window.clearTimeout(timer));

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
    copiedFormat = format;
    if (!announce(t('copied'), surface)) status = t('copied');
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
      copiedFormat = null;
      status = '';
    }, COPY_FEEDBACK_MS);
  }

  function save(): void {
    download(
      `dre-${doc.id}.ris`,
      'application/x-research-info-systems;charset=utf-8',
      citation('ris'),
    );
  }
</script>

<details class="dre-cite" use:detailsPopover>
  <summary>{t('cite')}</summary>
  <div class="dre-cite__actions" role="group" aria-label={t('cite')}>
    <button type="button" class="dre-button-secondary" onclick={() => copy('bibtex')}
      >{copiedFormat === 'bibtex' ? t('copied') : t('copy_bibtex')}</button
    >
    <button type="button" class="dre-button-secondary" onclick={() => copy('ris')}
      >{copiedFormat === 'ris' ? t('copied') : t('copy_ris')}</button
    >
    <button type="button" class="dre-button-secondary" onclick={save}>{t('download_ris')}</button>
  </div>
  {#if !surface}<p class="dre-cite__status" role="status">{status}</p>{/if}
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
  .dre-cite summary:focus-visible {
    outline: 2px solid var(--focus-color, #007a50);
    outline-offset: 2px;
  }
  .dre-cite__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
    margin-block-start: var(--space-xs, 0.25rem);
  }
  /* The shared secondary button (styles/buttons.css), at the card's size. */
  .dre-cite button {
    padding-inline: var(--space-sm, 0.5rem);
    font-size: var(--text-xs, 0.8125rem);
  }
  /* Spoken only: the button label already shows "Copied". */
  .dre-cite__status {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: 0;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
  }
</style>
