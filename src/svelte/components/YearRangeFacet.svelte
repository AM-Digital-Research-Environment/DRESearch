<script lang="ts">
  import { t } from '../lib/i18n';

  /**
   * Dual-handle year range slider, styled to sit among the facet groups.
   * Controlled: the parent owns the value (so "Clear all" can reset it); the
   * component keeps a local copy for smooth dragging and emits a debounced
   * onChange. Dependency-free — two overlaid range inputs with a coloured fill.
   */

  interface Props {
    min: number;
    max: number;
    from: number;
    to: number;
    onChange: (from: number, to: number) => void;
  }

  const { min, max, from, to, onChange }: Props = $props();

  // svelte-ignore state_referenced_locally
  let lo = $state(from);
  // svelte-ignore state_referenced_locally
  let hi = $state(to);
  // Plain (non-reactive) trackers so the sync effect depends only on the props,
  // never on lo/hi — otherwise dragging would fight the reset.
  // svelte-ignore state_referenced_locally
  let lastFrom = from;
  // svelte-ignore state_referenced_locally
  let lastTo = to;
  let timer: number | null = null;

  $effect(() => {
    if (from !== lastFrom) {
      lastFrom = from;
      lo = from;
    }
    if (to !== lastTo) {
      lastTo = to;
      hi = to;
    }
  });

  const span = $derived(Math.max(1, max - min));
  const pctLo = $derived(((lo - min) / span) * 100);
  const pctHi = $derived(((hi - min) / span) * 100);

  // When the handles meet, only the top one is grabbable. Keep the low handle on
  // top when the pair sits at the ceiling (so you can drag it back down), and the
  // high handle on top otherwise (so a pair stuck at the floor can be pulled up).
  const loOnTop = $derived(hi >= max);

  let open = $state(true);

  function emit(): void {
    if (timer !== null) {
      clearTimeout(timer);
    }
    timer = window.setTimeout(() => {
      timer = null;
      onChange(lo, hi);
    }, 200);
  }

  function onLo(e: Event): void {
    const input = e.currentTarget as HTMLInputElement;
    const v = Number(input.value);
    lo = Math.min(v, hi);
    // If the drag pushed past the high handle, the clamped state is unchanged,
    // so Svelte won't re-sync the native input — snap its DOM value back here so
    // the low thumb can never visually cross over the high one.
    if (v !== lo) {
      input.value = String(lo);
    }
    emit();
  }

  /**
   * Typed years commit on change (Enter or leaving the field): dragging a
   * 1500–2026 slider one year at a time is no way to reach an exact year.
   * Clamped to the bounds and kept in order; an empty or invalid entry
   * restores the current value.
   */
  function onTyped(which: 'lo' | 'hi', e: Event): void {
    const input = e.currentTarget as HTMLInputElement;
    const v = Math.round(Number(input.value));
    if (input.value.trim() === '' || !Number.isFinite(v)) {
      input.value = String(which === 'lo' ? lo : hi);
      return;
    }
    const clamped = Math.min(max, Math.max(min, v));
    if (which === 'lo') {
      lo = Math.min(clamped, hi);
      input.value = String(lo);
    } else {
      hi = Math.max(clamped, lo);
      input.value = String(hi);
    }
    if (timer !== null) clearTimeout(timer);
    timer = null;
    onChange(lo, hi);
  }

  function onHi(e: Event): void {
    const input = e.currentTarget as HTMLInputElement;
    const v = Number(input.value);
    hi = Math.max(v, lo);
    // Likewise keep the high thumb from crossing below the low handle.
    if (v !== hi) {
      input.value = String(hi);
    }
    emit();
  }
</script>

