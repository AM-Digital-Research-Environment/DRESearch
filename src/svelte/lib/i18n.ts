/** Locale-aware UI dictionary. Deployments may inject translated key/value
 * overrides as `window.dreSearchTranslations` before the deferred bundle runs. */

const ENGLISH_STRINGS: Record<string, string> = {
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
  pagination: 'Pagination',
  previous_page: 'Previous page',
  next_page: 'Next page',

  // Result export menu.
  export: 'Export',
  exporting: 'Exporting…',
  export_results: 'Export results',
  export_filters_label: 'Filters',
  export_txt: 'Plain text (.txt)',
  export_json: 'JSON (.json)',
  export_ris: 'RIS — Zotero, EndNote (.ris)',
  export_bibtex: 'BibTeX (.bib)',
  export_limit: 'Exports the first {n} results of the current set.',
  export_empty: 'No results to export.',
  export_failed: 'Export failed: {message}',
  // Headers inside the exported .txt file.
  export_query_label: 'Search',
  export_browse_label: 'Browse (no query)',
  export_count_label: 'Exported results',

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
  result_types: 'Result types',

  untitled: 'Untitled',
  project_label: 'Project',
  suggestions: 'Suggestions',

  // Match highlighting — prefix for matches in a field the card doesn't show.
  matched_in: 'Matched in',
  field_title: 'Title',
  field_abstract: 'Abstract',
  field_description: 'Description',
  field_subject: 'Subject',
  field_tag: 'Tag',
  field_author: 'Author',
  field_editor: 'Editor',
  field_container: 'In',
  field_publisher: 'Publisher',
  field_keyword: 'Keyword',
  field_host: 'Host',
  field_guest: 'Guest',
  field_engineer: 'Sound engineer',
  field_series: 'Series',
  field_speaker: 'Speaker',
  field_playlist: 'Playlist',
  field_transcript: 'Transcript',
  field_pi: 'Principal investigator',
  field_member: 'Member',
  field_institution: 'Institution',
  field_section: 'Research section',
  field_people: 'Associated people',
  field_spokesperson: 'Spokesperson',
  field_affiliation: 'Affiliation',
  field_role: 'Role',
  field_language: 'Language',
  field_origin: 'Place of origin',
  field_provenance: 'Current location',
  field_project: 'Project',

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

declare global {
  interface Window {
    dreSearchTranslations?: Record<string, string>;
  }
}

let locale = typeof document !== 'undefined' ? document.documentElement.lang || 'en' : 'en';
let strings: Record<string, string> = {
  ...ENGLISH_STRINGS,
  ...(typeof window !== 'undefined' ? window.dreSearchTranslations : {}),
};
let numberFormatter = new Intl.NumberFormat(locale);
let pluralRules = new Intl.PluralRules(locale);

export function configureI18n(nextLocale: string, overrides: Record<string, string> = {}): void {
  locale = nextLocale.trim() || 'en';
  strings = { ...ENGLISH_STRINGS, ...overrides };
  numberFormatter = new Intl.NumberFormat(locale);
  pluralRules = new Intl.PluralRules(locale);
}

export function formatNumber(value: number): string {
  return numberFormatter.format(value);
}

/** Format ISO-like year/month/day values without applying the browser timezone. */
export function formatDate(raw: string | undefined): string {
  if (!raw) return '';
  const ymd = /^(\d{4})-(\d{2})-(\d{2})/.exec(raw);
  if (ymd) {
    const date = new Date(Date.UTC(Number(ymd[1]), Number(ymd[2]) - 1, Number(ymd[3])));
    return new Intl.DateTimeFormat(locale, {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      timeZone: 'UTC',
    }).format(date);
  }
  const ym = /^(\d{4})-(\d{2})/.exec(raw);
  if (ym) {
    const date = new Date(Date.UTC(Number(ym[1]), Number(ym[2]) - 1, 1));
    return new Intl.DateTimeFormat(locale, {
      month: 'short',
      year: 'numeric',
      timeZone: 'UTC',
    }).format(date);
  }
  const year = /^(\d{4})/.exec(raw);
  return year ? year[1] : raw;
}

/**
 * Friendly label for a searchable/filterable field, by its Typesense field name.
 * Used for the "Matched in" line and as a fallback label for active-filter chips
 * whose field isn't a sidebar facet (e.g. an author clicked on a result card).
 */
const MATCH_FIELD_KEYS: Record<string, string> = {
  title: 'field_title',
  abstract: 'field_abstract',
  description: 'field_description',
  subject_ss: 'field_subject',
  tag_ss: 'field_tag',
  creator_ss: 'field_author',
  author_ss: 'field_author',
  editor_ss: 'field_editor',
  container_ss: 'field_container',
  publisher_ss: 'field_publisher',
  keyword_ss: 'field_keyword',
  host_ss: 'field_host',
  guest_ss: 'field_guest',
  engineer_ss: 'field_engineer',
  series_s: 'field_series',
  speaker_ss: 'field_speaker',
  playlist_s: 'field_playlist',
  transcript: 'field_transcript',
  pi_ss: 'field_pi',
  member_ss: 'field_member',
  institution_ss: 'field_institution',
  section_ss: 'field_section',
  people_ss: 'field_people',
  spokesperson_ss: 'field_spokesperson',
  affiliation_ss: 'field_affiliation',
  roles_ss: 'field_role',
  language_ss: 'field_language',
  origin_ss: 'field_origin',
  provenance_ss: 'field_provenance',
  project_s: 'field_project',
};

export function matchFieldLabel(field: string): string {
  const key = MATCH_FIELD_KEYS[field];
  return key ? t(key) : field;
}

export function t(key: string, vars?: Record<string, string | number>): string {
  let str = strings[key] ?? key;
  if (vars) {
    for (const [name, value] of Object.entries(vars)) {
      str = str.replaceAll(`{${name}}`, String(value));
    }
  }
  return str;
}

/** Pluralised "{n} research items". */
export function researchItemsLabel(n: number): string {
  return t(pluralRules.select(n) === 'one' ? 'research_items_one' : 'research_items_other', {
    n: formatNumber(n),
  });
}

/** Pluralised "{n} publications". */
export function publicationsLabel(n: number): string {
  return t(pluralRules.select(n) === 'one' ? 'publications_one' : 'publications_other', {
    n: formatNumber(n),
  });
}

/** Pluralised "{n} members". */
export function membersLabel(n: number): string {
  return t(pluralRules.select(n) === 'one' ? 'members_one' : 'members_other', {
    n: formatNumber(n),
  });
}

/** Pluralised "{n} projects". */
export function projectsLabel(n: number): string {
  return t(pluralRules.select(n) === 'one' ? 'projects_one' : 'projects_other', {
    n: formatNumber(n),
  });
}

/** Pluralised "{n} people". */
export function peopleLabel(n: number): string {
  return t(pluralRules.select(n) === 'one' ? 'people_one' : 'people_other', { n: formatNumber(n) });
}
