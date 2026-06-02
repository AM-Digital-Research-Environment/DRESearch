<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\FacetConfig;

/**
 * Builds the Typesense collection schema for DRE research items — keyword
 * search only (no embedding field, so the container stays lean). The facet
 * fields are generated from {@see FacetConfig}, so adding/removing a facet in
 * config flows straight into the schema on the next reindex.
 *
 * No default_sorting_field: dates are optional, so every query passes sort_by
 * explicitly (relevance, or year asc/desc).
 */
final class SchemaProvider
{
    public function collection(string $name, FacetConfig $facetConfig): array
    {
        $facetNames = $facetConfig->fieldNames();
        $used = array_flip($facetNames);
        $fields = [];

        // Identity + display fields (skip any name a facet already claims).
        $head = [
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'is_public', 'type' => 'bool', 'facet' => false],
            ['name' => 'title', 'type' => 'string', 'sort' => true],
            ['name' => 'abstract', 'type' => 'string', 'optional' => true],
            ['name' => 'description', 'type' => 'string', 'optional' => true],
            ['name' => 'creator_ss', 'type' => 'string[]', 'facet' => true, 'optional' => true],
        ];
        foreach ($head as $field) {
            if (!isset($used[$field['name']])) {
                $fields[] = $field;
                $used[$field['name']] = true;
            }
        }

        // Facet fields (data-driven).
        foreach ($facetNames as $name_) {
            $fields[] = [
                'name'     => $name_,
                'type'     => $facetConfig->isMultivalued($name_) ? 'string[]' : 'string',
                'facet'    => true,
                'optional' => true,
            ];
        }

        // Origin date + display.
        $tail = [
            ['name' => 'year', 'type' => 'int32', 'facet' => true, 'sort' => true, 'optional' => true],
            ['name' => 'date', 'type' => 'int64', 'sort' => true, 'optional' => true],
            ['name' => 'thumbnail_url', 'type' => 'string', 'index' => false, 'optional' => true],
        ];
        foreach ($tail as $field) {
            if (!isset($used[$field['name']])) {
                $fields[] = $field;
            }
        }

        return [
            'name' => $name,
            // Treat apostrophes/hyphens as separators so "l'islam" / "côte-d'ivoire"
            // tokenise sensibly.
            'token_separators' => ["'", '-'],
            'fields' => $fields,
        ];
    }
}
