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
     * Federated autocomplete across every profile in one Typesense `multi_search`
     * round-trip. Returns suggestions grouped by corpus, each group tagged with
     * the profile's translated label + kind so the client can show a type badge.
     * Empty corpora are dropped. Null-safe: no client → available:false, empty
     * groups; a thrown multi_search → available:false. `is_public:=true` is
     * enforced per search by {@see QueryBuilder::suggest()}.
     *
     * @param callable(string):string|null $translate translates the profile label (defaults to identity)
     * @return array{available:bool, groups:list<array{profile:string,label:string,kind:string,suggestions:list<array{id:string,title:string,subtitle:?string}>}>}
     */
    public function suggestAll(string $q, ?callable $translate = null): array
    {
        $q = trim($q);
        $client = $this->provider->getClient();
        if ($client === null || $q === '') {
            return ['available' => $client !== null, 'groups' => []];
        }
        $translate ??= static fn(string $s): string => $s;

        $profiles = array_values($this->registry->all());
        $searches = [];
        foreach ($profiles as $profile) {
            $searches[] = (new QueryBuilder($profile))->suggestSearch($q);
        }

        try {
            $response = $client->multiSearch->perform(['searches' => $searches]);
        } catch (\Throwable $e) {
            return ['available' => false, 'groups' => []];
        }

        // multi_search preserves request order; a per-search failure (e.g. a
        // not-yet-indexed collection) yields an entry with no 'hits' → skipped.
        $results = $response['results'] ?? [];
        $groups = [];
        foreach ($profiles as $i => $profile) {
            $hits = $results[$i]['hits'] ?? [];
            if ($hits === []) {
                continue;
            }
            $suggestions = [];
            foreach ($hits as $hit) {
                $doc = $hit['document'] ?? [];
                $suggestions[] = [
                    'id'       => (string) ($doc['id'] ?? ''),
                    'title'    => (string) ($doc['title'] ?? ''),
                    'subtitle' => $this->subtitle($profile, $doc),
                ];
            }
            $groups[] = [
                'profile'     => $profile->name(),
                'label'       => $translate($profile->label()),
                'kind'        => $profile->kind(),
                'suggestions' => $suggestions,
            ];
        }
        return ['available' => true, 'groups' => $groups];
    }

    /**
     * Federated results-page search: a per-corpus match COUNT for every profile
     * (the type tabs) in one `multi_search`, plus the focused corpus's full
     * faceted response (reusing {@see search()}). Counts use only the shared
     * free-text + optional year window, NOT per-corpus facet filters, so each tab
     * shows how many records match the query regardless of the active corpus's
     * sidebar selections. Counts are non-fatal — on failure the tabs just lack a
     * number; the active search still runs and drives `available`.
     *
     * @param array<string,mixed> $req shared: q, year_from, year_to; focused: page, sort, filters, facets, per_page, locked_filter
     * @return array{available:bool, counts:array<string,int>, active:array<string,mixed>}
     */
    public function searchAll(string $activeProfile, array $req): array
    {
        $client = $this->provider->getClient();
        if ($client === null) {
            return ['available' => false, 'counts' => [], 'active' => $this->unavailable()];
        }

        $profiles = array_values($this->registry->all());
        $countReq = [
            'q'         => (string) ($req['q'] ?? ''),
            'year_from' => $req['year_from'] ?? null,
            'year_to'   => $req['year_to'] ?? null,
        ];
        $searches = [];
        foreach ($profiles as $profile) {
            $searches[] = (new QueryBuilder($profile))->countOnly($countReq);
        }

        $counts = [];
        try {
            $response = $client->multiSearch->perform(['searches' => $searches]);
            foreach ($profiles as $i => $profile) {
                $counts[$profile->name()] = (int) ($response['results'][$i]['found'] ?? 0);
            }
        } catch (\Throwable $e) {
            $counts = []; // counts are non-fatal; the active search still runs
        }

        $active = $this->search($activeProfile, $req);
        return [
            'available' => (bool) ($active['available'] ?? false),
            'counts'    => $counts,
            'active'    => $active,
        ];
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
        } elseif ($profile->kind() === 'organisation') {
            // Type (Institution / Group) + the first role it plays.
            $parts = [$doc['type_s'] ?? null, $doc['roles_ss'][0] ?? null];
        } elseif ($profile->kind() === 'term') {
            // Sub-type, if the corpus has one (Country / Tag / …).
            $parts = [$doc['type_s'] ?? null];
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
