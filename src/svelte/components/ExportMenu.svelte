<script module lang="ts">
  // Page-unique menu id for the trigger's aria-controls.
  let exportUid = 0;
  function nextExportMenuId(): string {
    return `dre-export-menu-${++exportUid}`;
  }
</script>

<script lang="ts">
  import type { ActiveFilters, CardKind, ExportResponse } from '../lib/types';
  import {
    download,
    exportFilename,
    serialize,
    EXPORT_FORMATS,
    EXPORT_MAX_HITS,
    type ExportFormat,
    type ExportMeta,
  } from '../lib/export';
  import { formatNumber, t } from '../lib/i18n';

  /**
   * "Export" disclosure in the results toolbar: a small outlined trigger (same
   * control vocabulary as SortSelect) opening a menu of download formats. Picking
   * one fetches the CURRENT result set (same query / filters / sort / year,
   * capped server-side), serializes client-side and triggers a file download —
   * the server only ships JSON, the is_public:=true constraint applies unchanged.
   */
  interface Props {
    /** Fetch the current result set's docs (capped) + the total found. */
    fetchDocs: () => Promise<ExportResponse>;
    /** The live query string, embedded in the export header metadata. */
    query: string;
    /** Total results of the current search — drives the cap hint. */
    found: number;
    /** Corpus kind — selects the citation mapping (publication vs item vs …). */
    kind: CardKind;
    /** Base for building each result's absolute Omeka item URL. */
    itemUrlBase: string;
    /** Active facet filters at export time — recorded in the export header. */
    filters: ActiveFilters;
    /** Active year-range bounds (null = unconstrained at that end). */
    yearFrom: number | null;
    yearTo: number | null;
    /** field => label, so the header reads "Type" not "type_s". */
    facetLabels: Record<string, string>;
  }

  const {
    fetchDocs,
    query,
    found,
    kind,
    itemUrlBase,
    filters,
    yearFrom,
    yearTo,
    facetLabels,
  }: Props = $props();

  const menuId = nextExportMenuId();

  let open = $state(false);
  let busy = $state(false);
  let error = $state<string | null>(null);
  let root: HTMLElement | null = $state(null);

  // Close when focus/clicks land outside the component.
  $effect(() => {
    if (!open) return;
    const onPointerDown = (e: PointerEvent): void => {
      if (root && !root.contains(e.target as Node)) {
        open = false;
      }
    };
    const onKeydown = (e: KeyboardEvent): void => {
      if (e.key === 'Escape') {
        open = false;
      }
    };
    window.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('keydown', onKeydown);
    return () => {
      window.removeEventListener('pointerdown', onPointerDown);
      window.removeEventListener('keydown', onKeydown);
    };
  });

  async function run(format: ExportFormat): Promise<void> {
    if (busy) return;
    busy = true;
    error = null;
    try {
      const res = await fetchDocs();
      if (!res.available || !res.complete || res.exported !== res.docs.length) {
        throw new Error(res.error?.message || t('search_unavailable'));
      }
      if (res.docs.length === 0) {
        error = t('export_empty');
        return;
      }
      const meta: ExportMeta = {
        query: query.trim(),
        found: res.found,
        filters,
        yearFrom,
        yearTo,
        facetLabels,
      };
      const spec = EXPORT_FORMATS.find((f) => f.format === format)!;
      download(
        exportFilename(spec.extension),
        spec.mime,
        serialize(format, res.docs, meta, kind, itemUrlBase),
      );
      open = false;
    } catch (e) {
      error = t('export_failed', { message: e instanceof Error ? e.message : String(e) });
    } finally {
      busy = false;
    }
  }
</script>

