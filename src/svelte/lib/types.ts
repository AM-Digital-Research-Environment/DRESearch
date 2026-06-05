/**
 * Client-side types. These mirror the JSON the PHP layer emits:
 *   - Bootstrap     : the per-block <script type="application/json"> blob
 *   - SearchResponse: what SearchProxy::search() returns
 *   - Suggestion    : one row from SearchProxy::suggest()
 */

export type SortKey = 'relevance' | 'newest' | 'oldest' | 'title' | 'count' | 'episode';

/** One sort choice offered for a corpus (server-built, label already translated). */
export interface SortOption {
  value: SortKey;
  label: string;
}

/** Which result card to render — selected by the profile's `kind`. */
export type CardKind =
  | 'item'
  | 'project'
  | 'publication'
  | 'podcast'
  | 'person'
  | 'section'
  | 'organisation'
  | 'term';

/** Single origin year vs a start/end range. */
export type DateMode = 'single' | 'range';

/** Global year span for the range slider. */
export interface YearBounds {
  min: number;
  max: number;
}

/** Per-block config the server inlines; read once on mount. */
export interface Bootstrap {
  block_id: number;
  /** Search profile (corpus) this block queries, e.g. "research_projects". */
  profile: string;
  /** Result card to render. */
  card_kind: CardKind;
  /** Corpus-specific search-box placeholder; null → use the kind-derived default. */
  search_placeholder?: string | null;
  /** Date handling for this corpus. */
  date_mode: DateMode;
  /** Whether to show the year range slider (range profiles only). */
  show_year: boolean;
  /** Slider bounds (range profiles), or null. */
  year_bounds: YearBounds | null;
  /** Facet fields to show, in order. */
  facets: string[];
  /** field => translated label (so the client needs no hardcoded strings). */
  facet_labels: Record<string, string>;
  default_sort: SortKey;
  /** Sort choices to show, in order (varies by corpus — e.g. no year sorts when date-less). */
  sort_options?: SortOption[];
  per_page: number;
  /** Admin-authored raw Typesense filter_by; echoed back on every request. */
  locked_filter: string;
  /** Result links are built as `${item_url_base}/${doc.id}`. */
  item_url_base: string;
  endpoints: { search: string; suggest: string };
  /** Server-rendered first page, so the block paints without a round-trip. */
  initial_response?: SearchResponse;
  /** Seed query (federated results page reuses App per corpus with a shared query). */
  initial_query?: string;
}

/** A Typesense document, trimmed to the fields the cards render. */
export interface Doc {
  id: string;
  title: string;

  // Research-item fields.
  type_s?: string;
  project_s?: string;
  /** Place of origin — the specific place as recorded (dcterms:spatial, e.g. "Bayreuth"). */
  origin_ss?: string[];
  /** Country of origin — dcterms:spatial rolled up to country (broad-browse facet). */
  country_ss?: string[];
  /** Current location — where the item is held now (dcterms:provenance: place or institution). */
  provenance_ss?: string[];
  language_ss?: string[];
  subject_ss?: string[];
  tag_ss?: string[];
  audience_ss?: string[];
  digitisation_ss?: string[];
  creator_ss?: string[];
  year?: number;

  // Research-project fields.
  institution_ss?: string[];
  section_ss?: string[];
  pi_ss?: string[];
  /** Person item ids, parallel to pi_ss ("" where the PI is an unlinked literal). */
  pi_ids?: string[];
  member_ss?: string[];
  people_ss?: string[];
  year_start?: number;
  year_end?: number;
  item_count?: number;
  has_items?: string;

  // Publication fields.
  author_ss?: string[];
  /** Person item ids, parallel to author_ss ("" where the author is unlinked). */
  author_ids?: string[];
  editor_ss?: string[];
  /** Journal or book title (and any series), the venue. */
  container_ss?: string[];
  publisher_ss?: string[];
  keyword_ss?: string[];
  volume_s?: string;
  issue_s?: string;
  /** Normalised page range / count, e.g. "141–165" or "121 pp.". */
  pages_s?: string;
  /** Resolvable DOI link, e.g. "https://doi.org/10.1163/…". */
  doi_s?: string;

  // Podcast fields. people_ss (union of hosts + guests) and language_ss are shared
  // with the corpora above; series_s is single-valued.
  /** Podcast series, e.g. "Cluster Conversations". */
  series_s?: string;
  /** Series item id, so the card links the series to its Omeka page. */
  series_id?: string;
  /** Episode number (the default sort). */
  episode?: number;
  host_ss?: string[];
  /** Person item ids, parallel to host_ss ("" where the host is unlinked). */
  host_ids?: string[];
  guest_ss?: string[];
  /** Person item ids, parallel to guest_ss ("" where the guest is unlinked). */
  guest_ids?: string[];
  /** Sound engineer(s) — an optional production credit line. */
  engineer_ss?: string[];
  /** Person item ids, parallel to engineer_ss ("" where unlinked). */
  engineer_ids?: string[];
  /** External "Listen" link (audio / streaming / episode page). */
  url_s?: string;
  /** Publication date, shown verbatim on the card (e.g. "2026-01-28"). */
  date_s?: string;
  /** Whether the episode has a transcript (the full text isn't shipped). */
  has_transcript?: boolean;

