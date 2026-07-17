<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/**
 * Maps one podcast episode (resource template 21, item set 39095) into a
 * Typesense document.
 *
 * Podcasts are hand-curated in Omeka (not from the MongoDB2OmekaS pipeline) and
 * have their own field shape, so they get a dedicated corpus rather than being
 * folded into publications. Each episode belongs to a podcast SERIES
 * (dcterms:isPartOf → a series authority item) and credits hosts (marcrel:hst)
 * and guests (marcrel:spk) — Person items kept with their ids so the card links
 * each one. The links are unambiguous, so facets resolve straight from the linked
 * title (no AuthorityResolver), exactly like {@see ProjectMapper}.
 *
 * Which property feeds which field comes from {@see SearchProfile} (config-driven);
 * the stable Typesense field names (series_s, people_ss, episode, …) are the
 * interface. Podcast-specific shapes:
 *   - abstract  : on dcterms:abstract.
 *   - episode   : bibo:number (numeric:integer) → a sortable int (the default sort).
 *   - people_ss : derived union of hosts + guests (the card labels each by role).
 *   - date_s    : the dcterms:date value shown verbatim on the card; `year` is the
 *                 4-digit run from it (newest/oldest sort).
 *   - url_s     : fabio:hasURL — the external "Listen" link (prefer the URI @id).
 *   - thumbnail : the series logo, resolved by the reindexer hopping
 *                 dcterms:isPartOf (the episode's own media is the audio file).
 */
final class PodcastMapper implements MapperInterface
{
    public function __construct(private readonly SearchProfile $profile)
    {
    }

    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        $bag = new ValueBag($values);
        $doc = [
            'id'        => (string) $item['id'],
            'is_public' => $item['is_public'],
            'title'     => $item['title'] !== '' ? $item['title'] : sprintf('[Untitled #%d]', $item['id']),
        ];

        if (($abstract = $bag->firstLiteral('dcterms:abstract')) !== null) {
            $doc['abstract'] = $abstract;
        }

        $df = $this->profile->displayFields();

        // Configured facets — linked-resource titles (literal fallback). Single-
        // valued facets (series_s) store the first title, multi-valued the deduped
        // list. The derived people_ss facet (property null) is built below.
        foreach ($this->profile->all() as $field => $def) {
            if (empty($def['property'])) {
                continue;
            }
            $titles = $bag->labels($def['property']);
            if ($titles === []) {
                continue;
            }
            $doc[$field] = $this->profile->isMultivalued($field) ? $titles : $titles[0];
        }

        // Hosts + guests, each with their person ids (host_ss[i] ↔ host_ids[i]) so
        // the card links each person to their page.
        [$hostNames, $hostIds] = $bag->people($df['host_ss']['property'] ?? 'marcrel:hst');
        if ($hostNames) {
            $doc['host_ss'] = $hostNames;
            $doc['host_ids'] = $hostIds;
        }
        [$guestNames, $guestIds] = $bag->people($df['guest_ss']['property'] ?? 'marcrel:spk');
        if ($guestNames) {
            $doc['guest_ss'] = $guestNames;
            $doc['guest_ids'] = $guestIds;
        }

        // Sound engineer (marcrel:sde) — a display-only credit line, with person ids
        // so the card can link each one. Not folded into the People facet (it's a
        // production credit, not a browse axis).
        [$engineerNames, $engineerIds] = $bag->people($df['engineer_ss']['property'] ?? 'marcrel:sde');
        if ($engineerNames) {
            $doc['engineer_ss'] = $engineerNames;
            $doc['engineer_ids'] = $engineerIds;
        }

        // Derived People facet — union of hosts + guests.
        if ($this->profile->hasFacet('people_ss')) {
            $people = array_values(array_unique(array_merge($hostNames, $guestNames)));
            if ($people) {
                $doc['people_ss'] = $people;
            }
        }

        // Series item id (parallel to series_s) so the card links the series chip
        // to its Omeka page.
        if (isset($df['series_id'])) {
            $seriesProp = $this->profile->property('series_s') ?? 'dcterms:isPartOf';
            if (($sid = $bag->firstResourceId($seriesProp)) !== null) {
                $doc['series_id'] = (string) $sid;
            }
        }

        // Episode number (sortable int).
        if (($episode = $bag->firstInt($df['episode']['property'] ?? 'bibo:number')) !== null) {
            $doc['episode'] = $episode;
        }

        // External "Listen" link — prefer the URI value's @id, literal fallback.
        if (($url = $bag->firstUrl($df['url_s']['property'] ?? 'fabio:hasURL')) !== null) {
            $doc['url_s'] = $url;
        }

        // Date — the verbatim value for display + the 4-digit year for sorting.
        if (($raw = $bag->firstLiteral($this->profile->dateProperty())) !== null) {
            $doc['date_s'] = $raw;
            if (($year = $bag->firstYear($this->profile->dateProperty())) !== null) {
                $doc['year'] = $year;
            }
        }

        // Transcript — the full-text search payload (often empty today). Indexed for
        // query_by but search_only (excluded from result payloads); `has_transcript`
        // flags availability so the card can show a "Transcript" badge without the
        // text being shipped.
        $transcript = $bag->firstLiteral($df['transcript']['property'] ?? 'bibo:content');
        if ($transcript !== null && $transcript !== '') {
            $doc['transcript'] = $transcript;
            $doc['has_transcript'] = true;
        } else {
            $doc['has_transcript'] = false;
        }

        if ($thumbnailUrl !== null) {
            $doc['thumbnail_url'] = $thumbnailUrl;
        }

        return $doc;
    }

}
