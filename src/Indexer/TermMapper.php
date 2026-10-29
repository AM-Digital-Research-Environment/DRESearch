<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/**
 * Maps one authority term — a genre, language, location, or subject/tag — into a
 * Typesense document. This is the generic mapper behind the `term` kind: every
 * such corpus is an authority item set whose substance lives in the *reverse*
 * direction, exactly like the people and organisations corpora.
 *
 * A term record itself carries almost nothing — a display title and, for some
 * corpora, a dcterms:type that distinguishes sub-kinds (a location is a Country or
 * a geographic location; a subject is an LCSH heading or a tag). Everything else a
 * useful card wants is the count of public records that reference the term, which
 * {@see DocumentAssembler} computes from `reverse_links` config and hands in via
 * $item['counts'] (and $item['roles'] for any corpus that derives roles).
 *
 * That part is the people and organisations mapping, so it is delegated to
 * {@see ReverseLinkMapper}; a term adds only the optional geopoint of the
 * location corpora. Which property feeds which facet comes from
 * {@see SearchProfile} (config-driven); the stable field names (type_s,
 * item_count, publication_count, …) are the interface.
 */
final class TermMapper implements MapperInterface
{
    private readonly ReverseLinkMapper $entity;

    public function __construct(private readonly SearchProfile $profile)
    {
        $this->entity = new ReverseLinkMapper($profile);
    }

    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        $doc = $this->entity->map($item, $values, $thumbnailUrl);

        // Location profiles opt into a geopoint field. Typesense's coordinate
        // order is [latitude, longitude]; MapLibre swaps it at presentation time.
        if (isset($this->profile->displayFields()['geo'])) {
            $bag = new ValueBag($values);
            $lat = $bag->firstFloat('geo:lat');
            $lng = $bag->firstFloat('geo:long');
            $valid = $lat !== null && $lng !== null
                && $lat >= -90.0 && $lat <= 90.0
                && $lng >= -180.0 && $lng <= 180.0;
            $doc['has_coords'] = $valid;
            if ($valid) {
                $doc['geo'] = [$lat, $lng];
            }
        }

        return $doc;
    }
}
