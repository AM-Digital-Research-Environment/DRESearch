/**
 * Client-side types. These mirror the JSON the PHP layer emits:
 *   - Bootstrap     : the per-block <script type="application/json"> blob
 *   - SearchResponse: what SearchProxy::search() returns
 *   - Suggestion    : one row from SearchProxy::suggest()
 */

export type SortKey = 'relevance' | 'newest' | 'oldest' | 'title';

/** Per-block config the server inlines; read once on mount. */
export interface Bootstrap {
  block_id: number;
  /** Facet fields to show, in order (subset of the eight). */
  facets: string[];
  /** field => translated label (so the client needs no hardcoded strings). */
  facet_labels: Record<string, string>;
  default_sort: SortKey;
  per_page: number;
  /** Admin-authored raw Typesense filter_by; echoed back on every request. */
  locked_filter: string;
  /** Result links are built as `${item_url_base}/${doc.id}`. */
  item_url_base: string;
  endpoints: { search: string; suggest: string };
  /** Server-rendered first page, so the block paints without a round-trip. */
  initial_response?: SearchResponse;
}

/** A Typesense document, trimmed to the fields the card renders. */
export interface Doc {
  id: string;
  title: string;
  type_s?: string;
  project_s?: string;
  country_ss?: string[];
  language_ss?: string[];
  subject_ss?: string[];
  tag_ss?: string[];
  audience_ss?: string[];
  digitisation_ss?: string[];
  creator_ss?: string[];
  year?: number;
  abstract?: string;
  description?: string;
  thumbnail_url?: string;
  /** Highlighted title snippet (contains literal <mark> tags only). */
  _title_highlight?: string;
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
  type?: string | null;
  project?: string | null;
  year?: number | null;
}

/** field => selected values. */
export type ActiveFilters = Record<string, string[]>;

export interface SearchRequest {
  q: string;
  page: number;
  per_page: number;
  sort: SortKey;
  filters: ActiveFilters;
  facets: string[];
  locked_filter: string;
}
