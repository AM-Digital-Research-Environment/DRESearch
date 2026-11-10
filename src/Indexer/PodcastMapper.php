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
final class PodcastMapper extends MediaMapper
{
    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        $bag = new ValueBag($values);
        $doc = $this->skeleton($item, $bag, 'dcterms:abstract');

        // Configured facets (series_s, language_ss); the derived people_ss is
        // built below.
        $this->addFacetLabels($doc, $bag);

        // Hosts and guests with their person ids (host_ss[i] ↔ host_ids[i]), and
        // the sound engineer: a display-only production credit, not folded into
        // the People facet, which is the hosts and guests.
        $hosts = $this->addPeople($doc, $bag, $this->displayProperty('host_ss'), 'host_ss', 'host_ids');
        $guests = $this->addPeople($doc, $bag, $this->displayProperty('guest_ss'), 'guest_ss', 'guest_ids');
        $this->addPeople($doc, $bag, $this->displayProperty('engineer_ss'), 'engineer_ss', 'engineer_ids');
        $this->addPeopleFacet($doc, $hosts, $guests);

        $this->addLinkedId($doc, $bag, 'series_s', 'series_id');

        // Episode number (a sortable int, the default sort).
        if (($episode = $bag->firstInt($this->displayProperty('episode'))) !== null) {
            $doc['episode'] = $episode;
        }

        $this->addMediaFields($doc, $bag);
        $this->addThumbnail($doc, $thumbnailUrl);
        return $doc;
    }
}
