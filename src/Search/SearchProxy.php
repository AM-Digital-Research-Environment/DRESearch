<?php

declare(strict_types=1);

namespace DRESearch\Search;

use DRESearch\Indexer\AnalyticsSync;
use DRESearch\Search\Exception\IncompletePullException;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use DRESearch\Search\Exception\RequestValidationException;
use Laminas\Log\LoggerInterface;

/**
 * Server-side search: runs the Typesense queries for a search profile with the
 * server-held search-only key ({@see TypesenseClientProvider::getSearchClient()};
 * the admin key only for the analytics and health) and returns the compact
 * shape the Svelte client expects.
 * Backend outages return a stable "available: false" payload; invalid profile
 * names and request data are rejected at the public boundary with a structured
 * validation error.
 *
 * The proxy orchestrates; the work lives in small collaborators:
 * {@see QueryBuilder} (parameters), {@see SearchExecutor} (transport),
 * {@see FacetRecount} (multi-select facets), {@see BulkPager} (export and map
 * pulls), {@see ResponseNormalizer} (the JSON shapes) and {@see HealthReport}.
 * What stays here is what every endpoint shares: request validation and scope,
 * the readiness gate, the circuit breaker, the MySQL cache and the logging.
 */
final class SearchProxy
{
    /** Seconds to stop calling an unreachable Typesense after a connection failure. */
    private const BREAKER_SECONDS = 30;

    /**
     * Lifetime of a cached server-rendered first page ({@see firstPage()}).
     * The epoch and the exclusion list already retire it on every recorded
     * change; this bounds what they cannot see (a direct SQL edit, an alias
     * moved by hand) while collapsing the views of a busy page.
     */
    private const FIRST_PAGE_SECONDS = 120;

    private readonly ?SearchCache $cache;
    private readonly ?PopularModeration $moderation;
    private readonly ReadinessGate $gate;
    /** Connection failure seen in this request (the APCu-less breaker). */
    private bool $backendDown = false;
    /** @var array{enabled:bool,min_count:int,limit:int} */
    private readonly array $popular;

    public function __construct(
        private readonly TypesenseClientProvider $provider,
        private readonly ProfileRegistry $registry,
        private readonly BlockScopeResolver $scopeResolver,
        private readonly LoggerInterface $logger,
        /** @var list<string> */
        private readonly array $unionProfiles = [],
        private readonly ?\Doctrine\DBAL\Connection $connection = null,
        ?ReadinessGate $gate = null,
        /** @var array{enabled?:bool,min_count?:int,limit?:int} the `popular_searches` config */
        array $popularSearches = [],
    ) {
        $this->cache = $connection !== null ? new SearchCache($connection) : null;
        $this->moderation = $connection !== null ? new PopularModeration($connection) : null;
        $this->gate = $gate ?? new ReadinessGate($connection);
        $this->popular = [
            'enabled' => (bool) ($popularSearches['enabled'] ?? false),
            'min_count' => max(1, (int) ($popularSearches['min_count'] ?? 5)),
            'limit' => max(1, min(10, (int) ($popularSearches['limit'] ?? 5))),
        ];
    }

    /** Whether blocks offer visitors the popular-searches list (opt-in). */
    public function popularEnabled(): bool
    {
        return $this->popular['enabled'];
    }

    public function isAvailable(): bool
    {
        return $this->provider->isConfigured();
    }

    /** @param array<string,mixed> $req */
    public function search(string $profileName, array $req, ?string $requestId = null): array
    {
        $this->gate->reset();
        [$profile, $req, $serverFilter] = $this->prepare($profileName, $req, 'search');
        return $this->searchPrepared($profile, $req, $serverFilter, $requestId);
    }

