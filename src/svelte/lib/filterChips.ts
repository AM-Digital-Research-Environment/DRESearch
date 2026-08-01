import { matchFieldLabel, t } from './i18n';
import type { ActiveFilters } from './types';

export interface FilterChipModel {
  id: string;
  field: string;
  value: string;
  label: string;
  kind: 'facet' | 'year' | 'query';
}

export function buildFilterChips(
  filters: ActiveFilters,
  labels: Record<string, string>,
  yearFrom: number | null = null,
  yearTo: number | null = null,
  query = '',
): FilterChipModel[] {
  const chips: FilterChipModel[] = [];
  if (query.trim()) {
    chips.push({
      id: 'query',
      field: '$query',
      value: query.trim(),
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
        label: labels[field] ?? matchFieldLabel(field),
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
    chips.push({ id: 'year', field: '$year', value, label: t('year_label'), kind: 'year' });
  }
  return chips;
}
