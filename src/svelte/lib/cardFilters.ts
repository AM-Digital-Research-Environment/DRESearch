import { getContext, setContext } from 'svelte';
import { filterFieldLabel, t } from './i18n';
import type { ActiveFilters } from './types';

/**
 * The filter values on a result card — an author, a venue, a role chip — are
 * TOGGLES of the surface's filters: pressed while that value is one of the
 * active filters, and pressing one again removes it. They used to be add-only
 * controls with no state and no action in their name, so a screen-reader user
 * heard "Madore, Frédérick, button" with no hint of what it would do, and a
 * second press did nothing.
 *
 * The surface that owns the filters provides this once (ResultsList for a
 * corpus, MixedResultCard for a hit in the federated All tab), so every
 * FilterLink and FilterButton inside a card reads it directly instead of each
 * card threading the filters and a callback through to every value.
 */
export interface CardFilters {
  /** Whether `value` is among the active filters of `field`. */
  isActive(field: string, value: string): boolean;
  /** Add the filter, or remove it when it is active. */
  toggle(field: string, value: string): void;
  /** The field's name for the control's label ("Author", "Language"). */
  label(field: string): string;
}

const KEY = Symbol('dre-card-filters');

/** Make `filters` the card filters of everything rendered below (call during init). */
export function provideCardFilters(filters: CardFilters): void {
  setContext(KEY, filters);
}

/**
 * The enclosing surface's card filters. Outside any surface (a card rendered
 * on its own) a value is never active and pressing it does nothing.
 */
export function cardFilters(): CardFilters {
  return (
    getContext<CardFilters | undefined>(KEY) ?? {
      isActive: () => false,
      toggle: () => undefined,
      label: filterFieldLabel,
    }
  );
}

/**
 * Card filters over an ActiveFilters map read through `filters` (a getter, so
 * the pressed state follows the surface's state), labelled from the corpus's
 * facet labels, falling back to the field's generic name for a display field
 * that is not a sidebar facet.
 */
export function activeCardFilters(
  filters: () => ActiveFilters,
  labels: () => Record<string, string>,
  toggle: (field: string, value: string) => void,
): CardFilters {
  return {
    isActive: (field, value) => filters()[field]?.includes(value) ?? false,
    toggle,
    label: (field) => labels()[field] ?? filterFieldLabel(field),
  };
}

/**
 * What pressing the control will do, as its accessible name: "Filter by
 * Author: Madore, Frédérick", or "Remove filter Author: Madore, Frédérick"
 * while it is active. The visible value is part of it (WCAG 2.5.3, label in
 * name).
 */
export function filterToggleLabel(active: boolean, label: string, value: string): string {
  return t(active ? 'remove_filter' : 'add_filter', { label, value });
}