    /**
     * {@see search()} for the first page a surface renders on the server (a
     * page block, the project records), shared between visitors through the
     * MySQL cache: every visitor of a page asks for the same page, which only
     * ever holds public documents.
     *
     * The key covers everything that shapes the answer: the profile, the
     * validated request, the resolved saved scope (so an edited locked filter
     * misses), the connection, the cache epoch, and the readiness gate's
     * exclusion list. The epoch moves when a change is enqueued, when a drain
     * applies one and when a rebuild is promoted, and the exclusion list names
     * what is queued in between, so a fill is served only while the queue and
     * the index look as they did when it was made. (The epoch is read before
     * the gate, as for the popular searches, so a change recorded meanwhile
     * moves it past this fill.) Only an available answer is stored: caching an
     * outage would pin it.
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public function firstPage(string $profileName, array $req, ?string $requestId = null): array
    {
        $this->gate->reset();
        [$profile, $req, $serverFilter] = $this->prepare($profileName, $req, 'search');
        $base = $this->cacheKey('first-page:' . serialize([$profile, $req, $serverFilter]));
        $state = $this->state($profile);
        $key = $base !== null && $state['ready'] && $this->client() !== null
            ? hash('sha256', $base . ':' . implode(',', $state['exclude']))
            : null;
        $cached = $this->cacheGet($key);
        if (($cached['available'] ?? false) === true) {
            return $cached;
        }
        $page = $this->searchPrepared($profile, $req, $serverFilter, $requestId);
        if (($page['available'] ?? false) === true) {
            $this->cachePut($key, $page, self::FIRST_PAGE_SECONDS);
        }
        return $page;
    }

    /**
     * {@see search()} for a request {@see prepare()} has already validated and
     * scoped, so a caller that needs the profile first (the federated search)
     * neither validates it twice nor resolves its scope twice.
     *
     * @param array<string,mixed> $req validated request
     * @return array<string,mixed>
     */
    private function searchPrepared(SearchProfile $profile, array $req, ?string $serverFilter, ?string $requestId): array
    {
        $client = $this->client();
        $state = $this->state($profile);
        if ($client === null || !$state['ready']) {
            return $this->unavailable($requestId);
        }
        $started = hrtime(true);
        $builder = new QueryBuilder($profile, $serverFilter, $state['exclude']);
        $params = $builder->search($req);

        // Facets stay multi-select: any facet the user has already refined is
        // recounted alongside the main search with its own clause lifted, so its
        // unselected options keep showing (see FacetRecount). Nothing refined —
        // or the combined call failed — falls through to the plain single
        // search, which owns the missing-stopword-set retry.
        $refined = $builder->refinedFacetFields($req);
        $result = $refined === []
            ? null
            : $this->searchWithOpenFacets($client, $builder, $params, $req, $refined, $profile, $requestId);
        $result ??= $this->runSearch($client, $params, $profile, $requestId);
        if ($result === null) {
            return $this->unavailable($requestId);
        }
        $normalized = ResponseNormalizer::search($result, $profile);
        $this->logMetric('search', $started, $profile, $requestId, ['found' => $normalized['found']]);
        return $normalized;
    }

    /**
     * Run one search, degrading gracefully when the stopword set is missing (e.g.
     * a fresh Typesense volume not yet provisioned by a reindex) — that would
     * otherwise 404 the whole search. Stopwords are an enhancement, not a
     * correctness requirement, so drop them and retry once. Restore filtering with
     * "Reindex all corpora" or the Maintenance "Sync stopwords" action.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null null once the failure has been logged
     */
    private function runSearch(
        object $client,
        array $params,
        SearchProfile $profile,
        ?string $requestId,
    ): ?array {
        try {
            return SearchExecutor::single($client, $profile->collection(), $params);
        } catch (\Throwable $error) {
            $this->logBackendFailure('search', $error, $profile, $requestId);
            return null;
        }
    }

