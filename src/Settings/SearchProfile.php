<?php
declare(strict_types=1);

namespace DRESearch\Settings;

/**
 * One search corpus ("profile") — e.g. research items or research projects —
 * built from a `dre_search.profiles.<name>` config block (overridable via
 * config/local.config.php). A {@see ProfileRegistry} holds one per corpus.
 *
 * Nothing instance-specific is hardcoded: the Typesense collection alias, the
 * source resource template / item set, which Omeka properties feed which
 * Typesense fields, the authority IDs, the date handling, and the read term
 * list all come from config. The Typesense field names (type_s, institution_ss,
 * year_start, …) are the stable interface; their Omeka-side backing is what's
 * configurable.
 *
 * `kind` selects the indexer mapper and the result card:
 *   - 'item'         : research items (shared-property facets via AuthorityResolver)
 *   - 'project'      : research projects (linked-title facets, a date range, and a
 *                      reverse count of associated research items)
 *   - 'publication'  : bibliographic references (linked authors + literal venue/
 *                      publisher, a single year, and a formatted reference card)
 *   - 'podcast'      : podcast episodes (series + host/guest people, an episode
 *                      number, the series logo as the thumbnail, a "Listen" link)
 *   - 'video'        : YouTube videos (playlist + speaker people, the video's own
 *                      poster thumbnail, a transcript, a "Watch" link)
 *   - 'person'       : people (affiliation + reverse-link roles & association counts)
 *   - 'section'      : research sections (leaders, derived phase, project count)
 *   - 'organisation' : institutions & groups (Type facet + reverse-link roles & counts)
 *   - 'term'         : authority terms (genres, languages, locations, subjects & tags)
 *                      — a name + optional Type facet + reverse-link association counts
 */