<section class="dre-yr">
  <button type="button" class="dre-yr__heading" aria-expanded={open} onclick={() => (open = !open)}>
    <span class="dre-yr__label">{t('year_label')}</span>
    {#if lo > min || hi < max}
      <span class="dre-yr__badge">{lo}–{hi}</span>
    {/if}
    <span class="dre-yr__chevron" aria-hidden="true">{open ? '▾' : '▸'}</span>
  </button>

  {#if open}
    <div class="dre-yr__body">
      <div class="dre-yr__values">
        <label>
          <span class="dre-yr__sr">{t('year_from')}</span>
          <input
            class="dre-yr__number"
            type="number"
            inputmode="numeric"
            {min}
            {max}
            step="1"
            value={lo}
            onchange={(e) => onTyped('lo', e)}
          />
        </label>
        <span aria-hidden="true">–</span>
        <label>
          <span class="dre-yr__sr">{t('year_to')}</span>
          <input
            class="dre-yr__number"
            type="number"
            inputmode="numeric"
            {min}
            {max}
            step="1"
            value={hi}
            onchange={(e) => onTyped('hi', e)}
          />
        </label>
      </div>
      <div class="dre-yr__slider">
        <div class="dre-yr__track"></div>
        <div class="dre-yr__fill" style="left:{pctLo}%; right:{100 - pctHi}%"></div>
        <input
          class="dre-yr__input dre-yr__input--lo"
          type="range"
          {min}
          {max}
          step="1"
          value={lo}
          style="z-index: {loOnTop ? 4 : 2}"
          aria-label={`${t('year_from')} (${min}–${max})`}
          oninput={onLo}
        />
        <input
          class="dre-yr__input dre-yr__input--hi"
          type="range"
          {min}
          {max}
          step="1"
          value={hi}
          style="z-index: {loOnTop ? 2 : 4}"
          aria-label={`${t('year_to')} (${min}–${max})`}
          oninput={onHi}
        />
      </div>
    </div>
  {/if}
</section>

<style>
  .dre-yr__number {
    width: 5.5rem;
    min-height: var(--size-control-lg, 2.75rem);
    padding-inline: var(--space-sm, 0.5rem);
    border: 1px solid var(--field-border, #8b857f);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfcf9);
    color: var(--ink, #3c342d);
    font: inherit;
    /* At least 16px, or iOS Safari zooms the page when the field takes focus. */
    font-size: var(--text-base, 1.0625rem);
    font-variant-numeric: tabular-nums;
  }
  .dre-yr__number:focus {
    /* The theme's field focus (DRE-theme base/elements/_fields.scss): the ring is
       a box-shadow, which forced-colors mode drops, so the outline stays —
       transparent — and is painted in the system focus colour there. */
    outline: 2px solid transparent;
    border-color: var(--primary, #007a50);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }
  .dre-yr__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
  }
  .dre-yr {
    padding-block: var(--space-md, 1rem);
    border-bottom: 1px solid var(--border-light, #eae8e3);
  }
  .dre-yr__heading {
    display: flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    width: 100%;
    padding: 0;
    /* Reset the native button chrome — this toggle reads as plain text. */
    background: none;
    border: none;
    cursor: pointer;
    font: inherit;
    color: var(--ink-strong, #261d15);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    letter-spacing: var(--tracking-wide, 0.04em);
    text-transform: uppercase;
    text-align: start;
  }
  .dre-yr__heading:hover {
    color: var(--primary, #007a50);
  }
  .dre-yr__label {
    flex: 1;
  }
  .dre-yr__badge {
    background: var(--primary, #007a50);
    color: var(--primary-contrast, #fcfcf9);
    border-radius: var(--radius-full, 9999px);
    padding: 0 var(--space-2, 0.5rem);
    height: 1.25rem;
    display: inline-flex;
    align-items: center;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: var(--tracking-normal, 0);
    font-variant-numeric: tabular-nums;
  }
  .dre-yr__chevron {
    color: var(--muted, #716a66);
    font-size: var(--text-xs, 0.8125rem);
  }

  .dre-yr__body {
    margin-top: var(--space-sm, 0.5rem);
  }
  .dre-yr__values {
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: var(--muted, #716a66);
    font-size: var(--text-xs, 0.8125rem);
    font-variant-numeric: tabular-nums;
    margin-bottom: var(--space-xs, 0.25rem);
  }

  .dre-yr__slider {
    position: relative;
    height: 1.5rem;
  }
  .dre-yr__track,
  .dre-yr__fill {
    position: absolute;
    top: 50%;
    height: 4px;
    transform: translateY(-50%);
    border-radius: var(--radius-full, 9999px);
  }
  .dre-yr__track {
    left: 0;
    right: 0;
    background: var(--border, #dbd7d1);
  }
  .dre-yr__fill {
    background: var(--primary, #007a50);
  }

  .dre-yr__input {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    margin: 0;
    /* Neutralise the host theme's generic form-control box: DRE-theme styles
       `input[type=range]` with a border, padding and radius, which would draw
       an input-field outline around the slider and inset the native track. */
    padding: 0;
    border: none;
    border-radius: 0;
    box-shadow: none;
    background: none;
    pointer-events: none;
    -webkit-appearance: none;
    appearance: none;
  }
  /* Theme also adds a focus border + ring on the input itself; keep focus on
     the thumb only. */
  .dre-yr__input:focus,
  .dre-yr__input:focus-visible {
    outline: 2px solid var(--focus-color, #007a50);
    outline-offset: 2px;
    border: none;
    box-shadow: none;
  }
  /* z-index is set inline (dynamic) so whichever handle needs to move stays on
     top when the two meet — see `loOnTop`. */
  .dre-yr__input::-webkit-slider-runnable-track {
    background: none;
    border: none;
  }
  .dre-yr__input::-moz-range-track {
    background: none;
    border: none;
  }
  .dre-yr__input::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    pointer-events: auto;
    width: 1rem;
    height: 1rem;
    border-radius: 50%;
    background: var(--surface, #fdfcf9);
    border: 2px solid var(--primary, #007a50);
    cursor: pointer;
    margin-top: -0.375rem;
  }
  .dre-yr__input::-moz-range-thumb {
    pointer-events: auto;
    width: 1rem;
    height: 1rem;
    border-radius: 50%;
    background: var(--surface, #fdfcf9);
    border: 2px solid var(--primary, #007a50);
    cursor: pointer;
  }
  .dre-yr__input:focus-visible::-webkit-slider-thumb {
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }
  .dre-yr__input:focus-visible::-moz-range-thumb {
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 122, 80, 0.32));
  }
</style>
