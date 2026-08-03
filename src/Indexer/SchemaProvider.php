<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/**
 * Builds the Typesense collection schema for a search profile — keyword search
 * only (no embedding field, so the container stays lean). Facet fields, extra
 * display fields, and the date field(s) are all generated from the
 * {@see SearchProfile}, so adding/removing a facet (or switching a profile's
 * date mode) in config flows straight into the schema on the next reindex.
 *
 * No default_sorting_field: dates are optional, so every query passes sort_by
 * explicitly (relevance, or year asc/desc).
 */
final class SchemaProvider
{
    public function collection(string $name, SearchProfile $profile): array
    {
        $fields = [];
        $used = [];

        $add = static function (array $field) use (&$fields, &$used): void {
            $n = $field['name'];
            if (!isset($used[$n])) {
                $fields[] = $field;
                $used[$n] = true;
            }
        };

        // Identity + universal display fields.
        $add(['name' => 'id', 'type' => 'string']);
        $add(['name' => 'is_public', 'type' => 'bool', 'facet' => false]);
        $add(['name' => 'title', 'type' => 'string', 'sort' => true]);
        $add(['name' => 'abstract', 'type' => 'string', 'optional' => true]);
        $add(['name' => 'description', 'type' => 'string', 'optional' => true]);
        // Display-only source markers let a Typesense union response select the
        // correct mixed-result card. Union responses do not otherwise identify
        // which sub-search produced a hit.
        $add(['name' => '_profile', 'type' => 'string', 'index' => false]);
        $add(['name' => '_kind', 'type' => 'string', 'index' => false]);

        // Facet fields (data-driven). Derived facets (e.g. has_items) are plain
        // single-valued strings.
        foreach ($profile->fieldNames() as $field) {
            $add([
                'name'     => $field,
                'type'     => $profile->isMultivalued($field) ? 'string[]' : 'string',
                'facet'    => true,
                'optional' => true,
            ]);
        }

        // Extra display / query fields (e.g. creator_ss, pi_ss, pi_ids, member_ss, item_count).
        foreach ($profile->displayFields() as $field => $def) {
            $f = [
                'name'     => $field,
                'type'     => $def['type'],
                'facet'    => $def['facet'],
                // Typesense builds the geo index from the sort index, so it
                // rejects a geopoint declared with `sort: false` outright. The
                // rule belongs here rather than in every profile's config.
                'sort'     => $def['sort'] || self::isGeo($def['type']),
                'optional' => true,
            ];
            if (($def['index'] ?? true) === false) {
                $f['index'] = false; // display-only (e.g. id lists for links)
            }
            $add($f);
        }

        // Origin date(s) — single year, or a start/end range. A corpus may have
        // no date at all (e.g. people), in which case no year field is added.
        if ($profile->hasDate()) {
            if ($profile->isRangeDate()) {
                $add(['name' => 'year_start', 'type' => 'int32', 'facet' => true, 'sort' => true, 'optional' => true]);
                $add(['name' => 'year_end', 'type' => 'int32', 'facet' => true, 'sort' => true, 'optional' => true]);
            } else {
                $add(['name' => 'year', 'type' => 'int32', 'facet' => true, 'sort' => true, 'optional' => true]);
                $add(['name' => 'date', 'type' => 'int64', 'sort' => true, 'optional' => true]);
            }
        }

        $add(['name' => 'thumbnail_url', 'type' => 'string', 'index' => false, 'optional' => true]);

        return [
            'name' => $name,
            // Treat apostrophes/hyphens as separators so "l'islam" / "côte-d'ivoire"
            // tokenise sensibly.
            'token_separators' => ["'", '-'],
            'fields' => $fields,
        ];
    }

    private static function isGeo(string $type): bool
    {
        return $type === 'geopoint' || $type === 'geopoint[]';
    }
}