final class SearchProfile
{
    /**
     * @param array<string,array{property:?string,label:string,array:bool,derived:bool}> $facets
     * @param array<string,array{property:?string,type:string,facet:bool,sort:bool,index:bool}> $displayFields
     * @param array<string,int> $itemSets
     * @param array<string,int> $typeItems
     * @param array{mode:string,property:?string,label:string} $date
     * @param list<string> $readProperties
     * @param array{from_template:int,property:string,public_only:bool}|null $itemLink
     * @param array{counts?:array<string,array<string,mixed>>,roles?:list<array<string,mixed>>}|null $reverseLinks
     */
    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly string $placeholder,
        private readonly string $collection,
        private readonly string $kind,
        private readonly ?int $templateId,
        private readonly ?int $itemSetId,
        private readonly string $queryBy,
        private readonly array $facets,
        private readonly array $displayFields,
        private readonly array $itemSets,
        private readonly array $typeItems,
        private readonly array $date,
        private readonly array $readProperties,
        private readonly ?array $itemLink,
        private readonly ?array $reverseLinks,
        private readonly string $defaultSort,
        private readonly ?array $sortCount,
        private readonly array $extraSources,
        private readonly array $sortFields,
        private readonly ?string $thumbnailProperty,
    ) {
    }

    public static function fromArray(string $name, array $c): self
    {
        // Normalise facet defs (fill array/derived defaults).
        $facets = [];
        foreach (($c['facets'] ?? []) as $field => $def) {
            $facets[$field] = [
                'property' => $def['property'] ?? null,
                'label'    => (string) ($def['label'] ?? $field),
                'array'    => (bool) ($def['array'] ?? true),
                'derived'  => (bool) ($def['derived'] ?? false),
            ];
        }

        $displayFields = [];
        foreach (($c['display_fields'] ?? []) as $field => $def) {
            $displayFields[$field] = [
                'property' => $def['property'] ?? null,
                'type'     => (string) ($def['type'] ?? 'string'),
                'facet'    => (bool) ($def['facet'] ?? false),
                'sort'     => (bool) ($def['sort'] ?? false),
                // index:false for display-only fields (e.g. id lists for links).
                'index'    => array_key_exists('index', $def) ? (bool) $def['index'] : true,
                // search_only: indexed for query_by but excluded from result payloads
                // (e.g. a podcast transcript — searchable, never shipped to the card).
                'search_only' => (bool) ($def['search_only'] ?? false),
            ];
        }

        // mode 'none' = the corpus has no date at all (e.g. people); 'single' is
        // the back-compat default when a date block is present but unspecified.
        $date = $c['date'] ?? ['mode' => 'none'];
        $date = [
            'mode'     => (string) ($date['mode'] ?? 'single'),
            'property' => $date['property'] ?? null,
            'label'    => (string) ($date['label'] ?? 'Year'),
            // Whether to expose a year range slider (works for single or range
            // date modes; the backing field(s) differ, the UI doesn't).
            'facet'    => (bool) ($date['facet'] ?? false),
        ];

        // Extra source resources folded into the corpus beyond the primary
        // template/item-set (e.g. the Locations corpus also indexes geocoded
        // institutions). Each entry widens the reindex source query.
        $extraSources = [];
        foreach (($c['extra_sources'] ?? []) as $src) {
            if (!is_array($src)) {
                continue;
            }
            $extraSources[] = [
                'template_id'      => isset($src['template_id']) && $src['template_id'] !== null ? (int) $src['template_id'] : null,
                'item_set_id'      => isset($src['item_set_id']) && $src['item_set_id'] !== null ? (int) $src['item_set_id'] : null,
                'require_property' => isset($src['require_property']) && $src['require_property'] !== '' ? (string) $src['require_property'] : null,
            ];
        }

        // Extra numeric sorts: sort key => { field, dir, label }. Each exposes a
        // sortable int field as its own sort option (e.g. podcasts by episode
        // number) — the generalisation of the single-field `sort_count`.
        $sortFields = [];
        foreach (($c['sort_fields'] ?? []) as $key => $def) {
            if (!is_array($def) || empty($def['field'])) {
                continue;
            }
            $dir = strtolower((string) ($def['dir'] ?? 'desc'));
            $sortFields[(string) $key] = [
                'field' => (string) $def['field'],
                'dir'   => $dir === 'asc' ? 'asc' : 'desc',
                'label' => (string) ($def['label'] ?? $key),
            ];
        }

        return new self(
            $name,
            (string) ($c['label'] ?? $name),
            (string) ($c['placeholder'] ?? ''),
            (string) ($c['collection'] ?? ($name . '_current')),
            (string) ($c['kind'] ?? 'item'),
            // null = no template filter (e.g. publications span several
            // templates but share one item set); scoping then relies on item_set_id.
            isset($c['template_id']) && $c['template_id'] !== null ? (int) $c['template_id'] : null,
            isset($c['item_set_id']) && $c['item_set_id'] !== null ? (int) $c['item_set_id'] : null,
            (string) ($c['query_by'] ?? 'title'),
            $facets,
            $displayFields,
            $c['authority_item_sets'] ?? [],
            $c['type_items'] ?? [],
            $date,
            array_values($c['read_properties'] ?? []),
            $c['item_link'] ?? null,
            // `reverse_links` is the current key; `person_links` is accepted as a
            // back-compat alias (the mechanism was first built for the people corpus).
            $c['reverse_links'] ?? $c['person_links'] ?? null,
            (string) ($c['default_sort'] ?? 'relevance'),
            // sort_count: {field, label} exposes a "most <X>" sort over a sortable
            // int32 display field (e.g. item_count) — the term corpora sort by how
            // many records reference each term.
            isset($c['sort_count']) && is_array($c['sort_count']) ? $c['sort_count'] : null,
            $extraSources,
            $sortFields,
            // Resolve the episode thumbnail from a linked resource (e.g. the podcast
            // series item's image) instead of the item's own media. '' / unset → own.
            isset($c['thumbnail_property']) && $c['thumbnail_property'] !== '' ? (string) $c['thumbnail_property'] : null,
        );
    }

    // ── Identity ────────────────────────────────────────────────────────────
    public function name(): string { return $this->name; }
    public function label(): string { return $this->label; }

    /**
     * Optional search-box placeholder ('' = none). Lets corpora that share a card
     * `kind` (e.g. the authority-term corpora) still show a corpus-specific hint;
     * the client falls back to a kind-derived default when this is empty.
     */
    public function placeholder(): string { return $this->placeholder; }
    public function collection(): string { return $this->collection; }
    public function kind(): string { return $this->kind; }
    public function templateId(): ?int { return $this->templateId; }
    public function itemSetId(): ?int { return $this->itemSetId; }
    public function queryBy(): string { return $this->queryBy; }

    // ── Facets ────────────────────────────────────────────────────────────────
    /** @return list<string> Facet field names, in display order. */
    public function fieldNames(): array
    {
        return array_keys($this->facets);
    }

    /** @return array<string,array{property:?string,label:string,array:bool,derived:bool}> */
    public function all(): array
    {
        return $this->facets;
    }

    public function hasFacet(string $field): bool
    {
        return isset($this->facets[$field]);
    }

    /**
     * Fields the client is allowed to filter on: every sidebar facet, plus any
     * faceted display field (e.g. creator_ss) so a card can make a non-sidebar
     * value like an author name clickable-to-filter. index:false display fields
     * are excluded (they aren't queryable in Typesense).
     *
     * @return list<string>
     */
    public function filterableFields(): array
    {
        $fields = $this->fieldNames();
        foreach ($this->displayFields as $name => $def) {
            if (($def['facet'] ?? false) && (($def['index'] ?? true) !== false)) {
                $fields[] = $name;
            }
        }
        return array_values(array_unique($fields));
    }

    public function facetLabel(string $field): string
    {
        return $this->facets[$field]['label'] ?? $field;
    }

    public function isMultivalued(string $field): bool
    {
        return (bool) ($this->facets[$field]['array'] ?? true);
    }

    public function isDerivedFacet(string $field): bool
    {
        return (bool) ($this->facets[$field]['derived'] ?? false);
    }

    public function property(string $field): ?string
    {
        return $this->facets[$field]['property'] ?? null;
    }

    /** @return list<string> Distinct Omeka property terms across all facets. */
    public function properties(): array
    {
        $terms = [];
        foreach ($this->facets as $def) {
            if (!empty($def['property'])) {
                $terms[$def['property']] = true;
            }
        }
        return array_keys($terms);
    }

    // ── Authority disambiguation (item kind) ──────────────────────────────────
    public function itemSet(string $key): ?int
    {
        return isset($this->itemSets[$key]) ? (int) $this->itemSets[$key] : null;
    }

    /** @return list<int> All configured authority item-set IDs. */
    public function allItemSets(): array
    {
        return array_values(array_map('intval', $this->itemSets));
    }

    public function typeItem(string $key): ?int
    {
        return isset($this->typeItems[$key]) ? (int) $this->typeItems[$key] : null;
    }

    // ── Display (non-facet) fields ────────────────────────────────────────────
    /** @return array<string,array{property:?string,type:string,facet:bool,sort:bool,index:bool,search_only:bool}> */
    public function displayFields(): array
    {
        return $this->displayFields;
    }

    /**
     * Fields indexed for search (query_by) but excluded from result payloads — the
     * `search_only` display fields (e.g. a podcast transcript). The reindexer still
     * stores them so Typesense can match + snippet-highlight them; the query
     * (see {@see \DRESearch\Search\QueryBuilder}) drops them from the returned
     * documents so a large value never bloats every hit.
     *
     * @return list<string>
     */
    public function searchOnlyFields(): array
    {
        $out = [];
        foreach ($this->displayFields as $name => $def) {
            if (!empty($def['search_only'])) {
                $out[] = $name;
            }
        }
        return $out;
    }

    // ── Dates ─────────────────────────────────────────────────────────────────
    public function dateMode(): string { return $this->date['mode']; }
    public function dateProperty(): ?string { return $this->date['property']; }
    public function dateLabel(): string { return $this->date['label']; }
    public function isRangeDate(): bool { return $this->date['mode'] === 'range'; }

    /** Whether this corpus has any date at all (single or range). */
    public function hasDate(): bool
    {
        return $this->date['mode'] === 'single' || $this->date['mode'] === 'range';
    }

    /** Whether this profile exposes a year range slider (config: date.facet). */
    public function hasYearFacet(): bool
    {
        return (bool) $this->date['facet'];
    }

    /** Typesense field used for newest/oldest sort. */
    public function sortYearField(): string
    {
        return $this->isRangeDate() ? 'year_start' : 'year';
    }

    /**
     * Typesense int field(s) whose facet stats give the slider bounds: a single
     * `year`, or the `year_start`/`year_end` pair for a range.
     *
     * @return list<string>
     */
    public function yearStatFields(): array
    {
        return $this->isRangeDate() ? ['year_start', 'year_end'] : ['year'];
    }

    // ── Sorting ─────────────────────────────────────────────────────────────
    /** Default sort key for blocks of this corpus that don't override it. */
    public function defaultSort(): string
    {
        return $this->defaultSort;
    }

    /** Typesense int field for the "most <X>" sort, or null if not offered. */
    public function sortCountField(): ?string
    {
        $field = $this->sortCount['field'] ?? null;
        return is_string($field) && $field !== '' ? $field : null;
    }

    /** Label for the count sort option (e.g. "Most research items"). */
    public function sortCountLabel(): string
    {
        return (string) ($this->sortCount['label'] ?? 'Most results'); // @translate
    }

    /**
     * Ordered sort keys this corpus offers: always relevance + title; a count
     * sort when configured; newest/oldest only when the corpus has a date (so a
     * date-less corpus never shows the meaningless year sorts).
     *
     * @return list<string>
     */
    public function sortOptionValues(): array
    {
        $values = ['relevance'];
        // Config-defined numeric sorts (e.g. podcasts by episode number).
        foreach (array_keys($this->sortFields) as $key) {
            $values[] = $key;
        }
        if ($this->sortCountField() !== null) {
            $values[] = 'count';
        }
        if ($this->hasDate()) {
            $values[] = 'newest';
            $values[] = 'oldest';
        }
        $values[] = 'title';
        return $values;
    }

    /**
     * Config-defined extra numeric sorts: sort key => { field, dir, label }. Each
     * sorts by a sortable int field (with a title tiebreak) under its own option;
     * the generic form of {@see sortCountField()}. Empty for most corpora.
     *
     * @return array<string,array{field:string,dir:string,label:string}>
     */
    public function sortFields(): array
    {
        return $this->sortFields;
    }

    /**
     * The {field,dir} spec for a custom sort key, or null if it isn't one (so
     * {@see \DRESearch\Search\QueryBuilder} can build its sort_by) .
     *
     * @return array{field:string,dir:string,label:string}|null
     */
    public function sortFieldSpec(string $key): ?array
    {
        return $this->sortFields[$key] ?? null;
    }

    /** Label for a custom sort key (e.g. "Episode number"), or null if not one. */
    public function sortFieldLabel(string $key): ?string
    {
        return $this->sortFields[$key]['label'] ?? null;
    }

    /**
     * Property term to resolve the thumbnail FROM a linked resource instead of the
     * item's own media (e.g. podcasts use the series item's logo via
     * dcterms:isPartOf), or null to use the item's own first thumbnailed media.
     */
    public function thumbnailFromProperty(): ?string
    {
        return $this->thumbnailProperty;
    }

    // ── Reindex ─────────────────────────────────────────────────────────────
    /**
     * Every Omeka property term the reindexer must SELECT: facet properties,
     * display-field properties, the date property, plus the explicit
     * read_properties list (terms a mapper folds that aren't otherwise declared,
     * e.g. the creator-role union or date fallbacks).
     *
     * @return list<string>
     */
    public function readProperties(): array
    {
        $terms = [];
        foreach ($this->properties() as $t) {
            $terms[$t] = true;
        }
        foreach ($this->displayFields as $def) {
            if (!empty($def['property'])) {
                $terms[$def['property']] = true;
            }
        }
        if ($this->date['property']) {
            $terms[$this->date['property']] = true;
        }
        foreach ($this->readProperties as $t) {
            if ($t !== '') {
                $terms[$t] = true;
            }
        }
        return array_keys($terms);
    }

    /**
     * Extra source resources folded into this corpus beyond the primary
     * template/item-set. Each entry widens the reindex source query with an
     * OR-group: resources of `template_id` (and/or in `item_set_id`) that carry a
     * value for `require_property`. The Locations corpus uses this to index
     * geocoded institutions (template 2 with geo:lat) alongside the place
     * authority — they surface as a new Type ("Institution"), and the existing
     * dcterms:provenance reverse-link gives each its held-items count and
     * "Current location" relationship. Every other corpus omits the key → [].
     *
     * @return list<array{template_id:?int,item_set_id:?int,require_property:?string}>
     */
    public function extraSources(): array
    {
        return $this->extraSources;
    }

    /**
     * Reverse item-count config (project kind): count resources of
     * `from_template` whose `property` links to each indexed item.
     *
     * @return array{from_template:int,property:string,public_only:bool}|null
     */
    public function itemLink(): ?array
    {
        return $this->itemLink;
    }

    /**
     * Reverse-link config (person & organisation kinds): how to count the records
     * that reference each indexed entity, and which relationships to surface as
     * roles. The mechanism is corpus-agnostic — people count the items/publications
     * referencing them; organisations count the projects they fund, items crediting
     * them, and people affiliated with them.
     *
     *   counts: field => { properties?:?list<string>, from_template?:int,
     *                      from_item_set?:int, public_only:bool }
     *   roles:  list of { label:string, properties?:?list<string>, from_template?:int,
     *                     from_item_set?:int, public_only?:bool }
     *           OR        { per_property:true, vocabulary?:string,
     *                     properties?:?list<string>, from_template?:int,
     *                     from_item_set?:int, public_only?:bool }
     *
     * A fixed-label rule's `properties` null = any reference (e.g. "contributed to
     * a research item"); otherwise only references via those properties (e.g.
     * dcterms:creator on a project = "Principal investigator"). A `per_property`
     * rule instead derives one label per distinct property the entity is referenced
     * by — narrowed to a `vocabulary` (prefix) and/or `properties` list — taking the
     * label from the property's `from_template` alternate label (so each marcrel:*
     * relator a person holds surfaces as its own role).
     *
     * @return array{counts?:array<string,array<string,mixed>>,roles?:list<array<string,mixed>>}|null
     */
    public function reverseLinks(): ?array
    {
        return $this->reverseLinks;
    }
}
