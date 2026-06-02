<?php
declare(strict_types=1);

namespace DRESearch\Search;

use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;

/**
 * Server-side search: runs the Typesense query for a given search profile with
 * the server-held key and normalises the response into the compact shape the
 * Svelte client expects. Every method is null-safe — when Typesense isn't
 * configured/reachable, or the profile is unknown, it returns an
 * "available: false" payload rather than throwing, so the block shows a quiet
 * notice instead of breaking the page.
 */
final class SearchProxy
{
    public function __construct(
        private readonly TypesenseClientProvider $provider,
        private readonly ProfileRegistry $registry,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->provider->isConfigured();
    }

    /** @param array<string,mixed> $req */
    public function search(string $profileName, array $req): array
    {
        $profile = $this->registry->get($profileName);
        $client = $this->provider->getClient();
        if ($profile === null || $client === null) {
            return $this->unavailable();
        }
        try {
            $result = $client->collections[$profile->collection()]
                ->documents
                ->search((new QueryBuilder($profile))->search($req));
        } catch (\Throwable $e) {
            return $this->unavailable($e->getMessage());
        }
        return $this->normalize($result, $profile);
    }

    public function suggest(string $profileName, string $q): array
    {
        $q = trim($q);
        $profile = $this->registry->get($profileName);
        $client = $this->provider->getClient();
        if ($profile === null || $client === null || $q === '') {
            return ['available' => $client !== null && $profile !== null, 'suggestions' => []];
        }
        try {
            $result = $client->collections[$profile->collection()]
                ->documents
                ->search((new QueryBuilder($profile))->suggest($q));
        } catch (\Throwable $e) {
            return ['available' => false, 'suggestions' => []];
        }

        $suggestions = [];
        foreach ($result['hits'] ?? [] as $hit) {
            $doc = $hit['document'] ?? [];
            $suggestions[] = [
                'id'       => (string) ($doc['id'] ?? ''),
                'title'    => (string) ($doc['title'] ?? ''),
                'subtitle' => $this->subtitle($profile, $doc),
            ];
        }
        return ['available' => true, 'suggestions' => $suggestions];
    }

    /**
     * Global year span for the slider bounds, from the profile's date field(s)
     * (`year`, or the `year_start`/`year_end` pair). Null when the profile has
     * no year facet or Typesense is unavailable.
     *
     * @return array{min:int, max:int}|null
     */
    public function yearBounds(string $profileName): ?array
    {
        $profile = $this->registry->get($profileName);
        $client = $this->provider->getClient();
        if ($profile === null || $client === null || !$profile->hasYearFacet()) {
            return null;
        }
        try {
            $result = $client->collections[$profile->collection()]
                ->documents
                ->search((new QueryBuilder($profile))->yearStats());
        } catch (\Throwable $e) {
            return null;
        }

        $statFields = array_flip($profile->yearStatFields());
        $min = null;
        $max = null;
        foreach ($result['facet_counts'] ?? [] as $facet) {
            if (!isset($statFields[$facet['field_name'] ?? ''])) {
                continue;
            }
            $stats = $facet['stats'] ?? [];
            if (isset($stats['min'])) {
                $min = $min === null ? (int) $stats['min'] : min($min, (int) $stats['min']);
            }
            if (isset($stats['max'])) {
                $max = $max === null ? (int) $stats['max'] : max($max, (int) $stats['max']);
            }
        }
        if ($min === null || $max === null || $max < $min) {
            return null;
        }
        return ['min' => $min, 'max' => $max];
    }

    private function normalize(array $result, SearchProfile $profile): array
    {
        $hits = [];
        foreach ($result['hits'] ?? [] as $hit) {
            $doc = $hit['document'] ?? [];
            if (isset($hit['highlight']['title']['snippet'])) {
                $doc['_title_highlight'] = $hit['highlight']['title']['snippet'];
            }
            $hits[] = $doc;
        }

        $facets = [];
        foreach ($result['facet_counts'] ?? [] as $facet) {
            $field = (string) ($facet['field_name'] ?? '');
            if ($field === '') {
                continue;
            }
            $counts = [];
            foreach ($facet['counts'] ?? [] as $count) {
                $counts[] = [
                    'value' => (string) ($count['value'] ?? ''),
                    'count' => (int) ($count['count'] ?? 0),
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

    /** A short "·"-joined subtitle for an autocomplete row, by profile kind. */
    private function subtitle(SearchProfile $profile, array $doc): ?string
    {
        if ($profile->kind() === 'project') {
            $section = $doc['section_ss'][0] ?? null;
            $parts = [$section, $this->yearRange($doc)];
        } elseif ($profile->kind() === 'publication') {
            $author = $doc['author_ss'][0] ?? ($doc['type_s'] ?? null);
            $parts = [$author, isset($doc['year']) ? (string) $doc['year'] : null];
        } elseif ($profile->kind() === 'person') {
            $parts = [$doc['affiliation_ss'][0] ?? null, $doc['roles_ss'][0] ?? null];
        } elseif ($profile->kind() === 'section') {
            $leader = $doc['pi_ss'][0] ?? ($doc['spokesperson_ss'][0] ?? null);
            $parts = [$doc['phase_s'] ?? null, $leader];
        } else {
            $parts = [$doc['type_s'] ?? null, isset($doc['year']) ? (string) $doc['year'] : null];
        }
        $parts = array_filter($parts, static fn($p) => $p !== null && $p !== '');
        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function yearRange(array $doc): ?string
    {
        $start = isset($doc['year_start']) ? (int) $doc['year_start'] : null;
        $end = isset($doc['year_end']) ? (int) $doc['year_end'] : null;
        if ($start === null) {
            return null;
        }
        return ($end !== null && $end !== $start) ? $start . '–' . $end : (string) $start;
    }

    private function unavailable(?string $error = null): array
    {
        return [
            'available' => false,
            'found'     => 0,
            'page'      => 1,
            'hits'      => [],
            'facets'    => [],
            'error'     => $error,
        ];
    }
}
