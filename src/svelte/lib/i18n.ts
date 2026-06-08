/**
 * English-only UI strings. Kept as a flat dictionary with a tiny {var}
 * interpolator so adding French/German later is just another dictionary +
 * a locale switch — no structural change.
 */

const STRINGS: Record<string, string> = {
  search_placeholder: 'Search research items…',
  search_placeholder_project: 'Search research projects…',
  search_placeholder_publication: 'Search publications…',
  search_placeholder_podcast: 'Search podcasts…',
  search_placeholder_video: 'Search YouTube videos…',
  search_placeholder_person: 'Search people…',
  search_placeholder_section: 'Search research sections…',
  search_placeholder_organisation: 'Search organisations…',
  search_placeholder_term: 'Search terms…',
  clear_search: 'Clear search',

  filters: 'Filters',
  clear_all: 'Clear all',
  active_filters: 'Active filters',
  remove_filter: 'Remove filter {label}: {value}',
  show_more: 'Show {n} more',
  show_less: 'Show less',
  search_to_see_options: 'No filter options for the current results.',
  facet_search_placeholder: 'Search {label}…',
  facet_no_matches: 'No matches',
  show_filters: 'Filters',
  hide_filters: 'Hide filters',

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

  // Federated header bar + results page.
  search_all_placeholder: 'Search everything…',
  see_all_results: 'See all results for “{q}”',
  no_matches_anywhere: 'No matches in any collection.',
  search_results_for: 'Results for “{q}”',

  untitled: 'Untitled',
  project_label: 'Project',
  suggestions: 'Suggestions',

  // Match highlighting — prefix for matches in a field the card doesn't show.
  matched_in: 'Matched in',

  // Research-item card.
  origin_label: 'Place of origin',
  current_location_label: 'Current location',
  language_label: 'Language',

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

  // Podcast card.
  episode_label: 'Episode {n}',
  host_label: 'Host',
  guest_label: 'Guest',
  engineer_label: 'Sound engineer',
  listen_label: 'Listen',
  transcript_label: 'Transcript',

  // YouTube-video card.
  speaker_label: 'Speaker',
  watch_label: 'Watch',

  // Person card.
  publications_one: '{n} publication',
  publications_other: '{n} publications',

  // Research-section card.
  pis_label: 'PIs',
  spokesperson_label: 'Spokesperson',
  members_one: '{n} member',
  members_other: '{n} members',
  projects_one: '{n} project',
  projects_other: '{n} projects',

  // Organisation card.
  people_one: '{n} person',
  people_other: '{n} people',
};

/**
 * Friendly label for a searchable/filterable field, by its Typesense field name.
 * Used for the "Matched in" line and as a fallback label for active-filter chips
 * whose field isn't a sidebar facet (e.g. an author clicked on a result card).
 */
const MATCH_FIELD_LABELS: Record<string, string> = {
  title: 'Title',
  abstract: 'Abstract',
  description: 'Description',
  subject_ss: 'Subject',
  tag_ss: 'Tag',
  creator_ss: 'Author',
  author_ss: 'Author',
  editor_ss: 'Editor',
  container_ss: 'In',
  publisher_ss: 'Publisher',
  keyword_ss: 'Keyword',
  host_ss: 'Host',
  guest_ss: 'Guest',
  engineer_ss: 'Sound engineer',
  series_s: 'Series',
  speaker_ss: 'Speaker',
  playlist_s: 'Playlist',
  transcript: 'Transcript',
  pi_ss: 'Principal investigator',
  member_ss: 'Member',
  institution_ss: 'Institution',
  section_ss: 'Research section',
  people_ss: 'Associated people',
  spokesperson_ss: 'Spokesperson',
  affiliation_ss: 'Affiliation',
  roles_ss: 'Role',
  language_ss: 'Language',
  origin_ss: 'Place of origin',
  provenance_ss: 'Current location',
  project_s: 'Project',
};

export function matchFieldLabel(field: string): string {
  return MATCH_FIELD_LABELS[field] ?? field;
}

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

/** Pluralised "{n} publications". */
export function publicationsLabel(n: number): string {
  return t(n === 1 ? 'publications_one' : 'publications_other', { n: n.toLocaleString() });
}

/** Pluralised "{n} members". */
export function membersLabel(n: number): string {
  return t(n === 1 ? 'members_one' : 'members_other', { n: n.toLocaleString() });
}

/** Pluralised "{n} projects". */
export function projectsLabel(n: number): string {
  return t(n === 1 ? 'projects_one' : 'projects_other', { n: n.toLocaleString() });
}

/** Pluralised "{n} people". */
export function peopleLabel(n: number): string {
  return t(n === 1 ? 'people_one' : 'people_other', { n: n.toLocaleString() });
}
