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
 * Structurally a near-twin of {@see PodcastMapper}: links are unambiguous, so the
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
final class VideoMapper implements MapperInterface
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
        // valued facets (playlist_s) store the first title, multi-valued the deduped
        // list (language_ss). speaker_ss is a facet too but carries parallel person
        // ids, so it's built explicitly below — skip it here.
        foreach ($this->profile->all() as $field => $def) {
            if ($field === 'speaker_ss' || empty($def['property'])) {
                continue;
            }
            $titles = $bag->labels($def['property']);
            if ($titles === []) {
                continue;
            }
            $doc[$field] = $this->profile->isMultivalued($field) ? $titles : $titles[0];
        }

        // Speakers (marcrel:spk), each with their person ids (speaker_ss[i] ↔
        // speaker_ids[i]) so the card links each person to their page. This field is
        // also searchable (query_by). Empty in the data today; populates over time.
        if ($this->profile->hasFacet('speaker_ss')) {
            [$speakerNames, $speakerIds] = $bag->people($this->profile->property('speaker_ss') ?? 'marcrel:spk');
            if ($speakerNames) {
                $doc['speaker_ss'] = $speakerNames;
                $doc['speaker_ids'] = $speakerIds;
            }
        }

        // Playlist item id (parallel to playlist_s) so the card links the playlist
        // chip to its Omeka page.
        if (isset($df['playlist_id'])) {
            $playlistProp = $this->profile->property('playlist_s') ?? 'dcterms:isPartOf';
            if (($pid = $bag->firstResourceId($playlistProp)) !== null) {
                $doc['playlist_id'] = (string) $pid;
            }
        }

        // External "Watch" link — prefer the URI value's @id, literal fallback.
        if (($url = $bag->firstUrl($df['url_s']['property'] ?? 'fabio:hasURL')) !== null) {
            $doc['url_s'] = $url;
        }

        // Date — the verbatim value for display + the 4-digit year for sorting/slider.
        if (($raw = $bag->firstLiteral($this->profile->dateProperty())) !== null) {
            $doc['date_s'] = $raw;
            if (($year = $bag->firstYear($this->profile->dateProperty())) !== null) {
                $doc['year'] = $year;
            }
        }

        // Transcript — the full-text search payload. Indexed for query_by but
        // search_only (excluded from result payloads); `has_transcript` flags
        // availability so the card can show a "Transcript" badge without shipping
        // the (potentially huge) text.
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
