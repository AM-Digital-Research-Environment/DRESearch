/**
 * English-only UI strings. Kept as a flat dictionary with a tiny {var}
 * interpolator so adding French/German later is just another dictionary +
 * a locale switch — no structural change.
 */

const STRINGS: Record<string, string> = {
  search_placeholder: 'Search research items…',
  search_placeholder_project: 'Search research projects…',
  search_placeholder_publication: 'Search publications…',
  clear_search: 'Clear search',

  filters: 'Filters',
  clear_all: 'Clear all',
  active_filters: 'Active filters',
  remove_filter: 'Remove filter {label}: {value}',
  show_more: 'Show {n} more',
  show_less: 'Show less',
  search_to_see_options: 'No filter options for the current results.',

  sort_label: 'Sort',
  sort_relevance: 'Relevance',
  sort_newest: 'Newest first',
  sort_oldest: 'Oldest first',
  sort_title: 'Title (A–Z)',

  searching: 'Searching…',
  result_one: 'result',
  result_other: 'results',

  no_results_title: 'No results found',
  try_removing_filter: 'Try removing a filter or broadening your search.',
  clear_all_filters: 'Clear all filters',
  try_broader_query: 'Try a different or broader search term.',
  corpus_empty: 'Nothing to show yet.',

  search_unavailable: 'Search is unavailable',
  search_unavailable_hint: 'The search service is not reachable right now.',

  untitled: 'Untitled',
  project_label: 'Project',
  suggestions: 'Suggestions',

  // Year range slider.
  year_label: 'Year',
  year_from: 'From',
  year_to: 'To',

  // Project card.
  pi_label: 'PI',
  research_items_one: '{n} research item',
  research_items_other: '{n} research items',

  // Publication card.
  in_prefix: 'In:',
  ed_short: 'ed.',
  eds_short: 'eds.',
  vol_short: 'vol.',
  no_short: 'no.',
  pp_short: 'pp.',
  doi_label: 'DOI',
};

export function t(key: string, vars?: Record<string, string | number>): string {
  let str = STRINGS[key] ?? key;
  if (vars) {
    for (const [name, value] of Object.entries(vars)) {
      str = str.replaceAll(`{${name}}`, String(value));
    }
  }
  return str;
}

/** Pluralised "{n} research items". */
export function researchItemsLabel(n: number): string {
  return t(n === 1 ? 'research_items_one' : 'research_items_other', { n: n.toLocaleString() });
}
