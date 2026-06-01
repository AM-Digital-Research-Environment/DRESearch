<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

/**
 * The Typesense collection schema for DRE research items — keyword search only
 * (no embedding field, so the Typesense container stays lean enough for a
 * modest host). Facets mirror DRESearch\Settings\FacetConfig.
 *
 * No default_sorting_field: dates are optional, so every query passes sort_by
 * explicitly (relevance, or year asc/desc).
 */
final class SchemaProvider
{
    /** Fields searched by full-text queries (query_by), in priority order. */
    public const QUERY_BY = 'title,abstract,description,subject_ss,tag_ss,creator_ss';

    public function collection(string $name): array
    {
        return [
            'name' => $name,
            // Treat apostrophes/hyphens as separators so "l'islam" / "côte-d'ivoire"
            // tokenise sensibly.
            'token_separators' => ["'", '-'],
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'is_public', 'type' => 'bool', 'facet' => false],

                ['name' => 'title', 'type' => 'string', 'sort' => true],
                ['name' => 'abstract', 'type' => 'string', 'optional' => true],
                ['name' => 'description', 'type' => 'string', 'optional' => true],
                ['name' => 'creator_ss', 'type' => 'string[]', 'facet' => true, 'optional' => true],

                // Facets (see FacetConfig).
                ['name' => 'type_s', 'type' => 'string', 'facet' => true, 'optional' => true],
                ['name' => 'project_s', 'type' => 'string', 'facet' => true, 'optional' => true],
                ['name' => 'country_ss', 'type' => 'string[]', 'facet' => true, 'optional' => true],
                ['name' => 'language_ss', 'type' => 'string[]', 'facet' => true, 'optional' => true],
                ['name' => 'subject_ss', 'type' => 'string[]', 'facet' => true, 'optional' => true],
                ['name' => 'tag_ss', 'type' => 'string[]', 'facet' => true, 'optional' => true],
                ['name' => 'audience_ss', 'type' => 'string[]', 'facet' => true, 'optional' => true],
                ['name' => 'digitisation_ss', 'type' => 'string[]', 'facet' => true, 'optional' => true],

                // Origin date.
                ['name' => 'year', 'type' => 'int32', 'facet' => true, 'sort' => true, 'optional' => true],
                ['name' => 'date', 'type' => 'int64', 'sort' => true, 'optional' => true],

                // Display only.
                ['name' => 'thumbnail_url', 'type' => 'string', 'index' => false, 'optional' => true],
            ],
        ];
    }
}
