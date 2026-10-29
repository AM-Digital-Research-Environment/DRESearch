<?php

declare(strict_types=1);

namespace DRESearch\Search;

use DRESearch\Settings\SearchProfile;

/**
 * Turns raw Typesense results into the compact JSON the Svelte client reads:
 * documents with their match highlights, labelled facet counts, and
 * suggestion rows. Pure and stateless; the shapes are the public contract of
 * the search, union, suggest and suggest-all endpoints (docs/public-api.md).
 */
final class ResponseNormalizer
{
    /**
     * A search (or union) response: the documents, each with its `_highlights`
     * when something in it matched, the facets labelled from the profile, and
     * the paging figures.
     *
     * @param array<string,mixed> $result a Typesense search result
     * @return array{available:true, found:int, page:int, hits:list<array<string,mixed>>,
     *     facets:list<array{field:string,label:string,counts:list<array{value:string,count:?int}>}>,
     *     search_time_ms:int}
     */
    public static function search(array $result, SearchProfile $profile): array
    {
        $hits = [];
        foreach (is_array($result['hits'] ?? null) ? $result['hits'] : [] as $hit) {
            $doc = is_array($hit['document'] ?? null) ? $hit['document'] : [];
            $highlights = self::highlights(is_array($hit['highlight'] ?? null) ? $hit['highlight'] : []);
            if ($highlights !== []) {
                $doc['_highlights'] = $highlights;
            }
            $hits[] = $doc;
        }

        $facets = [];
        foreach (is_array($result['facet_counts'] ?? null) ? $result['facet_counts'] : [] as $facet) {
            $field = (string) ($facet['field_name'] ?? '');
            if ($field === '') {
                continue;
            }
            $counts = [];
            foreach (is_array($facet['counts'] ?? null) ? $facet['counts'] : [] as $count) {
                $counts[] = [
                    'value' => (string) ($count['value'] ?? ''),
                    // null = selected but outside the recounted top values.
                    'count' => array_key_exists('count', $count) && $count['count'] === null ? null : (int) ($count['count'] ?? 0),
                ];
            }
            $facets[] = [
                'field'  => $field,
                'label'  => $profile->facetLabel($field),
                'counts' => $counts,
            ];
        }

        return [
            'available'      => true,
            'found'          => (int) ($result['found'] ?? 0),
            'page'           => (int) ($result['page'] ?? 1),
            'hits'           => $hits,
            'facets'         => $facets,
            'search_time_ms' => (int) ($result['search_time_ms'] ?? 0),
        ];
    }

    /**
     * Flatten a Typesense per-hit `highlight` object into `field => list<snippet>`,
     * keeping only fields/elements that actually matched (i.e. carry a highlight
     * sentinel). The full highlighted value (`value`/`values`, present for fields
     * in highlight_full_fields) is preferred over the windowed `snippet`/`snippets`
     * so a card can highlight a complete linked value; abstract/description, which
     * aren't full-highlighted, fall back to their centred snippet. The marks are
     * the {@see QueryBuilder} private-use sentinels, converted to <mark> client-side.
     *
     * @param array<mixed> $highlight
     * @return array<string,list<string>>
     */
    public static function highlights(array $highlight): array
    {
        $out = [];
        foreach ($highlight as $field => $info) {
            if (!is_string($field) || !is_array($info)) {
                continue;
            }
            $candidates = [];
            if (array_is_list($info)) {
                // A string[] field (creator_ss, subject_ss, …): with the v2
                // highlight format Typesense returns one {matched_tokens,
                // snippet[, value]} object per array ELEMENT, unmarked ones
                // included. Prefer the full value (highlight_full_fields) so the
                // client can key it back onto the chip it renders.
                foreach ($info as $element) {
                    if (!is_array($element)) {
                        continue;
                    }
                    $text = $element['value'] ?? $element['snippet'] ?? null;
                    if (is_string($text)) {
                        $candidates[] = $text;
                    }
                }
            } elseif (isset($info['value']) && is_string($info['value'])) {
                $candidates[] = $info['value'];
            } elseif (isset($info['snippet']) && is_string($info['snippet'])) {
                $candidates[] = $info['snippet'];
            }
            foreach (['values', 'snippets'] as $key) {
                if ($candidates === [] && isset($info[$key]) && is_array($info[$key])) {
                    foreach ($info[$key] as $s) {
                        if (is_string($s)) {
                            $candidates[] = $s;
                        }
                    }
                }
            }
            // Keep only the ones that were actually marked.
            $marked = array_values(array_filter(
                $candidates,
                static fn(string $s): bool => str_contains($s, QueryBuilder::HL_START)
            ));
            if ($marked !== []) {
                $out[$field] = $marked;
            }
        }
        return $out;
    }

