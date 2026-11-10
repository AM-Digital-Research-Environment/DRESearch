<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

/**
 * What a podcast episode and a YouTube video share beyond the skeleton: an
 * external link, a verbatim date with its year, and a search-only transcript.
 * {@see PodcastMapper} and {@see VideoMapper} were near-twins written out
 * twice; each now adds only its own people and linked ids.
 */
abstract class MediaMapper extends AbstractMapper
{
    /**
     * - url_s: the external "Listen" / "Watch" link, the URI value's @id first,
     *   the literal as a fallback.
     * - date_s: the date value shown verbatim on the card; `year` is its
     *   four-digit run (newest / oldest sort, the year slider).
     * - transcript: indexed for query_by but search_only (never shipped to the
     *   card); `has_transcript` flags it for the card's badge, always set.
     *
     * @param array<string,mixed> $doc
     */
    protected function addMediaFields(array &$doc, ValueBag $bag): void
    {
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

        $transcript = $bag->firstLiteral($this->displayProperty('transcript'));
        if ($transcript !== null && $transcript !== '') {
            $doc['transcript'] = $transcript;
            $doc['has_transcript'] = true;
        } else {
            $doc['has_transcript'] = false;
        }
    }
}
