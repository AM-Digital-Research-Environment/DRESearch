<script lang="ts">
  import type { Snippet } from 'svelte';
  import { cardFilters, filterToggleLabel } from '../lib/cardFilters';

  /**
   * A card chip that filters by its value — a project, section, institution,
   * role, type, phase, series, playlist or keyword. A real <button> (a chip is
   * a box of its own, unlike FilterLink's values inside running text) and a
   * toggle (lib/cardFilters.ts): pressed while the value is an active filter,
   * named for what pressing it does. The look is the card stylesheet's chip
   * (styles/card.css), chosen by `class`.
   */

  interface Props {
    field: string;
    value: string;
    /** The chip's classes, e.g. "dre-shell__chip dre-shell__chip--role". */
    class: string;
    children: Snippet;
  }

  const { field, value, class: className, children }: Props = $props();
  const filters = cardFilters();
  const active = $derived(filters.isActive(field, value));
  const label = $derived(filterToggleLabel(active, filters.label(field), value));
</script>

<button
  type="button"
  data-print
  class={className}
  aria-pressed={active}
  aria-label={label}
  onclick={() => filters.toggle(field, value)}>{@render children()}</button
>
