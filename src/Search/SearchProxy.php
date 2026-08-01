<?php
declare(strict_types=1);

namespace DRESearch\Search;

use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use DRESearch\Search\Exception\RequestValidationException;
use Laminas\Log\LoggerInterface;

/**
 * Server-side search: runs the Typesense query for a given search profile with
 * the server-held key and normalises the response into the compact shape the
 * Svelte client expects. Backend outages return a stable "available: false"
 * payload; invalid profile names and request data are rejected at the public
 * boundary with a structured validation error.
 */
final class SearchProxy
{
    /** @var array<string,array{expires:int,counts:array<string,int>}> */
    private static array $countCache = [];
    /** @var array<string,array{expires:int,bounds:?array{min:int,max:int}}> */
    private static array $yearCache = [];

    public function __construct(
        private readonly TypesenseClientProvider $provider,
        private readonly ProfileRegistry $registry,
        private readonly BlockScopeResolver $scopeResolver,
        private readonly LoggerInterface $logger,
        /** @var list<string> */
        private readonly array $unionProfiles = [],
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->provider->isConfigured();
    }

    /** @param array<string,mixed> $req */
    public function search(string $profileName, array $req, ?string $requestId = null): array
    {
        [$profile, $req, $serverFilter] = $this->prepare($profileName, $req, 'search');
        $client = $this->provider->getClient();
        if ($client === null) {
            return $this->unavailable($requestId);
        }
        $started = hrtime(true);
        $params = (new QueryBuilder($profile, $serverFilter))->search($req);
        $collection = $client->collections[$profile->collection()];
        try {
            $result = $collection->documents->search($params);
        } catch (\Throwable $e) {
            // A missing stopword set (e.g. a fresh Typesense volume not yet
            // provisioned by a reindex) would otherwise 404 the whole search.
            // Stopwords are an enhancement, not a correctness requirement — drop
            // them and retry once. Restore filtering with "Reindex all corpora" or
            // the Maintenance "Sync stopwords" action.
            if (isset($params['stopwords']) && $this->isStopwordError($e)) {
                unset($params['stopwords']);
                try {
                    $result = $collection->documents->search($params);
                } catch (\Throwable $retry) {
                    $this->logBackendFailure('search', $retry, $profile, $requestId);
                    return $this->unavailable($requestId);
                }
            } else {
                $this->logBackendFailure('search', $e, $profile, $requestId);
                return $this->unavailable($requestId);
            }
        }
        $normalized = $this->normalize($result, $profile);
        $this->logMetric('search', $started, $profile, $requestId, ['found' => $normalized['found']]);
        return $normalized;
    }