    /**
     * The main search plus one facet-only recount per refined field, in a single
     * `multi_search` ({@see FacetRecount}). Null to fall back to a plain single
     * search — a corrected sidebar is worth a round-trip, never a failed search.
     *
     * @param array<string,mixed> $params main search params
     * @param array<string,mixed> $req    validated request
     * @param list<string> $refined       facet fields to recount
     * @return array<string,mixed>|null
     */
    private function searchWithOpenFacets(
        object $client,
        QueryBuilder $builder,
        array $params,
        array $req,
        array $refined,
        SearchProfile $profile,
        ?string $requestId,
    ): ?array {
        $searches = FacetRecount::searches($builder, $params, $req, $refined, $profile->collection());
        try {
            $response = SearchExecutor::multi($client, ['searches' => $searches]);
        } catch (\Throwable $e) {
            // Non-fatal: log once, then let the caller run the plain search.
            $this->logBackendFailure('facet_recount', $e, $profile, $requestId);
            return null;
        }
        return FacetRecount::merge(is_array($response['results'] ?? null) ? $response['results'] : [], $req, $refined);
    }

    /**
     * Bulk citation pull behind the result-export menu: the CURRENT result set
     * (same query / filters / year window / sort as the live search) paged
     * server-side by {@see BulkPager} up to {@see QueryBuilder::EXPORT_MAX_HITS}.
     * Returns the raw documents (citation / display fields only — no facets, no
     * highlights, no `is_public`); the client serializes them to txt / json /
     * ris / bibtex. A file is deliverable only when every expected page was
     * returned; partial exports fail closed.
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public function export(string $profileName, array $req, ?string $requestId = null): array
    {
        $this->gate->reset();
        [$profile, $req, $serverFilter] = $this->prepare($profileName, $req, 'export');
        $client = $this->client();
        $state = $this->state($profile);
        if ($client === null || !$state['ready']) {
            return $this->unavailableExport($requestId);
        }
        $started = hrtime(true);
        $builder = new QueryBuilder($profile, $serverFilter, $state['exclude']);
        $pager = new BulkPager(QueryBuilder::EXPORT_MAX_HITS);
        $pull = $this->pull(
            'export',
            $pager,
            static fn(int $page): array => SearchExecutor::single($client, $profile->collection(), $builder->export($req, $page)),
            $profile,
            $requestId,
        );
        if ($pull === null) {
            return $this->unavailableExport($requestId);
        }
        [$found, $docs] = [$pull['found'], $pull['docs']];
        $this->logMetric('export', $started, $profile, $requestId, ['found' => $found, 'exported' => count($docs)]);
        return [
            'available' => true,
            'found'     => $found,
            'exported'  => count($docs),
            'capped'    => $pager->capped($found),
            'complete'  => true,
            'docs'      => $docs,
        ];
    }

    /**
     * Run a {@see BulkPager} pull, logging a failed request as `$operation` and
     * a short pull as `{$operation}_verification`.
     *
     * @param callable(int):array<string,mixed> $fetchPage
     * @return array{found:int, docs:list<array<string,mixed>>}|null null once logged
     */
    private function pull(
        string $operation,
        BulkPager $pager,
        callable $fetchPage,
        SearchProfile $profile,
        ?string $requestId,
    ): ?array {
        try {
            return $pager->pull($fetchPage);
        } catch (IncompletePullException $short) {
            // A file or a map missing records is a failure the visitor sees as
            // "could not complete", and the cause is server-side.
            $this->logBackendFailure($operation . '_verification', $short, $profile, $requestId);
        } catch (\Throwable $error) {
            $this->logBackendFailure($operation, $error, $profile, $requestId);
        }
        return null;
    }

