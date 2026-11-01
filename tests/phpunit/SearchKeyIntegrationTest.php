<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\SchemaProvider;
use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Search\TypesenseSearchKeyProvider;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use Omeka\Settings\SettingsInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Typesense\Client;

/**
 * Public search with the search-only key, against a real Typesense: what the
 * key may and may not do, and that every public path — a corpus search, the
 * merged (union) stream, the federated search, autocomplete, analytics capture
 * — works with it, while health keeps the admin key.
 */
#[Group('typesense')]
final class SearchKeyIntegrationTest extends TestCase
{
    private Client $admin;
    private TypesenseClientProvider $adminOnly;
    private string $prefix;
    /** @var list<string> */
    private array $collections = [];
    private ProfileRegistry $registry;
    private SettingsInterface $settings;

    protected function setUp(): void
    {
        if (!getenv('TYPESENSE_HOST') || !interface_exists(SettingsInterface::class)) {
            self::markTestSkipped('Requires Omeka core and a disposable Typesense; see CONTRIBUTING.md.');
        }
        $this->adminOnly = $this->provider();
        $client = $this->adminOnly->getClient();
        self::assertNotNull($client);
        $this->admin = $client;
        $this->prefix = 'dre_key_' . bin2hex(random_bytes(5));
        $profiles = [];
        foreach (['records' => 'River archive', 'people' => 'River people'] as $name => $title) {
            $alias = $this->prefix . '_' . $name . '_current';
            $profile = SearchProfile::fromArray($name, [
                'label' => ucfirst($name), 'collection' => $alias, 'kind' => 'item', 'template_id' => 10,
                'query_by' => 'title', 'date' => ['mode' => 'none'],
                'facets' => ['type_s' => ['property' => 'dcterms:type', 'label' => 'Type', 'array' => false]],
            ]);
            $generation = $this->prefix . '_' . $name . '_g1';
            $this->admin->collections->create((new SchemaProvider())->collection($generation, $profile));
            $this->collections[] = $generation;
            $this->admin->aliases->upsert($alias, ['collection_name' => $generation]);
            $this->admin->collections[$generation]->documents->import([
                ['id' => '1', '_kind' => 'item', '_profile' => $name, 'title' => $title, 'is_public' => true, 'type_s' => 'Text'],
            ]);
            $profiles[$name] = $profile;
        }
        $this->registry = new ProfileRegistry($profiles);
        $this->settings = new ArraySettings();
    }

    protected function tearDown(): void
    {
        if (!isset($this->admin)) {
            return;
        }
        foreach ($this->admin->keys->retrieve()['keys'] ?? [] as $key) {
            if (
                ($key['description'] ?? '') === TypesenseSearchKeyProvider::DESCRIPTION
                && in_array('^' . $this->prefix . '_people_current$', (array) ($key['collections'] ?? []), true)
            ) {
                $this->admin->keys[(string) $key['id']]->delete();
            }
        }
        foreach (['records', 'people'] as $name) {
            try {
                $this->admin->aliases[$this->prefix . '_' . $name . '_current']->delete();
            } catch (\Throwable) {
            }
        }
        foreach ($this->collections as $collection) {
            try {
                $this->admin->collections[$collection]->delete();
            } catch (\Throwable) {
            }
        }
    }

