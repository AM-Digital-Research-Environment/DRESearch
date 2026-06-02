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
 *   - 'item'    : research items (shared-property facets via AuthorityResolver)
 *   - 'project' : research projects (linked-title facets, a date range, and a
 *                 reverse count of associated research items)
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
     */
    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly string $collection,
        private readonly string $kind,
        private readonly int $templateId,
        private readonly ?int $itemSetId,
        private readonly string $queryBy,
        private readonly array $facets,
        private readonly array $displayFields,
        private readonly array $itemSets,
        private readonly array $typeItems,
        private readonly array $date,
        private readonly array $readProperties,
        private readonly ?array $itemLink,
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
            ];
        }

        $date = $c['date'] ?? [];
        $date = [
            'mode'     => (string) ($date['mode'] ?? 'single'),
            'property' => $date['property'] ?? null,
            'label'    => (string) ($date['label'] ?? 'Year'),
            // Whether to expose a year range slider (works for single or range
            // date modes; the backing field(s) differ, the UI doesn't).
            'facet'    => (bool) ($date['facet'] ?? false),
        ];

        return new self(
            $name,
            (string) ($c['label'] ?? $name),
            (string) ($c['collection'] ?? ($name . '_current')),
            (string) ($c['kind'] ?? 'item'),
            (int) ($c['template_id'] ?? 0),
            isset($c['item_set_id']) && $c['item_set_id'] !== null ? (int) $c['item_set_id'] : null,
            (string) ($c['query_by'] ?? 'title'),
            $facets,
            $displayFields,
            $c['authority_item_sets'] ?? [],
            $c['type_items'] ?? [],
            $date,
            array_values($c['read_properties'] ?? []),
            $c['item_link'] ?? null,
        );
    }

    // ── Identity ────────────────────────────────────────────────────────────
    public function name(): string { return $this->name; }
    public function label(): string { return $this->label; }
    public function collection(): string { return $this->collection; }
    public function kind(): string { return $this->kind; }
    public function templateId(): int { return $this->templateId; }
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
    /** @return array<string,array{property:?string,type:string,facet:bool,sort:bool,index:bool}> */
    public function displayFields(): array
    {
        return $this->displayFields;
    }

    // ── Dates ─────────────────────────────────────────────────────────────────
    public function dateMode(): string { return $this->date['mode']; }
    public function dateProperty(): ?string { return $this->date['property']; }
    public function dateLabel(): string { return $this->date['label']; }
    public function isRangeDate(): bool { return $this->date['mode'] === 'range'; }

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
     * Reverse item-count config (project kind): count resources of
     * `from_template` whose `property` links to each indexed item.
     *
     * @return array{from_template:int,property:string,public_only:bool}|null
     */
    public function itemLink(): ?array
    {
        return $this->itemLink;
    }
}