    public function suggest(
        string $profileName,
        string $q,
        ?int $blockId = null,
        ?string $requestId = null,
        ?int $scopeItem = null,
    ): array {
        $this->gate->reset();
        $q = SearchRequest::query($q);
        $profile = $this->registry->get($profileName);
        if ($profile === null) {
            throw new RequestValidationException('unknown_profile', 'Unknown search profile.');
        }
        $serverFilter = $this->scopeResolver->resolveScope($blockId, $scopeItem, $profile->name());
        $client = $this->client();
        $state = $this->state($profile);
        if ($client === null || !$state['ready'] || $q === '') {
            return ['available' => $client !== null && $state['ready'], 'suggestions' => []];
        }
        try {
            $result = SearchExecutor::single(
                $client,
                $profile->collection(),
                (new QueryBuilder($profile, $serverFilter, $state['exclude']))->suggest($q),
            );
        } catch (\Throwable $e) {
            $this->logBackendFailure('suggest', $e, $profile, $requestId);
            return ['available' => false, 'suggestions' => []];
        }
        return ['available' => true, 'suggestions' => ResponseNormalizer::suggestions($result, $profile)];
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
    ): array {
        $this->gate->reset();
        $q = SearchRequest::query($q);
        $client = $this->client();
        // A corpus with queued work beyond the exclusion limit drops out of the
        // dropdown; the others keep answering.
        $profiles = $this->readyProfiles(array_values($this->registry->all()));
        if ($client === null || $profiles === [] || $q === '') {
            return ['available' => $client !== null && $profiles !== [], 'groups' => []];
        }
        $translate ??= static fn(string $s): string => $s;

        $searches = [];
        foreach ($profiles as $profile) {
            $searches[] = (new QueryBuilder($profile, null, $this->state($profile)['exclude']))->suggestSearch($q);
        }

        try {
            $response = SearchExecutor::multi($client, ['searches' => $searches]);
        } catch (\Throwable $e) {
            $this->logBackendFailure('suggest_all', $e, $profiles[0], $requestId);
            return ['available' => false, 'groups' => []];
        }

        // multi_search preserves request order; a per-search failure (e.g. a
        // not-yet-indexed collection) yields an entry with no 'hits' → skipped.
        $results = $response['results'] ?? [];
        $groups = [];
        foreach ($profiles as $i => $profile) {
            $result = is_array($results[$i] ?? null) ? $results[$i] : [];
            if (($result['hits'] ?? []) === []) {
                continue;
            }
            $groups[] = [
                'profile'     => $profile->name(),
                'label'       => $translate($profile->label()),
                'kind'        => $profile->kind(),
                'suggestions' => ResponseNormalizer::suggestions($result, $profile),
            ];
        }
        return ['available' => true, 'groups' => $groups];
    }