    public function testTheMintedKeyMaySearchTheAliasesAndNothingElse(): void
    {
        $keys = $this->keys();
        $value = $keys->key();
        self::assertIsString($value);
        $listed = array_values(array_filter(
            $this->admin->keys->retrieve()['keys'],
            static fn(array $key): bool => ($key['value_prefix'] ?? '') === substr($value, 0, 4)
                && ($key['description'] ?? '') === TypesenseSearchKeyProvider::DESCRIPTION,
        ));
        self::assertCount(1, $listed);
        self::assertSame(['documents:search'], $listed[0]['actions']);
        $scope = $listed[0]['collections'];
        sort($scope);
        self::assertSame($keys->scope(), $scope);

        $search = $this->provider($keys)->getSearchClient();
        self::assertNotNull($search);
        self::assertNotSame($this->admin, $search);
        $hits = $search->collections[$this->prefix . '_records_current']->documents->search(['q' => 'river', 'query_by' => 'title']);
        self::assertSame(1, $hits['found']);
        foreach (
            [
                'a retained generation' => fn() => $search->collections[$this->prefix . '_records_g1']->documents->search(['q' => 'river', 'query_by' => 'title']),
                'a write' => fn() => $search->collections[$this->prefix . '_records_current']->documents->create(['id' => '2', 'title' => 'x', 'is_public' => false]),
                'the key listing' => fn() => $search->keys->retrieve(),
            ] as $what => $attempt
        ) {
            try {
                $attempt();
                self::fail("The search-only key reached {$what}.");
            } catch (\Typesense\Exceptions\RequestUnauthorized) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEveryPublicPathAnswersWithTheSearchKey(): void
    {
        $keys = $this->keys();
        $provider = $this->provider($keys);
        $proxy = $this->proxy($provider);
        self::assertNotSame($provider->getClient(), $provider->getSearchClient(), 'Public search runs with its own key.');

        self::assertSame(1, $proxy->search('records', ['q' => 'river'])['found']);
        self::assertSame(1, $proxy->search('records', ['filters' => ['type_s' => ['Text']]])['found'], 'The facet recount pass.');
        $union = $proxy->union(['q' => 'river']);
        self::assertTrue($union['available']);
        self::assertSame(2, $union['found'], 'The merged stream reads both aliases.');
        $all = $proxy->searchAll('people', ['q' => 'river']);
        self::assertSame(['records' => 1, 'people' => 1], $all['counts']);
        self::assertSame(1, $all['active']['found']);
        self::assertCount(2, $proxy->suggestAll('riv')['groups']);
        self::assertSame(1, $proxy->export('records', ['q' => 'river'])['exported']);
        self::assertTrue($proxy->health()['ok'], 'Health keeps the admin key.');
    }

    /**
     * Analytics capture runs on the search request itself: a query recorded
     * through the search-only key (the collection GET with
     * `x-typesense-user-id`) counts exactly as one recorded through the admin
     * key. On a server started without search analytics neither counts; with
     * them both do.
     */
    public function testAnalyticsCountQueriesSearchedWithTheSearchKey(): void
    {
        $alias = $this->prefix . '_records_current';
        $destination = $this->prefix . '_popular';
        $this->admin->collections->create(['name' => $destination, 'fields' => [
            ['name' => 'q', 'type' => 'string'], ['name' => 'count', 'type' => 'int32'],
        ]]);
        $this->collections[] = $destination;
        $rule = $this->prefix . '_rule';
        $this->admin->analytics->rules()[$rule]->update([
            'name' => $rule, 'type' => 'popular_queries', 'collection' => $alias, 'event_type' => 'search',
            'params' => ['destination_collection' => $destination, 'limit' => 100, 'capture_search_requests' => true, 'expand_query' => false],
        ]);
        try {
            $withKey = $this->proxy($this->provider($this->keys()));
            $withAdmin = $this->proxy($this->provider());
            // One visitor per search: Typesense keeps only the last of one
            // visitor's quick successive queries (a typing session).
            for ($i = 0; $i < 3; $i++) {
                $visitor = static fn(string $hex): string => str_repeat($hex, 32);
                self::assertTrue($withKey->search('records', ['q' => 'river', 'record_query' => true, 'analytics_id' => $visitor((string) $i)])['available']);
                self::assertTrue($withAdmin->search('records', ['q' => 'archive', 'record_query' => true, 'analytics_id' => $visitor(dechex($i + 10))])['available']);
            }
            $this->flushAnalytics();
            $counts = [];
            foreach ($this->admin->collections[$destination]->documents->search(['q' => '*', 'query_by' => 'q'])['hits'] as $hit) {
                $counts[$hit['document']['q']] = $hit['document']['count'];
            }
            self::assertSame($counts['archive'] ?? null, $counts['river'] ?? null);
        } finally {
            $this->admin->analytics->rules()[$rule]->delete();
        }
    }

    /**
     * A minted key Typesense no longer has (deleted by hand, a volume reset)
     * costs one failed request: the key is forgotten, and the next request
     * mints another.
     */
    public function testARevokedMintedKeyIsReplacedOnTheNextRequest(): void
    {
        $keys = $this->keys();
        $value = $keys->key();
        self::assertIsString($value);
        foreach ($this->admin->keys->retrieve()['keys'] as $key) {
            if (($key['value_prefix'] ?? '') === substr($value, 0, 4) && ($key['description'] ?? '') === TypesenseSearchKeyProvider::DESCRIPTION) {
                $this->admin->keys[(string) $key['id']]->delete();
            }
        }
        self::assertFalse($this->proxy($this->provider($keys))->search('records', ['q' => 'river'])['available']);
        self::assertNull($this->settings->get($keys->slot()), 'The refused key is forgotten.');

        $next = $this->keys();
        self::assertSame(1, $this->proxy($this->provider($next))->search('records', ['q' => 'river'])['found']);
        self::assertNotSame($value, $next->key());
    }

    private function keys(): TypesenseSearchKeyProvider
    {
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter(new \Laminas\Log\Writer\Noop());
        return new TypesenseSearchKeyProvider(
            fn(): ?object => $this->adminOnly->getClient(),
            $this->settings,
            $logger,
            array_values(array_map(static fn(SearchProfile $p): string => $p->collection(), $this->registry->all())),
        );
    }

    private function provider(?TypesenseSearchKeyProvider $keys = null): TypesenseClientProvider
    {
        return new TypesenseClientProvider(
            (string) getenv('TYPESENSE_HOST'),
            (int) (getenv('TYPESENSE_PORT') ?: 8108),
            'http',
            (string) getenv('TYPESENSE_API_KEY'),
            5.0,
            60.0,
            2.0,
            $keys !== null ? static fn(): TypesenseSearchKeyProvider => $keys : null,
        );
    }

    private function proxy(TypesenseClientProvider $provider): SearchProxy
    {
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter(new \Laminas\Log\Writer\Noop());
        return new SearchProxy(
            $provider,
            $this->registry,
            new BlockScopeResolver(\Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])),
            $logger,
        );
    }

    /** Write the analytics buffered in memory to their destination collections now. */
    private function flushAnalytics(): void
    {
        (new \GuzzleHttp\Client())->post(sprintf(
            'http://%s:%s/analytics/flush',
            (string) getenv('TYPESENSE_HOST'),
            (string) (getenv('TYPESENSE_PORT') ?: 8108),
        ), ['headers' => ['X-TYPESENSE-API-KEY' => (string) getenv('TYPESENSE_API_KEY')]]);
        usleep(500_000);
    }
}
