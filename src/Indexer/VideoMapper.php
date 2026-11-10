<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/**
 * Maps one YouTube video (resource template 22, item set 39192) into a Typesense
 * document.
 *
 * The cluster's recorded talks, interviews, panels and screenings published on
 * YouTube. Each video belongs to a YouTube PLAYLIST (dcterms:isPartOf → a playlist
 * authority item), credits SPEAKERS (marcrel:spk → Person items, sparse today but
 * growing) and a language, links out to YouTube (fabio:hasURL), and carries a
 * bibo:content TRANSCRIPT — the full-text search payoff (already populated). Unlike
 * podcasts (whose own media is the audio file), a video's own first media is its
 * poster-frame thumbnail, so the card uses the item's own thumbnail — no
 * thumbnail_property hop.
 *
 * Structurally a near-twin of {@see PodcastMapper}, sharing its link, date and
 * transcript handling ({@see MediaMapper}): links are unambiguous, so the
 * playlist + language facets resolve straight from the linked title (no
 * AuthorityResolver), and speakers are collected with their person ids so the card
 * links each one (exactly like a podcast's hosts/guests).
 *
 * Which property feeds which field comes from {@see SearchProfile} (config-driven);
 * the stable Typesense field names (playlist_s, speaker_ss, …) are the interface.
 * Video-specific shapes:
 *   - abstract   : dcterms:abstract.
 *   - playlist_s : dcterms:isPartOf (single — one video → one playlist); playlist_id
 *                  carries the linked item id so the card links the playlist chip.
 *   - speaker_ss : marcrel:spk, with parallel speaker_ids (the card links each one).
 *   - date_s     : the dcterms:date value shown verbatim; `year` is the 4-digit run
 *                  from it (newest/oldest sort + the year slider).
 *   - url_s      : fabio:hasURL — the external "Watch" link (prefer the URI @id).
 *   - transcript : bibo:content — indexed for query_by but search_only (never shipped
 *                  to the card); `has_transcript` flags availability for the badge.
 */
final class VideoMapper extends MediaMapper
{
    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        $bag = new ValueBag($values);
        $doc = $this->skeleton($item, $bag, 'dcterms:abstract');

        // Configured facets (playlist_s, language_ss). speaker_ss is a facet too
        // but carries parallel person ids, so it is built below.
        $this->addFacetLabels($doc, $bag, ['speaker_ss']);

        // Speakers with their person ids (speaker_ss[i] ↔ speaker_ids[i]); also
        // searchable (query_by).
        if ($this->profile->hasFacet('speaker_ss')) {
            $this->addPeople($doc, $bag, $this->profile->property('speaker_ss'), 'speaker_ss', 'speaker_ids');
        }

        $this->addLinkedId($doc, $bag, 'playlist_s', 'playlist_id');
        $this->addMediaFields($doc, $bag);
        $this->addThumbnail($doc, $thumbnailUrl);
        return $doc;
    }
}