    /**
     * The slider bounds from a {@see QueryBuilder::yearStats()} result: the
     * smallest minimum and largest maximum over the profile's year field(s), or
     * null when the corpus holds no dated record.
     *
     * @param array<string,mixed> $result
     * @return array{min:int,max:int}|null
     */
    public static function yearBounds(array $result, SearchProfile $profile): ?array
    {
        $statFields = array_flip($profile->yearStatFields());
        $min = null;
        $max = null;
        foreach (is_array($result['facet_counts'] ?? null) ? $result['facet_counts'] : [] as $facet) {
            if (!is_array($facet) || !isset($statFields[$facet['field_name'] ?? ''])) {
                continue;
            }
            $stats = is_array($facet['stats'] ?? null) ? $facet['stats'] : [];
            if (isset($stats['min'])) {
                $min = $min === null ? (int) $stats['min'] : min($min, (int) $stats['min']);
            }
            if (isset($stats['max'])) {
                $max = $max === null ? (int) $stats['max'] : max($max, (int) $stats['max']);
            }
        }
        return $min === null || $max === null || $max < $min ? null : ['min' => $min, 'max' => $max];
    }

    /**
     * Autocomplete rows for one corpus: id, title and the profile's subtitle.
     *
     * @param array<string,mixed> $result a Typesense search result
     * @return list<array{id:string,title:string,subtitle:?string}>
     */
    public static function suggestions(array $result, SearchProfile $profile): array
    {
        $rows = [];
        foreach (is_array($result['hits'] ?? null) ? $result['hits'] : [] as $hit) {
            $doc = is_array($hit['document'] ?? null) ? $hit['document'] : [];
            $rows[] = [
                'id'       => (string) ($doc['id'] ?? ''),
                'title'    => (string) ($doc['title'] ?? ''),
                'subtitle' => self::subtitle($profile, $doc),
            ];
        }
        return $rows;
    }

    /**
     * The "·"-joined line under a suggestion's title, from the profile's
     * {@see SearchProfile::subtitleFields()}: each part is the first of its
     * fields with a value (a list field gives its first value); parts without
     * one are left out.
     *
     * @param array<string,mixed> $doc
     */
    public static function subtitle(SearchProfile $profile, array $doc): ?string
    {
        $parts = [];
        foreach ($profile->subtitleFields() as $alternatives) {
            foreach ($alternatives as $field) {
                $value = self::subtitleValue($doc, $field);
                if ($value !== null) {
                    $parts[] = $value;
                    break;
                }
            }
        }
        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** @param array<string,mixed> $doc */
    private static function subtitleValue(array $doc, string $field): ?string
    {
        if ($field === SearchProfile::SUBTITLE_YEAR_RANGE) {
            if (!isset($doc['year_start'])) {
                return null;
            }
            $start = (int) $doc['year_start'];
            $end = isset($doc['year_end']) ? (int) $doc['year_end'] : null;
            return $end !== null && $end !== $start ? $start . '–' . $end : (string) $start;
        }
        $value = $doc[$field] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return is_string($value) && $value !== '' ? $value : null;
    }
}