<div class="dre-export" bind:this={root}>
  <button
    type="button"
    class="dre-export__trigger"
    aria-haspopup="true"
    aria-expanded={open}
    aria-controls={open ? menuId : undefined}
    aria-label={t('export_results')}
    disabled={busy}
    onclick={() => (open = !open)}
  >
    <span class="dre-export__icon" aria-hidden="true">
      <svg
        xmlns="http://www.w3.org/2000/svg"
        width="16"
        height="16"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
      >
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
        <polyline points="7 10 12 15 17 10" />
        <line x1="12" x2="12" y1="15" y2="3" />
      </svg>
    </span>
    {busy ? t('exporting') : t('export')}
  </button>

  {#if open}
    <div class="dre-export__menu" id={menuId} role="menu" aria-label={t('export_results')}>
      {#each EXPORT_FORMATS as spec (spec.format)}
        <button
          type="button"
          class="dre-export__item"
          role="menuitem"
          disabled={busy}
          onclick={() => run(spec.format)}
        >
          {t(`export_${spec.format}`)}
        </button>
      {/each}
      {#if found > EXPORT_MAX_HITS}
        <p class="dre-export__hint">
          {t('export_limit', { n: formatNumber(EXPORT_MAX_HITS) })}
        </p>
      {/if}
      {#if error}
        <p class="dre-export__error" role="alert">{error}</p>
      {/if}
    </div>
  {/if}
</div>

<style>
  .dre-export {
    position: relative;
    display: inline-flex;
  }

  /*
   * Trigger mirrors the toolbar's control vocabulary (SortSelect): outlined,
   * surface background, primary on hover. The surface background + border below
   * override the native button chrome (the host theme no longer styles bare
   * <button>s, so no override fight is needed).
   */
  .dre-export__trigger {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    height: var(--size-control-md, 2.5rem);
    margin: 0;
    padding-inline: var(--space-md, 1rem);
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfcf9);
    color: var(--ink, #3c342d);
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    font-weight: 500;
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-export__trigger:hover {
    border-color: var(--primary, #007a50);
    color: var(--primary, #007a50);
    background: var(--surface, #fdfcf9);
  }
  .dre-export__trigger:focus-visible {
    outline: none;
    border-color: var(--primary, #007a50);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }
  .dre-export__trigger:disabled {
    opacity: 0.6;
    cursor: progress;
  }
  .dre-export__icon {
    display: inline-flex;
    align-items: center;
  }

  /* Floating format menu. */
  .dre-export__menu {
    position: absolute;
    inset-inline-end: 0;
    inset-block-start: calc(100% + var(--space-xs, 0.25rem));
    z-index: var(--z-dropdown, 100);
    min-width: 15rem;
    background: var(--surface, #fdfcf9);
    border: 1px solid var(--border, #dbd7d1);
    border-radius: var(--radius-md, 0.5rem);
    box-shadow:
      0 4px 12px rgba(0, 0, 0, 0.08),
      0 1px 3px rgba(0, 0, 0, 0.05);
    overflow: hidden;
  }
  .dre-export__item {
    display: block;
    width: 100%;
    margin: 0;
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    appearance: none;
    background: transparent;
    border: 0;
    border-bottom: 1px solid var(--border-light, #eae8e3);
    color: var(--ink, #3c342d);
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    text-align: start;
    cursor: pointer;
    transition: background var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .dre-export__item:last-of-type {
    border-bottom: none;
  }
  .dre-export__item:hover,
  .dre-export__item:focus-visible {
    background: color-mix(in srgb, var(--primary, #007a50) 8%, var(--surface, #fdfcf9));
    color: var(--ink, #3c342d);
    outline: none;
  }
  .dre-export__item:disabled {
    opacity: 0.6;
    cursor: progress;
  }
  .dre-export__hint,
  .dre-export__error {
    margin: 0;
    padding: var(--space-xs, 0.25rem) var(--space-md, 1rem) var(--space-sm, 0.5rem);
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #716a66);
    border-top: 1px solid var(--border-light, #eae8e3);
  }
  .dre-export__error {
    color: var(--error, #cc272e);
  }
</style>