    /**
     * The searches visitors run most on one corpus, offered in the empty search
     * box. Read from the popular-query analytics ({@see AnalyticsSync}); off
     * unless `popular_searches.enabled`, because it shows visitors what other
     * visitors typed. {@see PopularSearches} gates every entry, and only what an
     * editor approved is served ({@see PopularModeration}): anyone can repeat a
     * query until it counts. Cached ten minutes: the list changes slowly and the
     * analytics flush is periodic; a moderation decision invalidates the cache.
     *
     * @return array{available:bool, queries:list<string>}
     */
    public function popular(string $profileName, ?string $requestId = null): array
    {
        $profile = $this->registry->get($profileName);
        if ($profile === null) {
            throw new RequestValidationException('unknown_profile', 'Unknown search profile.');
        }
        if (!$this->popular['enabled']) {
            return ['available' => true, 'queries' => []];
        }
        // The key is taken before the approvals are read, so a decision made
        // meanwhile moves the cache epoch past this fill.
        $cacheKey = $this->cacheKey('popular:' . $profile->name());
        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return ['available' => true, 'queries' => array_values(array_map('strval', $cached))];
        }
        // Fail closed: without the moderation table nothing is approved.
        try {
            $approved = $this->moderation?->approved($profile->name()) ?? [];
        } catch (\Throwable $e) {
            $this->logger->err('DRESearch popular-search approvals are unavailable', [
                'request_id' => $requestId,
                'profile' => $profile->name(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return ['available' => true, 'queries' => []];
        }
        if ($approved === []) {
            $this->cachePut($cacheKey, [], 600);
            return ['available' => true, 'queries' => []];
        }
        // The analytics collections hold every query visitors typed, so the
        // search-only key cannot read them; the admin key does, server-side,
        // and only approved queries leave this method.
        $client = $this->client(true);
        if ($client === null) {
            return ['available' => false, 'queries' => []];
        }
        $minCount = $this->popular['min_count'];
        try {
            $analytics = PopularAnalytics::fetch($client, $profile->name(), $minCount);
        } catch (\Throwable $e) {
            $this->logBackendFailure('popular', $e, $profile, $requestId, true);
            return ['available' => false, 'queries' => []];
        }
        // An approved query is served only while it is still a candidate:
        // typed min_count times, finding something. Missing analytics
        // collections (never provisioned) leave an empty list, cached as usual.
        $queries = PopularSearches::approved(
            PopularSearches::candidates($analytics['popular'], $analytics['nohits'], $minCount),
            $approved,
            $this->popular['limit'],
        );
        $this->cachePut($cacheKey, $queries, 600);
        return ['available' => true, 'queries' => $queries];
    }

    /**
     * Federated results-page search: a per-corpus match COUNT for every profile
     * (the type tabs) in one `multi_search`, plus the focused corpus's full
     * faceted response (as {@see search()} gives it). Counts use only the shared
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
        $this->gate->reset();
        // Validated and scoped once; the active search below reuses both, and
        // the gate's answers for this call.
        [$profile, $req, $serverFilter] = $this->prepare($activeProfile, $req, 'search');
        $client = $this->client();
        if ($client === null) {
            return ['available' => false, 'counts' => [], 'active' => $this->unavailable($requestId)];
        }

        // Paused corpora (queued work beyond the exclusion limit, or a pending
        // rebuild) simply have no count on their tab; the rest still answer.
        $profiles = $this->readyProfiles(array_values($this->registry->all()));
        $countReq = [
            'q'         => (string) ($req['q'] ?? ''),
            'year_from' => $req['year_from'] ?? null,
            'year_to'   => $req['year_to'] ?? null,
        ];
        $counts = [];
        if (!empty($req['include_counts'])) {
            $cacheKey = $this->cacheKey('counts:' . serialize([$profiles, $countReq, array_map(fn(SearchProfile $p): array => $this->state($p)['exclude'], $profiles)]));
            $cached = $this->cacheGet($cacheKey);
            if ($cached !== null) {
                $counts = $cached;
            } else {
                $searches = [];
                foreach ($profiles as $countProfile) {
                    $searches[] = (new QueryBuilder($countProfile, null, $this->state($countProfile)['exclude']))->countOnly($countReq);
                }
                try {
                    $response = SearchExecutor::multi($client, ['searches' => $searches]);
                    foreach ($profiles as $i => $countProfile) {
                        if (isset($response['results'][$i]['found'])) {
                            $counts[$countProfile->name()] = (int) $response['results'][$i]['found'];
                        }
                    }
                    $this->cachePut($cacheKey, $counts, 30);
                } catch (\Throwable $e) {
                    $this->logBackendFailure('federated_count', $e, $profile, $requestId);
                    $counts = [];
                }
            }
        }

        $active = $this->searchPrepared($profile, $req, $serverFilter, $requestId);
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
     * @param callable(string):string|null $translate translates corpus badges
     * @return array<string,mixed>
     */
    public function union(array $req, ?string $requestId = null, ?callable $translate = null): array
    {
        $this->gate->reset();
        $validated = SearchRequest::union($req);
        $client = $this->client();
        if ($client === null) {
            return $this->unavailable($requestId);
        }
        $translate ??= static fn(string $s): string => $s;

        $configured = [];
        $names = $this->unionProfiles !== [] ? $this->unionProfiles : $this->registry->names();
        foreach ($names as $name) {
            $profile = $this->registry->get((string) $name);
            if ($profile !== null) {
                $configured[] = $profile;
            }
        }
        if ($configured === []) {
            throw new RequestValidationException('empty_union_scope', 'No search profiles are configured for merged search.');
        }
        // Merge whatever is ready; a corpus paused for a rebuild just drops out.
        $profiles = $this->readyProfiles($configured);
        if ($profiles === []) {
            return $this->unavailable($requestId);
        }

        $searches = [];
        foreach ($profiles as $profile) {
            $searches[] = (new QueryBuilder($profile, null, $this->state($profile)['exclude']))->union($validated['q']);
        }
        $started = hrtime(true);
        try {
            $result = SearchExecutor::multi(
                $client,
                ['union' => true, 'searches' => $searches],
                ['page' => $validated['page'], 'per_page' => $validated['per_page']],
            );
        } catch (\Throwable $e) {
            $this->logBackendFailure('union', $e, $profiles[0], $requestId);
            return $this->unavailable($requestId);
        }

        $normalized = ResponseNormalizer::search($result, $profiles[0]);
        $normalized['partial'] = count($profiles) < count($configured);
        // Each merged hit names its corpus (`_profile`, stored on the document);
        // the client badges it with the translated label.
        foreach ($normalized['hits'] as $i => $doc) {
            $profile = $this->registry->get((string) ($doc['_profile'] ?? ''));
            $normalized['hits'][$i]['_profile_label'] = $profile !== null ? $translate($profile->label()) : (string) ($doc['_profile'] ?? '');
        }
        $this->logMetric('union', $started, $profiles[0], $requestId, ['found' => $normalized['found']]);
        return $normalized;
    }

    /**
     * Return up to MAP_MAX_HITS geocoded documents matching one location
     * profile's current query/filter scope. Paging stays server-side
     * ({@see BulkPager}) so the map represents the result set rather than only
     * the visible list page.
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public function map(string $profileName, array $req, ?string $requestId = null): array
    {
        $this->gate->reset();
        [$profile, $validated, $serverFilter] = $this->prepare($profileName, $req, 'search');
        if (!isset($profile->displayFields()['geo'], $profile->displayFields()['has_coords'])) {
            throw new RequestValidationException('map_unavailable', 'This search profile does not provide map coordinates.');
        }
        $client = $this->client();
        $state = $this->state($profile);
        if ($client === null || !$state['ready']) {
            return $this->unavailableMap($requestId);
        }

        $builder = new QueryBuilder($profile, $serverFilter, $state['exclude']);
        $pager = new BulkPager(QueryBuilder::MAP_MAX_HITS);
        $started = hrtime(true);
        $pull = $this->pull(
            'map',
            $pager,
            static fn(int $page): array => SearchExecutor::single($client, $profile->collection(), $builder->map($validated, $page)),
            $profile,
            $requestId,
        );
        if ($pull === null) {
            return $this->unavailableMap($requestId);
        }
        [$found, $docs] = [$pull['found'], $pull['docs']];
        $this->logMetric('map', $started, $profile, $requestId, ['found' => $found, 'mapped' => count($docs)]);
        return [
            'available' => true,
            'found' => $found,
            'mapped' => count($docs),
            'capped' => $pager->capped($found),
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
        return $this->yearBoundsFor([$profileName])[$profileName] ?? null;
    }

    /**
     * {@see yearBounds()} for several corpora at once, as the federated page
     * needs them for every tab: one readiness-gate answer, one cache read (and
     * epoch read) and, on a miss, one `multi_search`, instead of one of each
     * per corpus. A corpus without a year facet, paused, unknown, or whose
     * entry failed maps to null; the set is cached only when every entry
     * answered.
     *
     * @param list<string> $profileNames
     * @return array<string,array{min:int,max:int}|null> keyed by every name asked for
     */
    public function yearBoundsFor(array $profileNames): array
    {
        $this->gate->reset();
        $bounds = array_fill_keys($profileNames, null);
        $profiles = [];
        foreach ($profileNames as $name) {
            $profile = $this->registry->get($name);
            if ($profile !== null && $profile->name() === $name && $profile->hasYearFacet()) {
                $profiles[] = $profile;
            }
        }
        $client = $this->client();
        $profiles = $profiles === [] || $client === null ? [] : $this->readyProfiles($profiles);
        if ($client === null || $profiles === []) {
            return $bounds;
        }
        $cacheKey = $this->cacheKey('years:' . serialize($profiles));
        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            foreach ($profiles as $profile) {
                $span = $cached[$profile->name()] ?? null;
                $bounds[$profile->name()] = is_array($span) && is_int($span['min'] ?? null) && is_int($span['max'] ?? null)
                    ? ['min' => $span['min'], 'max' => $span['max']]
                    : null;
            }
            return $bounds;
        }
        $searches = array_map(
            static fn(SearchProfile $p): array => (new QueryBuilder($p))->yearStats() + ['collection' => $p->collection()],
            $profiles,
        );
        try {
            $response = SearchExecutor::multi($client, ['searches' => $searches]);
        } catch (\Throwable $e) {
            $this->noteFailure($e);
            return $bounds;
        }
        $complete = true;
        $found = [];
        foreach ($profiles as $i => $profile) {
            $result = $response['results'][$i] ?? null;
            if (!is_array($result) || isset($result['error'])) {
                // e.g. a corpus never built: no bounds, and nothing cached.
                $complete = false;
                continue;
            }
            $found[$profile->name()] = ResponseNormalizer::yearBounds($result, $profile);
        }
        if ($complete) {
            $this->cachePut($cacheKey, $found, 300);
        }
        return array_replace($bounds, $found);
    }

    public function facet(string $profileName, array $input, ?string $requestId = null): array
    {
        $this->gate->reset();
        [$profile, $req, $scope] = $this->prepare($profileName, $input, 'facet');
        $client = $this->client();
        $state = $this->state($profile);
        if ($client === null || !$state['ready']) {
            return ['available' => false, 'counts' => []];
        }
        $field = $req['facet_field'];
        $params = (new QueryBuilder($profile, $scope, $state['exclude']))->facetSearch($req, $field, $req['facet_query']);
        $result = $this->runSearch($client, $params, $profile, $requestId);
        if ($result === null) {
            return ['available' => false, 'counts' => []];
        }
        $counts = FacetRecount::countsOf($result, $field) ?? [];
        // While the visitor types into a facet's search box, list only what
        // matches the text; already-selected values stay in the sidebar list.
        if ($req['facet_query'] === '') {
            $counts = FacetRecount::keepSelectedListed($counts, $req['filters'][$field] ?? []);
        }
        return ['available' => true, 'counts' => array_map(
            static fn(array $row): array => [
                'value' => (string) $row['value'],
                'count' => $row['count'] === null ? null : (int) $row['count'],
            ],
            $counts,
        )];
    }

    /**
     * Operational status for monitoring ({@see HealthReport}): is Typesense
     * configured and reachable, which corpora are live, hiding pending records
     * or paused, and is the incremental worker keeping up. No secrets, no
     * record ids.
     *
     * @return array<string,mixed>
     */
    public function health(): array
    {
        $this->gate->reset();
        $names = $this->registry->names();
        return (new HealthReport($this->connection))->build(
            $this->provider->isConfigured(),
            $this->provider->getClient(),
            $names,
            $this->gate->check($names),
            fn(\Throwable $error) => $this->noteFailure($error, true),
        );
    }

    /** @return array{ready:bool,exclude:list<int>} */
    private function state(SearchProfile $profile): array
    {
        return $this->gate->check([$profile->name()])[$profile->name()] ?? ['ready' => false, 'exclude' => []];
    }

    /**
     * @param list<SearchProfile> $profiles
     * @return list<SearchProfile> those that may answer now (one gate query)
     */
    private function readyProfiles(array $profiles): array
    {
        $states = $this->gate->check(array_map(static fn(SearchProfile $p): string => $p->name(), $profiles));
        return array_values(array_filter(
            $profiles,
            static fn(SearchProfile $p): bool => $states[$p->name()]['ready'] ?? false,
        ));
    }

    private function cacheKey(string $context): ?string
    {
        try {
            return $this->cache?->key($this->provider->cacheNamespace() . ':' . $context);
        } catch (\Throwable) {
            return null;
        }
    }

    private function cacheGet(?string $key): ?array
    {
        try {
            return $key !== null ? $this->cache?->get($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function cachePut(?string $key, array $value, int $ttl): void
    {
        try {
            if ($key !== null) {
                $this->cache?->put($key, $value, $ttl);
            }
        } catch (\Throwable) {
            // Cache failure must not hide an otherwise valid search result.
        }
    }

    /**
     * Validate public input before resolving trusted server-side scope: a
     * saved block's locked filter (`block_id`) or a project's records
     * (`scope_item`), never both.
     *
     * @param array<string,mixed> $req
     * @return array{0:SearchProfile, 1:array<string,mixed>, 2:?string}
     */
    private function prepare(string $profileName, array $req, string $mode): array
    {
        $profile = $this->registry->get($profileName);
        if ($profile === null) {
            throw new RequestValidationException('unknown_profile', 'Unknown search profile.');
        }
        $validated = SearchRequest::fromArray($req, $profile, $mode)->toArray();
        $blockId = isset($validated['block_id']) ? (int) $validated['block_id'] : null;
        $scopeItem = isset($validated['scope_item']) ? (int) $validated['scope_item'] : null;
        $serverFilter = $this->scopeResolver->resolveScope($blockId, $scopeItem, $profile->name());
        return [$profile, $validated, $serverFilter];
    }

    /**
     * The client for public searches — the search-only key's
     * ({@see TypesenseClientProvider::getSearchClient()}), or with `$admin`
     * the admin key's, for what that key cannot read (the analytics) — or null
     * when Typesense is unconfigured or was just found unreachable. Without
     * the breaker a page rendering several blocks against a firewalled server
     * waits out one connect timeout per call.
     */
    private function client(bool $admin = false): ?object
    {
        $client = $admin ? $this->provider->getClient() : $this->provider->getSearchClient();
        if ($client === null || $this->backendDown) {
            return null;
        }
        if (function_exists('apcu_fetch') && apcu_fetch($this->breakerKey()) !== false) {
            $this->backendDown = true;
            return null;
        }
        return $client;
    }

    /** @param bool $admin the failed request ran with the admin key */
    private function noteFailure(\Throwable $error, bool $admin = false): void
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if (!$admin && $e instanceof \Typesense\Exceptions\RequestUnauthorized) {
                // Typesense answers 401 for a deleted key and for one that may
                // not do what was asked: forget a minted key, so the next
                // request mints a fresh one rather than every request failing.
                $this->provider->searchKeyRejected();
                return;
            }
            if ($e instanceof \GuzzleHttp\Exception\ConnectException || $e instanceof \Psr\Http\Client\NetworkExceptionInterface) {
                $this->backendDown = true;
                if (function_exists('apcu_store')) {
                    apcu_store($this->breakerKey(), 1, self::BREAKER_SECONDS);
                }
                return;
            }
        }
    }

    private function breakerKey(): string
    {
        return 'dre_search_down_' . $this->provider->cacheNamespace();
    }

    private function logBackendFailure(
        string $operation,
        \Throwable $error,
        SearchProfile $profile,
        ?string $requestId,
        bool $admin = false,
    ): void {
        $this->noteFailure($error, $admin);
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
