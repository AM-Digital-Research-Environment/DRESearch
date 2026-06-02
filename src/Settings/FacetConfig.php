<?php
declare(strict_types=1);

namespace DRESearch\Settings;

/**
 * The facet / index mapping for research items, built from the `dre_search`
 * config (config/module.config.php, overridable via config/local.config.php).
 *
 * Nothing instance-specific is hardcoded here: which Omeka properties feed
 * which Typesense fields, the authority item-set IDs, and the dcterms:type
 * discriminator items all come from config — so porting to another Omeka
 * instance is a config override, not a source edit. The Typesense field names
 * (type_s, country_ss, …) are the stable interface; their Omeka-side backing
 * is what's configurable.
 *
 * The resolution *logic* for the three shared-property facets lives in
 * ResearchItemMapper; this object just supplies the IDs it keys on:
 *   - subject vs tag : dcterms:subject split by the target's type item
 *                      (typeItem('lcsh') vs typeItem('tag'))
 *   - country        : dcterms:spatial — country item directly, else the
 *                      city/region's dcterms:isPartOf country
 *   - digitisation   : dcterms:format limited to itemSet('digital')
 *                      (itemSet('genre') excluded)
 */
final class FacetConfig
{
    /**
     * @param array<string,array{property:string,label:string,array:bool}> $facets
     * @param array<string,int> $itemSets
     * @param array<string,int> $typeItems
     */
    public function __construct(
        private readonly array $facets,
        private readonly array $itemSets,
        private readonly array $typeItems,
        private readonly int $researchTemplateId,
        private readonly string $queryBy,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            $config['facets'] ?? [],
            $config['authority_item_sets'] ?? [],
            $config['type_items'] ?? [],
            (int) ($config['research_template_id'] ?? 0),
            (string) ($config['query_by'] ?? 'title'),
        );
    }

    /** @return list<string> Facet field names, in display order. */
    public function fieldNames(): array
    {
        return array_keys($this->facets);
    }

    /** @return array<string,array{property:string,label:string,array:bool}> */
    public function all(): array
    {
        return $this->facets;
    }

    public function label(string $field): string
    {
        return $this->facets[$field]['label'] ?? $field;
    }

    public function isMultivalued(string $field): bool
    {
        return (bool) ($this->facets[$field]['array'] ?? true);
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

    public function researchTemplateId(): int
    {
        return $this->researchTemplateId;
    }

    public function queryBy(): string
    {
        return $this->queryBy;
    }
}
