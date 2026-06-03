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
      <div class="dre-yr__values" aria-hidden="true">
        <span>{lo}</span>
        <span>{hi}</span>
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
  .dre-yr {
    padding-block: var(--space-md, 1rem);
    border-bottom: 1px solid var(--border-light, #eee);
  }
  .dre-yr__heading {
    display: flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    width: 100%;
    padding: 0;
    /* Host theme styles every <button> as a filled primary button; without
       these the toggle fills with green on hover. */
    background: none !important;
    box-shadow: none !important;
    transform: none !important;
    border: none;
    cursor: pointer;
    font: inherit;
    color: var(--ink-strong, var(--ink, #222));
    font-size: var(--text-xs, 0.75rem);
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    text-align: start;
  }
  .dre-yr__heading:hover {
    color: var(--primary, #2a4d8f) !important;
  }
  .dre-yr__label {
    flex: 1;
  }
  .dre-yr__badge {
    background: var(--primary, #2a4d8f);
    color: var(--primary-contrast, #fff);
    border-radius: var(--radius-full, 9999px);
    padding: 0 0.45rem;
    height: 1.25rem;
    display: inline-flex;
    align-items: center;
    font-size: var(--text-xs, 0.7rem);
    font-weight: 600;
    letter-spacing: 0;
    font-variant-numeric: tabular-nums;
  }
  .dre-yr__chevron {
    color: var(--muted, #888);
    font-size: var(--text-xs, 0.75rem);
  }

  .dre-yr__body {
    margin-top: var(--space-sm, 0.5rem);
  }
  .dre-yr__values {
    display: flex;
    justify-content: space-between;
    color: var(--muted, #666);
    font-size: var(--text-xs, 0.75rem);
    font-variant-numeric: tabular-nums;
    margin-bottom: 0.25rem;
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
    background: var(--border, #ccc);
  }
  .dre-yr__fill {
    background: var(--primary, #2a4d8f);
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
    outline: none;
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
    background: var(--surface, #fff);
    border: 2px solid var(--primary, #2a4d8f);
    cursor: pointer;
    margin-top: -0.375rem;
  }
  .dre-yr__input::-moz-range-thumb {
    pointer-events: auto;
    width: 1rem;
    height: 1rem;
    border-radius: 50%;
    background: var(--surface, #fff);
    border: 2px solid var(--primary, #2a4d8f);
    cursor: pointer;
  }
  .dre-yr__input:focus-visible::-webkit-slider-thumb {
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(42, 77, 143, 0.3));
  }
  .dre-yr__input:focus-visible::-moz-range-thumb {
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(42, 77, 143, 0.3));
  }
</style>