    /**
     * Bulk citation pull behind the result-export menu: the CURRENT result set
     * (same query / filters / year window / sort as the live search) paged
     * server-side at {@see QueryBuilder::EXPORT_PER_PAGE} up to
     * {@see QueryBuilder::EXPORT_MAX_HITS}. Returns the raw documents (citation /
     * display fields only — no facets, no highlights, no `is_public`); the client
     * serializes them to txt / json / ris / bibtex. A file is deliverable only
     * when every expected page was returned; partial exports fail closed.
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public function export(string $profileName, array $req, ?string $requestId = null): array
    {
        [$profile, $req, $serverFilter] = $this->prepare($profileName, $req, 'export');
        $client = $this->provider->getClient();
        if ($client === null) {
            return $this->unavailableExport($requestId);
        }
        $started = hrtime(true);
        $collection = $client->collections[$profile->collection()];
        $builder = new QueryBuilder($profile, $serverFilter);
        $maxHits = QueryBuilder::EXPORT_MAX_HITS;
        $pages = (int) ceil($maxHits / QueryBuilder::EXPORT_PER_PAGE);

        $docs = [];
        $found = 0;
        // Mirror search()'s missing-stopword-set degradation: drop stopwords and
        // retry the page once, then carry on without them.
        $useStopwords = true;
        for ($page = 1; $page <= $pages; $page++) {
            $params = $builder->export($req, $page);
            if (!$useStopwords) {
                unset($params['stopwords']);
            }
            try {
                $result = $collection->documents->search($params);
            } catch (\Throwable $e) {
                if ($useStopwords && isset($params['stopwords']) && $this->isStopwordError($e)) {
                    $useStopwords = false;
                    $page--; // retry this page without stopwords
                    continue;
                }
                $this->logBackendFailure('export', $e, $profile, $requestId);
                return $this->unavailableExport($requestId);
            }
            $found = (int) ($result['found'] ?? 0);
            foreach ($result['hits'] ?? [] as $hit) {
                $docs[] = $hit['document'] ?? [];
            }
            if (count($docs) >= $found || count($docs) >= $maxHits) {
                break;
            }
        }

        $docs = array_slice($docs, 0, $maxHits);
        $expected = min($found, $maxHits);
        if (count($docs) !== $expected) {
            $error = new \RuntimeException(sprintf(
                'Typesense returned %d of %d expected export documents.',
                count($docs),
                $expected,
            ));
            $this->logBackendFailure('export_verification', $error, $profile, $requestId);
            return $this->unavailableExport($requestId);
        }
        $response = [
            'available' => true,
            'found'     => $found,
            'exported'  => count($docs),
            'capped'    => $found > $maxHits,
            'complete'  => true,
            'docs'      => $docs,
        ];
        $this->logMetric('export', $started, $profile, $requestId, ['found' => $found, 'exported' => count($docs)]);
        return $response;
    }

    public function suggest(
        string $profileName,
        string $q,
        ?int $blockId = null,
        ?string $requestId = null,
    ): array
    {
        $q = SearchRequest::query($q);
        $profile = $this->registry->get($profileName);
        if ($profile === null) {
            throw new RequestValidationException('unknown_profile', 'Unknown search profile.');
        }
        $serverFilter = $this->scopeResolver->resolve($blockId, $profile->name());
        $client = $this->provider->getClient();
        if ($client === null || $q === '') {
            return ['available' => $client !== null, 'suggestions' => []];
        }
        try {
            $result = $client->collections[$profile->collection()]
                ->documents
                ->search((new QueryBuilder($profile, $serverFilter))->suggest($q));
        } catch (\Throwable $e) {
            $this->logBackendFailure('suggest', $e, $profile, $requestId);
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
    public function suggestAll(
        string $q,
        ?callable $translate = null,
        ?string $requestId = null,
    ): array
    {
        $q = SearchRequest::query($q);
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
            $profile = $profiles[0] ?? $this->registry->default();
            if ($profile !== null) {
                $this->logBackendFailure('suggest_all', $e, $profile, $requestId);
            }
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
     * @param array<string,mixed> $req shared: q, year_from, year_to; focused: page, sort, filters, facets, per_page
     * @return array{available:bool, counts:array<string,int>, active:array<string,mixed>}
     */
    public function searchAll(string $activeProfile, array $req, ?string $requestId = null): array
    {
        [$profile, $req] = $this->prepare($activeProfile, $req, 'search');
        $activeProfile = $profile->name();
        $client = $this->provider->getClient();
        if ($client === null) {
            return ['available' => false, 'counts' => [], 'active' => $this->unavailable($requestId)];
        }

        $profiles = array_values($this->registry->all());
        $countReq = [
            'q'         => (string) ($req['q'] ?? ''),
            'year_from' => $req['year_from'] ?? null,
            'year_to'   => $req['year_to'] ?? null,
        ];
        $counts = [];
        if (!empty($req['include_counts'])) {
            $cacheKey = hash('sha256', json_encode($countReq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            $cached = self::$countCache[$cacheKey] ?? null;
            if ($cached !== null && $cached['expires'] >= time()) {
                $counts = $cached['counts'];
            } else {
                $searches = [];
                foreach ($profiles as $countProfile) {
                    $searches[] = (new QueryBuilder($countProfile))->countOnly($countReq);
                }
                try {
                    $response = $client->multiSearch->perform(['searches' => $searches]);
                    foreach ($profiles as $i => $countProfile) {
                        $counts[$countProfile->name()] = (int) ($response['results'][$i]['found'] ?? 0);
                    }
                    self::$countCache[$cacheKey] = ['expires' => time() + 30, 'counts' => $counts];
                } catch (\Throwable $e) {
                    $this->logBackendFailure('federated_count', $e, $profile, $requestId);
                    $counts = [];
                }
            }
        }

        $active = $this->search($activeProfile, $req, $requestId);
        return [
            'available' => (bool) ($active['available'] ?? false),
            'counts'    => $counts,
            'active'    => $active,
        ];
    }

    /**
     * One merged Typesense v30 union result stream. The configured scope is
     * curated toward content and named entities so authority vocabularies do not
     * overwhelm the ranking. The server-held key never reaches the browser.
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public function union(array $req, ?string $requestId = null): array
    {
        $validated = SearchRequest::union($req);
        $client = $this->provider->getClient();
        if ($client === null) {
            return $this->unavailable($requestId);
        }

        $profiles = [];
        $names = $this->unionProfiles !== [] ? $this->unionProfiles : $this->registry->names();
        foreach ($names as $name) {
            $profile = $this->registry->get((string) $name);
            if ($profile !== null) {
                $profiles[] = $profile;
            }
        }
        if ($profiles === []) {
            throw new RequestValidationException('empty_union_scope', 'No search profiles are configured for merged search.');
        }

        $searches = [];
        foreach ($profiles as $profile) {
            $searches[] = (new QueryBuilder($profile))->union($validated['q']);
        }
        $started = hrtime(true);
        try {
            $result = $client->multiSearch->perform(
                ['union' => true, 'searches' => $searches],
                ['page' => $validated['page'], 'per_page' => $validated['per_page']],
            );
        } catch (\Throwable $e) {
            $this->logBackendFailure('union', $e, $profiles[0], $requestId);
            return $this->unavailable($requestId);
        }

        $normalized = $this->normalize($result, $profiles[0]);
        foreach ($normalized['hits'] as &$doc) {
            $profile = $this->registry->get((string) ($doc['_profile'] ?? ''));
            $doc['_profile_label'] = $profile?->label() ?? (string) ($doc['_profile'] ?? '');
        }
        unset($doc);
        $this->logMetric('union', $started, $profiles[0], $requestId, ['found' => $normalized['found']]);
        return $normalized;
    }

    /**
     * Return up to MAP_MAX_HITS geocoded documents matching one location
     * profile's current query/filter scope. Paging stays server-side so the map
     * represents the result set rather than only the visible list page.
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public function map(string $profileName, array $req, ?string $requestId = null): array
    {
        [$profile, $validated, $serverFilter] = $this->prepare($profileName, $req, 'search');
        if (!isset($profile->displayFields()['geo'], $profile->displayFields()['has_coords'])) {
            throw new RequestValidationException('map_unavailable', 'This search profile does not provide map coordinates.');
        }
        $client = $this->provider->getClient();
        if ($client === null) {
            return $this->unavailableMap($requestId);
        }

        $builder = new QueryBuilder($profile, $serverFilter);
        $collection = $client->collections[$profile->collection()];
        $docs = [];
        $found = 0;
        $pages = (int) ceil(QueryBuilder::MAP_MAX_HITS / QueryBuilder::EXPORT_PER_PAGE);
        $started = hrtime(true);
        for ($page = 1; $page <= $pages; $page++) {
            try {
                $result = $collection->documents->search($builder->map($validated, $page));
            } catch (\Throwable $e) {
                $this->logBackendFailure('map', $e, $profile, $requestId);
                return $this->unavailableMap($requestId);
            }
            $found = (int) ($result['found'] ?? 0);
            foreach ($result['hits'] ?? [] as $hit) {
                if (is_array($hit['document'] ?? null)) {
                    $docs[] = $hit['document'];
                }
            }
            if (count($docs) >= $found || count($docs) >= QueryBuilder::MAP_MAX_HITS) {
                break;
            }
        }
        $docs = array_slice($docs, 0, QueryBuilder::MAP_MAX_HITS);
        $this->logMetric('map', $started, $profile, $requestId, ['found' => $found, 'mapped' => count($docs)]);
        return [
            'available' => true,
            'found' => $found,
            'mapped' => count($docs),
            'capped' => $found > QueryBuilder::MAP_MAX_HITS,
            'docs' => $docs,
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
        $cacheKey = $profile->name();
        $cached = self::$yearCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expires'] >= time()) {
            return $cached['bounds'];
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
        $bounds = ['min' => $min, 'max' => $max];
        self::$yearCache[$cacheKey] = ['expires' => time() + 300, 'bounds' => $bounds];
        return $bounds;
    }

    private function normalize(array $result, SearchProfile $profile): array
    {
        $hits = [];
        foreach ($result['hits'] ?? [] as $hit) {
            $doc = $hit['document'] ?? [];
            $highlights = $this->extractHighlights($hit['highlight'] ?? []);
            if ($highlights !== []) {
                $doc['_highlights'] = $highlights;
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

    /**
     * Flatten a Typesense per-hit `highlight` object into `field => list<snippet>`,
     * keeping only fields/elements that actually matched (i.e. carry a highlight
     * sentinel). The full highlighted value (`value`/`values`, present for fields
     * in highlight_full_fields) is preferred over the windowed `snippet`/`snippets`
     * so a card can highlight a complete linked value; abstract/description, which
     * aren't full-highlighted, fall back to their centred snippet. The marks are
     * the {@see QueryBuilder} private-use sentinels, converted to <mark> client-side.
     *
     * @param array<string,mixed> $highlight
     * @return array<string,list<string>>
     */
    private function extractHighlights(array $highlight): array
    {
        $out = [];
        foreach ($highlight as $field => $info) {
            if (!is_string($field) || !is_array($info)) {
                continue;
            }
            $candidates = [];
            if (isset($info['value']) && is_string($info['value'])) {
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

    /** A short "·"-joined subtitle for an autocomplete row, by profile kind. */
    private function subtitle(SearchProfile $profile, array $doc): ?string
    {
        if ($profile->kind() === 'project') {
            $section = $doc['section_ss'][0] ?? null;
            $parts = [$section, $this->yearRange($doc)];
        } elseif ($profile->kind() === 'publication') {
            // Edited volumes have no author — fall back to the first editor, then type.
            $author = $doc['author_ss'][0] ?? ($doc['editor_ss'][0] ?? ($doc['type_s'] ?? null));
            $parts = [$author, isset($doc['year']) ? (string) $doc['year'] : null];
        } elseif ($profile->kind() === 'podcast') {
            // Series + year (episodes have no type_s; the series gives context).
            $parts = [$doc['series_s'] ?? null, isset($doc['year']) ? (string) $doc['year'] : null];
        } elseif ($profile->kind() === 'video') {
            // Playlist + year (videos have no type_s; the playlist gives context).
            $parts = [$doc['playlist_s'] ?? null, isset($doc['year']) ? (string) $doc['year'] : null];
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

    /** Whether a Typesense error is a missing-stopword-set failure (message-based). */
    private function isStopwordError(\Throwable $e): bool
    {
        return stripos($e->getMessage(), 'stopword') !== false;
    }

    /**
     * @param array<string,mixed> $req
     * @return array{0:SearchProfile,1:array<string,mixed>,2:?string}
     */
    private function prepare(string $profileName, array $req, string $mode): array
    {
        $profile = $this->registry->get($profileName);
        if ($profile === null) {
            throw new RequestValidationException('unknown_profile', 'Unknown search profile.');
        }
        $validated = SearchRequest::fromArray($req, $profile, $mode)->toArray();
        $blockId = isset($validated['block_id']) ? (int) $validated['block_id'] : null;
        $serverFilter = $this->scopeResolver->resolve($blockId, $profile->name());
        return [$profile, $validated, $serverFilter];
    }

    private function logBackendFailure(
        string $operation,
        \Throwable $error,
        SearchProfile $profile,
        ?string $requestId,
    ): void {
        $this->logger->err('DRESearch backend request failed', [
            'request_id' => $requestId,
            'operation' => $operation,
            'profile' => $profile->name(),
            'exception' => $error::class,
            'message' => $error->getMessage(),
        ]);
    }

    /** @param array<string,int> $extra */
    private function logMetric(
        string $operation,
        int $started,
        SearchProfile $profile,
        ?string $requestId,
        array $extra = [],
    ): void {
        $this->logger->info('DRESearch request metric', $extra + [
            'request_id' => $requestId,
            'operation' => $operation,
            'profile' => $profile->name(),
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ]);
    }

    private function unavailable(?string $requestId = null): array
    {
        return [
            'available' => false,
            'found'     => 0,
            'page'      => 1,
            'hits'      => [],
            'facets'    => [],
            'error'     => $this->publicError(
                'backend_unavailable',
                'Search is temporarily unavailable.',
                $requestId,
            ),
        ];
    }

    private function unavailableExport(?string $requestId = null): array
    {
        return [
            'available' => false,
            'found' => 0,
            'exported' => 0,
            'capped' => false,
            'complete' => false,
            'docs' => [],
            'error' => $this->publicError(
                'export_failed',
                'The export could not be completed. No file was produced.',
                $requestId,
            ),
        ];
    }

    private function unavailableMap(?string $requestId = null): array
    {
        return [
            'available' => false,
            'found' => 0,
            'mapped' => 0,
            'capped' => false,
            'docs' => [],
            'error' => $this->publicError(
                'map_failed',
                'The map data could not be loaded.',
                $requestId,
            ),
        ];
    }

    /** @return array{code:string,message:string,request_id?:string} */
    private function publicError(string $code, string $message, ?string $requestId): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($requestId !== null && $requestId !== '') {
            $error['request_id'] = $requestId;
        }
        return $error;
    }
}
