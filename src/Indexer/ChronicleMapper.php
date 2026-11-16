<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/**
 * Maps one Cluster Chronicles work (resource template 34, item set 50105) into
 * a Typesense document.
 *
 * The Chronicles are the works of the cluster's writers in residence: essays,
 * poems, photographs and drawings, events and a video, one item per work, each
 * dcterms:isPartOf the series item. Unlike podcasts and videos they have no
 * type property, so the type is the resource class, labelled by the profile's
 * `class_types` (fabio:Essay => Essay); the reindexer loads the class with the
 * row ({@see OmekaSourceRepository::rows()}). A work credits people in several
 * roles (author, photographer, artist, translator, speaker, moderator,
 * curator) and may depict people; each role keeps its names with their person
 * ids so the card links each one, and all of them make the People facet.
 *
 * Which property feeds which field comes from {@see SearchProfile}; the stable
 * Typesense field names are the interface:
 *   - type_s        : the resource class's label; absent for an unlisted class.
 *   - <role>_ss/_ids: the people credited in each role, see ROLES.
 *   - people_ss     : derived union of every role, the depicted people last.
 *   - number        : bibo:number, the work's place in its writer's sequence.
 *   - date_s / year : dcterms:date verbatim (a year, a month or a day) and its year.
 *   - url_s         : fabio:hasURL, the work on the cluster's website.
 *   - content       : bibo:content, the full text, often French and English in
 *                     one value: indexed but search_only (never shipped to the
 *                     card); `has_content` flags it, always set.
 */
final class ChronicleMapper extends AbstractMapper
{
    /** The person fields, each with its parallel ids field, in card order. */
    public const ROLES = [
        'author_ss'       => 'author_ids',
        'photographer_ss' => 'photographer_ids',
        'artist_ss'       => 'artist_ids',
        'translator_ss'   => 'translator_ids',
        'speaker_ss'      => 'speaker_ids',
        'moderator_ss'    => 'moderator_ids',
        'curator_ss'      => 'curator_ids',
        'depicted_ss'     => 'depicted_ids',
    ];

    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        $bag = new ValueBag($values);
        $doc = $this->skeleton($item, $bag, 'dcterms:abstract');

        // Configured facets (subject_ss, place_ss, language_ss); type_s and
        // people_ss are derived below.
        $this->addFacetLabels($doc, $bag);

        $type = $this->profile->classTypes()[$item['resource_class'] ?? ''] ?? null;
        if ($type !== null && $this->profile->hasFacet('type_s')) {
            $doc['type_s'] = $type;
        }

        if (($alternative = $bag->firstLiteral($this->displayProperty('alternative_s'))) !== null) {
            $doc['alternative_s'] = $alternative;
        }

        $people = [];
        foreach (self::ROLES as $field => $idsField) {
            $people[] = $this->addPeople($doc, $bag, $this->displayProperty($field), $field, $idsField);
        }
        $this->addPeopleFacet($doc, ...$people);

        if (($number = $bag->firstInt($this->displayProperty('number'))) !== null) {
            $doc['number'] = $number;
        }

        if (($url = $bag->firstUrl($this->displayProperty('url_s'))) !== null) {
            $doc['url_s'] = $url;
        }

        $dateTerm = $this->profile->dateProperty();
        if (($raw = $bag->firstLiteral($dateTerm)) !== null) {
            $doc['date_s'] = $raw;
            if (($year = $bag->firstYear($dateTerm)) !== null) {
                $doc['year'] = $year;
            }
        }

        $content = $bag->firstLiteral($this->displayProperty('content'));
        if ($content !== null) {
            $doc['content'] = $content;
        }
        $doc['has_content'] = $content !== null;

        $this->addThumbnail($doc, $thumbnailUrl);
        return $doc;
    }
}