  // Person fields.
  affiliation_ss?: string[];
  roles_ss?: string[];
  /** Number of publications the person authored/edited. */
  publication_count?: number;

  // Research-section fields.
  /** "Phase 1" / "Phase 2" (absent for the External section). */
  phase_s?: string;
  spokesperson_ss?: string[];
  member_count?: number;
  project_count?: number;

  // Organisation fields (institutions & groups). type_s = "Institution" / "Group";
  // roles_ss = Funder / Contributor / Host institution. project_count and item_count
  // are shared with the sections/projects corpora.
  /** Number of people who name this organisation as their affiliation. */
  people_count?: number;

  // Authority-term fields (genres, languages, locations, subjects & tags). Reuses
  // type_s (sub-type, e.g. Country / Tag — absent for genres & languages) and the
  // shared item_count / publication_count association figures.

  // Shared.
  abstract?: string;
  description?: string;
  thumbnail_url?: string;
  /**
   * Per-field match highlights for the current query: field name => one or more
   * snippets with matched tokens wrapped in private-use sentinels (NOT HTML).
   * Only present for fields that actually matched. The client renders these as
   * text + <mark> via lib/highlight.ts. Empty/absent on a browse (no query).
   */
  _highlights?: Record<string, string[]>;
}

export interface FacetCount {
  value: string;
  count: number;
}

export interface Facet {
  field: string;
  label: string;
  counts: FacetCount[];
}

export interface SearchResponse {
  available: boolean;
  found: number;
  page: number;
  hits: Doc[];
  facets: Facet[];
  search_time_ms?: number;
  error?: string | null;
}

export interface Suggestion {
  id: string;
  title: string;
  /** A short "·"-joined meta line (type · year, or section · year range). */
  subtitle?: string | null;
}

/** field => selected values. */
export type ActiveFilters = Record<string, string[]>;

export interface SearchRequest {
  profile: string;
  q: string;
  page: number;
  per_page: number;
  sort: SortKey;
  filters: ActiveFilters;
  facets: string[];
  locked_filter: string;
  /** Year bounds — null means "no constraint at that end". */
  year_from?: number | null;
  year_to?: number | null;
}

// ── Federated search (header bar + grouped results page) ─────────────────────

/** One corpus's suggestions in the federated autocomplete, tagged for a badge. */
export interface SuggestGroup {
  profile: string;
  label: string;
  kind: CardKind;
  suggestions: Suggestion[];
}

export interface SuggestAllResponse {
  available: boolean;
  groups: SuggestGroup[];
}

/** Per-corpus metadata the federated page needs to render a tab + its App. */
export interface ProfileMeta {
  name: string;
  label: string;
  kind: CardKind;
  date_mode: DateMode;
  show_year: boolean;
  year_bounds: YearBounds | null;
  facets: string[];
  facet_labels: Record<string, string>;
  default_sort: SortKey;
  sort_options: SortOption[];
  per_page: number;
  placeholder?: string | null;
}

/** Bootstrap for the theme header search bar (variant 'bar'). */
export interface SearchBarBootstrap {
  variant: 'bar';
  available: boolean;
  /** Suggestion links are built as `${item_url_base}/${id}`. */
  item_url_base: string;
  /** Where a full search (Enter / "see all") navigates: `${results_url}?q=…`. */
  results_url: string;
  placeholder: string;
  /** Mobile: render as a toggle button that expands the input. */
  collapsible?: boolean;
  endpoints: { suggest_all: string };
}

/** Bootstrap for the federated results page (variant 'federated'). */
export interface FederatedBootstrap {
  variant: 'federated';
  available: boolean;
  item_url_base: string;
  initial_query: string;
  default_profile: string;
  profiles: ProfileMeta[];
  endpoints: { search: string; search_all: string; suggest: string; suggest_all: string };
}

export interface SearchAllResponse {
  available: boolean;
  /** profile name => total matches for the shared query (tab badges). */
  counts: Record<string, number>;
  active: SearchResponse;
}

/** Shared request for the federated search: free-text + optional year only. */
export interface SearchAllRequest {
  profile: string;
  q: string;
  page?: number;
  per_page?: number;
  sort?: SortKey;
  filters?: ActiveFilters;
  facets?: string[];
  year_from?: number | null;
  year_to?: number | null;
}
