<?php
declare(strict_types=1);

namespace DRESearch\Settings;

/**
 * Single source of truth for the research-item facets.
 *
 * Maps each Typesense field to the Omeka property that feeds it, a default
 * (English) UI label, and the authority item-set IDs + dcterms:type
 * discriminators needed to resolve the trickier facets:
 *
 *   - subject vs tag : both live on dcterms:subject (item set {@see ITEM_SET_SUBJECT}).
 *                      Split by the linked authority item's own dcterms:type
 *                      target ({@see TYPE_ITEM_LCSH} vs {@see TYPE_ITEM_TAG}).
 *   - country        : dcterms:spatial mixes countries, cities and regions
 *                      (item set {@see ITEM_SET_LOCATION}). Use the location
 *                      directly when its type is {@see TYPE_ITEM_COUNTRY},
 *                      otherwise follow its dcterms:isPartOf up to the country.
 *   - digitisation   : dcterms:format mixes genres ({@see ITEM_SET_GENRE}) and
 *                      digital/technical items ({@see ITEM_SET_DIGITAL}).
 *                      Keep only the latter.
 *
 * These IDs are specific to the DRE Omeka instance populated by the
 * MongoDB2OmekaS pipeline. Porting to another instance? This is the one file
 * to edit. (TODO: verify each ID against the live database before first prod
 * reindex — see README.)
 */
final class FacetConfig
{
    // --- Authority item-set IDs (DRE instance) ---------------------------
    public const ITEM_SET_TYPE     = 1;     // type_of_resource
    public const ITEM_SET_LANGUAGE = 19;    // languages
    public const ITEM_SET_PROJECT  = 20;    // projects
    public const ITEM_SET_LOCATION = 1851;  // locations (countries + cities + regions)
    public const ITEM_SET_SUBJECT  = 1852;  // subjects (LCSH) + tags
    public const ITEM_SET_AUDIENCE = 3169;  // target audiences
    public const ITEM_SET_DIGITAL  = 7438;  // digital/technical properties
    public const ITEM_SET_GENRE    = 21;    // genres (also on dcterms:format — excluded)

    // --- dcterms:type discriminator target item IDs ----------------------
    // Authority items link their dcterms:type to one of these "type" items.
    public const TYPE_ITEM_LCSH         = 3167;
    public const TYPE_ITEM_TAG          = 22199;
    public const TYPE_ITEM_COUNTRY      = 3168;
    public const TYPE_ITEM_GEO_LOCATION = 22431;

    /**
     * Facets in display order: Typesense field => definition.
     *   property : Omeka property term the values come from
     *   label    : default (English) UI label
     *   array    : true => string[] (multi-valued), false => string (single)
     */
    public const FACETS = [
        'type_s'          => ['property' => 'dcterms:type',     'label' => 'Type',                'array' => false],
        'project_s'       => ['property' => 'dcterms:isPartOf', 'label' => 'Project',             'array' => false],
        'country_ss'      => ['property' => 'dcterms:spatial',  'label' => 'Country',             'array' => true],
        'language_ss'     => ['property' => 'dcterms:language', 'label' => 'Language',            'array' => true],
        'subject_ss'      => ['property' => 'dcterms:subject',  'label' => 'Subject',             'array' => true],
        'tag_ss'          => ['property' => 'dcterms:subject',  'label' => 'Tag',                 'array' => true],
        'audience_ss'     => ['property' => 'dcterms:audience', 'label' => 'Target audience',     'array' => true],
        'digitisation_ss' => ['property' => 'dcterms:format',   'label' => 'Digitisation method', 'array' => true],
    ];

    /** @return list<string> Typesense facet field names, in display order. */
    public static function fieldNames(): array
    {
        return array_keys(self::FACETS);
    }

    public static function label(string $field): string
    {
        return self::FACETS[$field]['label'] ?? $field;
    }

    public static function isMultivalued(string $field): bool
    {
        return (bool) (self::FACETS[$field]['array'] ?? true);
    }

    /** @return array<string,array{property:string,label:string,array:bool}> */
    public static function all(): array
    {
        return self::FACETS;
    }
}
