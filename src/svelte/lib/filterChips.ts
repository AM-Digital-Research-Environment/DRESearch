import { filterFieldLabel, t } from './i18n';
import type { ActiveFilters, Doc } from './types';

export interface FilterChipModel {
  id: string;
  field: string;
  /** The stored filter value, which removing the chip clears. */
  value: string;
  /** What the chip shows: the value, or the label a key-field id stands for. */
  display: string;
  label: string;
  kind: 'facet' | 'year' | 'query';
}

/** field => stored value => what a chip shows for it. */
export type ValueLabels = Record<string, Record<string, string>>;

/**
 * Filter keys whose values are ids, not something to read: each names the
 * document field holding the label its chip shows instead. A link can filter
 * on a project's item id (`f.project_id_s=1797`), which survives a title
 * change, while its chip still reads the project's title (#45).
 */
const KEY_FIELDS: Record<string, keyof Doc> = {
  project_id_s: 'project_s',
};

/**
 * Add the labels the hits carry for key-field values to `known`, in place, and
 * return a copy. `known` outlives a response, so a chip keeps its title when a
 * later search finds nothing.
 */
export function learnValueLabels(known: ValueLabels, hits: readonly Doc[]): ValueLabels {
  for (const [field, labelField] of Object.entries(KEY_FIELDS)) {
    for (const hit of hits) {
      const value = hit[field as keyof Doc];
      const label = hit[labelField];
      if (typeof value === 'string' && value !== '' && typeof label === 'string' && label !== '') {
        (known[field] ??= {})[value] = label;
      }
    }
  }
  return Object.fromEntries(Object.entries(known).map(([field, map]) => [field, { ...map }]));
}

export function buildFilterChips(
  filters: ActiveFilters,
  labels: Record<string, string>,
  yearFrom: number | null = null,
  yearTo: number | null = null,
  query = '',
  valueLabels: ValueLabels = {},
): FilterChipModel[] {
  const chips: FilterChipModel[] = [];
  if (query.trim()) {
    chips.push({
      id: 'query',
      field: '$query',
      value: query.trim(),
      display: query.trim(),
      label: t('search_scope'),
      kind: 'query',
    });
  }
  for (const [field, values] of Object.entries(filters)) {
    for (const value of values) {
      chips.push({
        id: `${field}|${value}`,
        field,
        value,
        // An id the hits have not named yet shows as itself.
        display: valueLabels[field]?.[value] ?? value,
        label: labels[field] ?? filterFieldLabel(field),
        kind: 'facet',
      });
    }
  }
  if (yearFrom !== null || yearTo !== null) {
    const value =
      yearFrom !== null && yearTo !== null
        ? `${yearFrom}–${yearTo}`
        : yearFrom !== null
          ? `${yearFrom}+`
          : `≤ ${yearTo}`;
    chips.push({
      id: 'year',
      field: '$year',
      value,
      display: value,
      label: t('year_label'),
      kind: 'year',
    });
  }
  return chips;
}
