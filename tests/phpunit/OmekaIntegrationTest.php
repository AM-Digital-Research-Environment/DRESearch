<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DRESearch\Indexer\ChangeQueue;
use DRESearch\Indexer\GenerationPublisher;
use DRESearch\Indexer\IncrementalIndexer;
use DRESearch\Indexer\ItemEventListener;
use DRESearch\Indexer\OmekaSourceRepository;
use DRESearch\Indexer\RebuildLock;
use DRESearch\Indexer\RebuildStateStore;
use DRESearch\Indexer\Reindexer;
use DRESearch\Indexer\WorkerLease;
use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\SearchCache;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use Laminas\EventManager\Event;
use Laminas\Log\Logger;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Api\Request;
use Omeka\Api\Response;
use Omeka\Entity\Item;
use Omeka\Entity\Media;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Typesense\Client;

/** Real Omeka entities/requests, DBAL/MySQL locks and SQL, and Typesense HTTP operations. */
#[Group('omeka')]
final class OmekaIntegrationTest extends TestCase
{
    private Connection $db;
    private Connection $admin;
    private string $database;
    private array $dbParams;
    private Client $client;
    private TypesenseClientProvider $provider;
    private SearchProfile $profile;
    private ProfileRegistry $registry;
    private RebuildStateStore $state;
    private ChangeQueue $queue;
    private Logger $logger;
    private string $prefix;

    protected function setUp(): void
    {
        if (!getenv('DRE_TEST_MYSQL_HOST') || !getenv('TYPESENSE_HOST') || !class_exists(Item::class)) {
            self::markTestSkipped('Requires Omeka core, disposable MySQL and Typesense; see CONTRIBUTING.md.');
        }
        $this->dbParams = [
            'driver' => 'pdo_mysql', 'host' => getenv('DRE_TEST_MYSQL_HOST'),
            'port' => (int) (getenv('DRE_TEST_MYSQL_PORT') ?: 3306),
            'user' => getenv('DRE_TEST_MYSQL_USER') ?: 'root',
            'password' => getenv('DRE_TEST_MYSQL_PASSWORD') ?: '', 'charset' => 'utf8mb4',
        ];
        $this->admin = DriverManager::getConnection($this->dbParams);
        $this->database = 'dre_test_' . bin2hex(random_bytes(6));
        $this->admin->executeStatement('CREATE DATABASE ' . $this->database);
        $this->db = DriverManager::getConnection($this->dbParams + ['dbname' => $this->database]);
        foreach (
            [
            'CREATE TABLE resource (id INT PRIMARY KEY, title TEXT, is_public INT, resource_type VARCHAR(255), resource_template_id INT)',
            'CREATE TABLE resource_template (id INT PRIMARY KEY, title_property_id INT)',
            'CREATE TABLE vocabulary (id INT PRIMARY KEY, prefix VARCHAR(100))',
            'CREATE TABLE property (id INT PRIMARY KEY, vocabulary_id INT, local_name VARCHAR(100), label VARCHAR(100))',
            'CREATE TABLE value (id INT PRIMARY KEY, resource_id INT, property_id INT, value_resource_id INT, value TEXT, uri TEXT, is_public INT)',
            'CREATE TABLE item_item_set (item_id INT, item_set_id INT)',
            'CREATE TABLE media (id INT PRIMARY KEY, item_id INT, storage_id VARCHAR(100), has_thumbnails INT, position INT)',
            'CREATE TABLE site_page_block (id INT PRIMARY KEY, layout VARCHAR(100), data TEXT)',
            ] as $sql
        ) {
            $this->db->executeStatement($sql);
        }
        require_once dirname(__DIR__, 2) . '/Module.php';
        $services = new ServiceManager();
        $services->setService('Omeka\Connection', $this->db);
        (new \DRESearch\Module())->install($services);
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $definition = $config['dre_search']['profiles']['research_publications'];
        $this->prefix = 'dre_test_' . bin2hex(random_bytes(6));
        $definition['collection'] = $this->prefix . '_current';
        $this->profile = SearchProfile::fromArray('research_publications', $definition);
        $this->registry = new ProfileRegistry(['research_publications' => $this->profile]);
        $this->provider = new TypesenseClientProvider(
            (string) getenv('TYPESENSE_HOST'),
            (int) (getenv('TYPESENSE_PORT') ?: 8108),
            'http',
            (string) getenv('TYPESENSE_API_KEY'),
        );
        $this->client = $this->provider->getClient();
        $this->state = new RebuildStateStore($this->db);
        $this->queue = new ChangeQueue($this->db);
        $this->logger = new Logger();
        $this->logger->addWriter(new \Laminas\Log\Writer\Noop());
        $this->resource(1, 'Public title');
        $this->resource(2, 'Private author', false);
        $this->resource(3, 'Public author');
        $this->db->insert('item_item_set', ['item_id' => 1, 'item_set_id' => 29918]);
        $this->db->insert('vocabulary', ['id' => 1, 'prefix' => 'bibo']);
        $this->db->insert('property', ['id' => 1, 'vocabulary_id' => 1, 'local_name' => 'abstract']);
        $this->db->insert('property', ['id' => 2, 'vocabulary_id' => 1, 'local_name' => 'authorList']);
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            try {
                $this->client->aliases[$this->profile->collection()]->delete();
            } catch (\Typesense\Exceptions\ObjectNotFound) {
            }
            foreach ($this->client->collections->retrieve() as $collection) {
                if (str_starts_with($collection['name'], $this->prefix . '_')) {
                    $this->client->collections[$collection['name']]->delete();
                }
            }
        }
        if (isset($this->db)) {
            $this->db->close();
        }
        if (isset($this->admin, $this->database) && preg_match('/^dre_test_[a-f0-9]{12}$/D', $this->database)) {
            $this->admin->executeStatement('DROP DATABASE ' . $this->database);
            $this->admin->close();
        }
    }

    public function testPublicDocumentExcludesPrivateValuesTargetsAndMedia(): void
    {
        $this->value(1, 1, 1, null, 'Secret abstract', false);
        $this->value(2, 1, 2, 2);
        $this->value(3, 1, 2, 3);
        $this->resource(4, 'Private media', false);
        $this->resource(5, 'Public media');
        foreach ([4 => 'secret', 5 => 'public'] as $id => $storage) {
            $this->db->insert('media', ['id' => $id, 'item_id' => 1, 'storage_id' => $storage, 'has_thumbnails' => 1, 'position' => $id]);
        }
        $this->build()->run();
        $doc = $this->client->collections[$this->profile->collection()]->documents['1']->retrieve();
        self::assertStringNotContainsString('Secret', json_encode($doc));
        self::assertSame(['Public author'], $doc['author_ss']);
        self::assertSame('/files/medium/public.jpg', $doc['thumbnail_url']);
        $this->db->executeStatement('UPDATE resource SET is_public = 0 WHERE id = 1');
        $this->queue->enqueue($this->registry->names(), [1]);
        $this->incremental()->drain();
        self::assertSame(0, $this->client->collections[$this->profile->collection()]->retrieve()['num_documents']);
    }

    public function testPrivateCachedTitlesAreRedactedOnItemsAndLinkedResources(): void
    {
        $this->db->insert('vocabulary', ['id' => 2, 'prefix' => 'dcterms']);
        $this->db->insert('property', ['id' => 3, 'vocabulary_id' => 2, 'local_name' => 'title']);
        $this->value(1, 1, 3, null, 'Secret cached title', false);
        $this->value(2, 3, 3, null, 'Secret author title', false);
        $this->value(3, 1, 2, 3);
        $this->db->executeStatement("UPDATE resource SET title = 'Secret cached title' WHERE id = 1");
        $source = new OmekaSourceRepository($this->db, $this->profile);
        self::assertSame('', $source->rows([1])[0]['title']);
        self::assertSame('', $source->loadValues([1])[1]['bibo:authorList'][0]['title']);
        $this->value(4, 1, 3, null, 'Public fallback title');
        self::assertSame('Public fallback title', $source->rows([1])[0]['title']);
    }

    public function testCancellationKeepsOldAliasAndPendingWork(): void
    {
        $old = $this->build()->run()['collection'];
        $this->queue->enqueue($this->registry->names(), [1]);
        $cancel = static fn(): bool => true;
        try {
            $this->build($cancel)->run();
            self::fail('Cancelled rebuild completed.');
        } catch (\DRESearch\Indexer\Exception\ReindexCancelledException) {
            self::assertSame($old, (new GenerationPublisher($this->client, $this->profile->collection()))->target());
            self::assertNotEmpty($this->queue->page($this->profile->name()));
        }
    }

    public function testReverseCountsExcludePrivateLinksEvenWhenProfileRequestsAll(): void
    {
        $this->value(1, 1, 2, 3, null, false);
        $source = new OmekaSourceRepository($this->db, $this->profile);
        self::assertSame([], $source->reverseCount([3], ['public_only' => false]));
        $this->db->executeStatement('UPDATE value SET is_public = 1');
        self::assertSame([3 => 1], $source->reverseCount([3], []));
        $this->db->executeStatement('UPDATE resource SET is_public = 0 WHERE id = 1');
        self::assertSame([], $source->reverseCount([3], []));
    }

    public function testEventsQueueOnlyProfilesWhoseScopeHoldsTheItemOrItsDependants(): void
    {
        $listener = new ItemEventListener($this->incremental(), $this->db);
        $this->resource(5, 'Volume A');
        $this->resource(6, 'Volume B');
        $this->db->insert('item_item_set', ['item_id' => 5, 'item_set_id' => 29918]);
        $this->db->insert('item_item_set', ['item_id' => 6, 'item_set_id' => 29918]);
        // Publication 1 moves its isPartOf-style link from publication 5 to 6:
        // itself plus the former AND the current target are refreshed.
        $this->value(1, 1, 2, 5);
        $request = new Request('update', 'items');
        $request->setId(1);
        $listener->onItemUpdatePre(new Event('api.update.pre', null, ['request' => $request]));
        $this->db->executeStatement('UPDATE value SET value_resource_id = 6 WHERE id = 1');
        $listener->onItemUpdate(new Event('api.update.post', null, ['request' => $request, 'response' => new Response($this->entity(1))]));
        self::assertSame([1, 5, 6], $this->queued());
        $this->db->executeStatement('DELETE FROM dre_search_change');

        // Author 3 lives outside the publications scope: editing it queues the
        // in-scope publication that embeds its name, never the author itself.
        $this->value(2, 1, 2, 3);
        $author = new Request('update', 'items');
        $author->setId(3);
        $listener->onItemUpdatePre(new Event('api.update.pre', null, ['request' => $author]));
        $listener->onItemUpdate(new Event('api.update.post', null, ['request' => $author, 'response' => new Response($this->entity(3))]));
        self::assertSame([1], $this->queued());
        $this->db->executeStatement('DELETE FROM dre_search_change');

        $this->resource(7, 'New publication');
        $this->db->insert('item_item_set', ['item_id' => 7, 'item_set_id' => 29918]);
        $listener->onItemCreate(new Event('api.create.post', null, ['response' => new Response($this->entity(7))]));
        self::assertSame([7], $this->queued());
        $this->db->executeStatement('DELETE FROM dre_search_change');

        $media = new Media();
        $media->setItem($this->entity(1));
        $listener->onMediaSave(new Event('api.create.post', null, ['response' => new Response($media)]));
        self::assertSame([1, 6], $this->queued());
    }

    public function testDeletedOrDescopedItemsAreRemovedFromTheCorpusThatIndexedThem(): void
    {
        $this->resource(8, 'Second publication');
        $this->db->insert('item_item_set', ['item_id' => 8, 'item_set_id' => 29918]);
        $this->build()->run();
        $listener = new ItemEventListener($this->incremental(), $this->db);

        // Deleted: the row is gone after the write, so only the scope captured
        // in the pre event can route the change to this corpus.
        $request = new Request('delete', 'items');
        $request->setId(1);
        $listener->onItemDeletePre(new Event('api.delete.pre', null, ['request' => $request]));
        $this->db->executeStatement('DELETE FROM item_item_set WHERE item_id = 1');
        $this->db->executeStatement('DELETE FROM resource WHERE id = 1');
        $listener->onItemDelete(new Event('api.delete.post', null, ['request' => $request]));
        self::assertSame([1], $this->queued());

        // Removed from the item set: still public, but no longer in this scope.
        $update = new Request('update', 'items');
        $update->setId(8);
        $listener->onItemUpdatePre(new Event('api.update.pre', null, ['request' => $update]));
        $this->db->executeStatement('DELETE FROM item_item_set WHERE item_id = 8');
        $listener->onItemUpdate(new Event('api.update.post', null, ['request' => $update, 'response' => new Response($this->entity(8))]));
        self::assertSame([1, 8], $this->queued());

        $this->incremental()->drain();
        self::assertSame(0, $this->client->collections[$this->profile->collection()]->retrieve()['num_documents']);
        self::assertSame([], $this->queued());
    }

    public function testItemSetDeletionAndTemplateChangesQueueTheirItems(): void
    {
        $listener = new ItemEventListener($this->incremental(), $this->db);
        $set = new Request('delete', 'item_sets');
        $set->setId(29918);
        $listener->onItemSetDeletePre(new Event('api.delete.pre', null, ['request' => $set]));
        $this->db->executeStatement('DELETE FROM item_item_set WHERE item_set_id = 29918');
        $listener->onItemSetDelete(new Event('api.delete.post', null, ['request' => $set]));
        self::assertSame([1], $this->queued());
        $this->db->executeStatement('DELETE FROM dre_search_change');

        $this->db->insert('item_item_set', ['item_id' => 1, 'item_set_id' => 29918]);
        $this->db->executeStatement('UPDATE resource SET resource_template_id = 11 WHERE id = 1');
        $template = new Request('update', 'resource_templates');
        $template->setId(11);
        $listener->onResourceTemplatePre(new Event('api.update.pre', null, ['request' => $template]));
        $listener->onResourceTemplatePost(new Event('api.update.post', null, ['request' => $template]));
        self::assertSame([1], $this->queued());
    }

    public function testQueueAcknowledgementCannotLoseNewerEditOrDeletedThenRecreatedWork(): void
    {
        $this->queue->enqueue($this->registry->names(), [1, 1]);
        $old = $this->queue->page($this->profile->name());
        $this->queue->enqueue($this->registry->names(), [1]);
        $this->queue->acknowledge($this->profile->name(), $old);
        self::assertCount(1, $this->queue->page($this->profile->name()));
        $new = $this->queue->page($this->profile->name());
        $this->queue->acknowledge($this->profile->name(), $new);
        $this->queue->enqueue($this->registry->names(), [1]);
        $this->queue->acknowledge($this->profile->name(), $old);
        self::assertCount(1, $this->queue->page($this->profile->name()));
    }

    public function testEditAndDirtyMarkerDuringRebuildSurvivePromotion(): void
    {
        $this->build()->run();
        $checkpoint = 0;
        $cancel = function () use (&$checkpoint): bool {
            if (++$checkpoint === 4) {
                $this->db->executeStatement("UPDATE resource SET title = 'Edited during rebuild' WHERE id = 1");
                $this->queue->enqueue($this->registry->names(), [1]);
                $this->state->markDirty($this->registry->names(), 'Unknown additional dependencies');
            }
            return false;
        };
        $this->build($cancel)->run();
        $doc = $this->client->collections[$this->profile->collection()]->documents['1']->retrieve();
        self::assertSame('Edited during rebuild', $doc['title']);
        self::assertSame([], $this->queue->page($this->profile->name()));
        self::assertSame(1, (int) $this->state->all()[$this->profile->name()]['dirty']);
        $this->build()->run();
        self::assertSame(0, (int) $this->state->all()[$this->profile->name()]['dirty']);
    }

    public function testLostSuccessfulPromotionResponseNeverDeletesLiveCollection(): void
    {
        $transport = new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $response = (new \GuzzleHttp\Client(['timeout' => 3]))->sendRequest($request);
                if ($request->getMethod() === 'PUT' && str_contains($request->getUri()->getPath(), '/aliases/')) {
                    throw new \RuntimeException('Lost successful PUT response');
                }
                return $response;
            }
        };
        $client = new Client([
            'api_key' => getenv('TYPESENSE_API_KEY'), 'num_retries' => 0, 'client' => $transport,
            'nodes' => [['host' => getenv('TYPESENSE_HOST'), 'port' => (string) (getenv('TYPESENSE_PORT') ?: 8108), 'protocol' => 'http']],
        ]);
        $result = (new Reindexer($this->db, $client, $this->profile, static function (): void {
        }, $this->state))->run();
        self::assertSame($result['collection'], (new GenerationPublisher($this->client, $this->profile->collection()))->target());
        self::assertFalse((new GenerationPublisher($this->client, $this->profile->collection()))->deleteUnaliased($result['collection']));
        self::assertSame(1, $this->client->collections[$result['collection']]->retrieve()['num_documents']);
    }

    public function testLocksExcludeOtherWorkersAndCacheInvalidationSurvivesLateFill(): void
    {
        $other = DriverManager::getConnection($this->dbParams + ['dbname' => $this->database]);
        $lock = new RebuildLock($this->db, $this->profile->name(), $this->profile->collection());
        $lock->acquire();
        try {
            try {
                (new RebuildLock($other, $this->profile->name(), $this->profile->collection()))->acquire();
                self::fail('Concurrent profile worker acquired the lock.');
            } catch (\DRESearch\Indexer\Exception\RebuildLockedException) {
                self::assertTrue(true);
            }
        } finally {
            $lock->release();
            $other->close();
        }
        $cache = new SearchCache($this->db);
        $oldKey = $cache->key('same request');
        $cache->invalidate();
        $cache->put($oldKey, ['stale'], 30);
        self::assertNull((new SearchCache($this->db))->get($cache->key('same request')));
    }

    public function testPendingChangesHideTheirDocumentsInsteadOfPausingTheCorpus(): void
    {
        $this->resource(8, 'Public second title');
        $this->db->insert('item_item_set', ['item_id' => 8, 'item_set_id' => 29918]);
        $this->build()->run();
        $proxy = $this->proxy();
        self::assertSame(2, $proxy->search($this->profile->name(), [])['found']);

        // Item 1 was just made private; its indexed copy is stale until drained.
        $this->db->executeStatement('UPDATE resource SET is_public = 0 WHERE id = 1');
        $this->queue->enqueue($this->registry->names(), [1]);
        $result = $proxy->search($this->profile->name(), []);
        self::assertTrue($result['available']);
        self::assertSame(['8'], array_column($result['hits'], 'id'));
        foreach ($proxy->suggestAll('Public')['groups'] as $group) {
            self::assertNotContains('1', array_column($group['suggestions'], 'id'));
        }
        $export = $proxy->export($this->profile->name(), []);
        self::assertSame(['8'], array_column($export['docs'], 'id'));

        // Beyond the exclusion limit the corpus pauses rather than hiding ids.
        self::assertFalse($this->proxy(0)->search($this->profile->name(), [])['available']);
        // A dirty marker (unknown impact) pauses it regardless.
        $this->state->markDirty([$this->profile->name()], 'test');
        self::assertFalse($this->proxy()->search($this->profile->name(), [])['available']);
        $this->db->executeStatement('UPDATE dre_search_profile_state SET dirty = 0');

        $this->incremental()->drain();
        $after = $proxy->search($this->profile->name(), []);
        self::assertSame(['8'], array_column($after['hits'], 'id'));
        self::assertSame(1, $this->client->collections[$this->profile->collection()]->retrieve()['num_documents']);
    }

    public function testStrandedQueueWakesOneWorkerThroughTheLease(): void
    {
        $woken = 0;
        $lease = new WorkerLease($this->db);
        $gate = new \DRESearch\Search\ReadinessGate($this->db, 250, function () use (&$woken, $lease): void {
            $lease->request();
            if ($lease->claim()) {
                $woken++;
            }
        }, 60);
        $this->queue->enqueue($this->registry->names(), [1]);
        $gate->check($this->registry->names());
        self::assertSame(0, $woken, 'A fresh queue is the worker\'s job, not the gate\'s.');
        $this->db->executeStatement("UPDATE dre_search_change SET queued_at = '2000-01-01 00:00:00'");
        $gate->reset();
        $gate->check($this->registry->names());
        $gate->reset();
        $gate->check($this->registry->names());
        self::assertSame(1, $woken, 'Only the first stranded check may start a worker.');
    }

    public function testWorkerLeaseNeverStrandsARequestRaisedDuringAPass(): void
    {
        $lease = new WorkerLease($this->db);
        self::assertTrue($lease->claim());
        self::assertFalse($lease->claim(), 'A live worker blocks a second dispatch.');
        $lease->begin();
        $lease->request(); // a write lands while the pass runs
        self::assertFalse($lease->finish(), 'The worker must loop for the new request.');
        $lease->begin();
        self::assertTrue($lease->finish());
        self::assertTrue($lease->claim(), 'An exited worker frees the lease.');
        $lease->backoff();
        self::assertFalse($lease->claim(), 'A failed pass holds the lease for one stale window.');
        $this->db->executeStatement("UPDATE dre_search_worker SET heartbeat = '2000-01-01 00:00:00'");
        self::assertTrue($lease->claim(), 'A dead worker can be replaced.');
        self::assertTrue($lease->status()['requested']);
    }

    public function testRejectedDocumentIsRemovedAndCannotBlockTheQueue(): void
    {
        $this->resource(8, 'Second publication');
        $this->db->insert('item_item_set', ['item_id' => 8, 'item_set_id' => 29918]);
        $live = $this->build()->run()['collection'];
        $this->queue->enqueue($this->registry->names(), [1, 8]);
        $transport = new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                if (str_ends_with($request->getUri()->getPath(), '/documents/import')) {
                    $lines = array_filter(explode("\n", (string) $request->getBody()));
                    $out = [];
                    foreach ($lines as $line) {
                        $doc = json_decode($line, true);
                        $out[] = json_encode(($doc['id'] ?? '') === '1'
                            ? ['success' => false, 'code' => 400, 'error' => 'Injected schema violation', 'document' => $line]
                            : ['success' => true]);
                    }
                    return new \GuzzleHttp\Psr7\Response(200, [], implode("\n", $out));
                }
                return (new \GuzzleHttp\Client(['timeout' => 3]))->sendRequest($request);
            }
        };
        $client = new Client([
            'api_key' => getenv('TYPESENSE_API_KEY'), 'num_retries' => 0, 'client' => $transport,
            'nodes' => [['host' => getenv('TYPESENSE_HOST'), 'port' => (string) (getenv('TYPESENSE_PORT') ?: 8108), 'protocol' => 'http']],
        ]);
        $rejected = (new Reindexer($this->db, $client, $this->profile, static function (): void {
        }))->drainQueue($this->queue, $live);
        self::assertSame([1], $rejected);
        self::assertSame([], $this->queued(), 'One bad record must not hold the corpus paused.');
        $ids = array_column(array_column($this->client->collections[$live]->documents->search(['q' => '*', 'query_by' => 'title'])['hits'], 'document'), 'id');
        self::assertSame(['8'], $ids, 'The rejected record\'s stale copy is removed.');
    }

    public function testShrinkGuardRefusesToPromoteAnAlmostEmptyCorpus(): void
    {
        foreach ([8, 9] as $id) {
            $this->resource($id, 'Publication ' . $id);
            $this->db->insert('item_item_set', ['item_id' => $id, 'item_set_id' => 29918]);
        }
        $live = $this->build()->run()['collection'];
        $this->db->executeStatement('DELETE FROM item_item_set WHERE item_id IN (8, 9)');
        try {
            $this->build()->run();
            self::fail('A 3 → 1 document rebuild was promoted.');
        } catch (\DRESearch\Indexer\Exception\VerificationException) {
            self::assertSame($live, (new GenerationPublisher($this->client, $this->profile->collection()))->target());
        }
        $forced = new Reindexer($this->db, $this->client, $this->profile, static function (): void {
        }, $this->state, 0, 'test', null, 0.5, true);
        self::assertSame(1, $forced->run()['documents']);
    }

    public function testDrainForgetsQueuedWorkForANeverBuiltProfile(): void
    {
        $this->queue->enqueue($this->registry->names(), [1]);
        $pass = $this->incremental()->drain();
        self::assertSame([], $pass['failures']);
        self::assertSame([], $this->queued(), 'Its first rebuild reads current SQL state anyway.');
    }

    public function testTargetedAuthorityResolutionMatchesTheFullLoad(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $items = SearchProfile::fromArray('research_items', $config['dre_search']['profiles']['research_items']);
        $this->db->insert('vocabulary', ['id' => 2, 'prefix' => 'dcterms']);
        $this->db->insert('property', ['id' => 3, 'vocabulary_id' => 2, 'local_name' => 'title']);
        $this->db->insert('property', ['id' => 4, 'vocabulary_id' => 2, 'local_name' => 'type']);
        $this->db->insert('property', ['id' => 5, 'vocabulary_id' => 2, 'local_name' => 'isPartOf']);
        // A city (set 1) that is part of a country (set 1), and a project (set 20).
        foreach ([[20, 'Nairobi', 1], [21, 'Kenya', 1], [22, 'A project', 20]] as [$id, $title, $setId]) {
            $this->resource($id, $title);
            $this->db->insert('item_item_set', ['item_id' => $id, 'item_set_id' => $setId]);
            $this->value(100 + $id, $id, 3, null, $title);
        }
        $this->value(130, 20, 5, 21);
        $this->value(131, 21, 4, 3168);
        $this->resource(3168, 'Country');
        $full = new \DRESearch\Indexer\AuthorityResolver($this->db, $items);
        $full->load();
        $targeted = new \DRESearch\Indexer\AuthorityResolver($this->db, $items);
        $targeted->prime([20, 22]);
        foreach ([20, 21, 22] as $id) {
            self::assertSame($full->title($id), $targeted->title($id), "title of $id");
            self::assertSame($full->partOfId($id), $targeted->partOfId($id), "partOf of $id");
            self::assertSame($full->typeItemId($id), $targeted->typeItemId($id), "type of $id");
        }
        self::assertTrue($targeted->inSet(22, 20));
        self::assertSame('Kenya', $targeted->title((int) $targeted->partOfId(20)), 'Parents are primed for the country roll-up.');
    }

    public function testUpgradeAddsQueueAndRevisionWithoutLosingExistingGeneration(): void
    {
        $old = $this->build()->run()['collection'];
        $this->db->executeStatement('ALTER TABLE dre_search_profile_state DROP COLUMN dirty_revision');
        $this->db->executeStatement('DROP TABLE dre_search_change');
        $this->db->executeStatement('DROP TABLE dre_search_cache');
        $this->db->executeStatement('DROP TABLE dre_search_worker');
        $this->db->executeStatement('ALTER TABLE dre_search_profile_state DROP COLUMN rejected_ids');
        // Omeka runs upgrade() while the module is inactive, so the service
        // manager holds ONLY core services — never this module's factories.
        // A local.config.php profile override must still be marked dirty.
        $services = new ServiceManager();
        $services->setService('Omeka\\Connection', $this->db);
        $services->setService('Config', ['dre_search' => ['profiles' => ['local_extra' => ['collection' => 'x']]]]);
        self::assertFalse($services->has(RebuildStateStore::class));
        self::assertFalse($services->has(ProfileRegistry::class));
        (new \DRESearch\Module())->upgrade('1.21.3', '1.22.1', $services);
        $all = $this->state->all();
        self::assertSame(1, (int) ($all['local_extra']['dirty'] ?? 0));
        self::assertSame(1, (int) ($all['research_items']['dirty'] ?? 0));
        $state = $all[$this->profile->name()];
        self::assertSame($old, $state['live_collection']);
        self::assertSame(1, (int) $state['dirty']);
        self::assertSame(32, strlen($state['dirty_revision']));
        self::assertArrayHasKey('rejected_ids', $state);
        self::assertTrue((new WorkerLease($this->db))->claim(), 'The 1.23 migration creates the worker lease table.');
        self::assertSame([], $this->queue->counts());
        $proxy = new SearchProxy($this->provider, $this->registry, new BlockScopeResolver($this->db), $this->logger, [], $this->db);
        self::assertFalse($proxy->search($this->profile->name(), [])['available']);
        $this->build()->run();
        self::assertTrue($proxy->search($this->profile->name(), [])['available']);
    }

    public function testPrivacyEditDuringScanIsReplayedBeforePublishing(): void
    {
        $checkpoint = 0;
        $cancel = function () use (&$checkpoint): bool {
            if (++$checkpoint === 4) {
                $this->db->executeStatement('UPDATE resource SET is_public = 0 WHERE id = 1');
                $this->queue->enqueue($this->registry->names(), [1]);
            }
            return false;
        };
        $result = $this->build($cancel)->run();
        self::assertSame(1, $result['attempted']);
        self::assertSame(0, $result['documents']);
        self::assertSame(0, $this->client->collections[$result['collection']]->retrieve()['num_documents']);
        self::assertSame([], $this->queue->page($this->profile->name()));
    }

    public function testQueuePagesBeyondOneBatchAndPreservesNewRevision(): void
    {
        $this->queue->enqueue($this->registry->names(), range(1, 205));
        $first = $this->queue->page($this->profile->name());
        $second = $this->queue->page($this->profile->name(), 100);
        $last = $this->queue->page($this->profile->name(), 200);
        self::assertCount(100, $first);
        self::assertCount(100, $second);
        self::assertCount(5, $last);
        $this->queue->enqueue($this->registry->names(), [101]);
        $this->queue->acknowledge($this->profile->name(), array_merge($first, $second, $last));
        self::assertSame([101], array_map('intval', array_column($this->queue->page($this->profile->name()), 'item_id')));
    }

    public function testFailedImportPreservesLiveAliasAndPendingChanges(): void
    {
        $old = $this->build()->run()['collection'];
        $this->queue->enqueue($this->registry->names(), [1]);
        $transport = new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                if (str_ends_with($request->getUri()->getPath(), '/documents/import')) {
                    return new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                        'success' => false, 'code' => 400, 'error' => 'Injected document validation failure',
                    ]));
                }
                return (new \GuzzleHttp\Client(['timeout' => 3]))->sendRequest($request);
            }
        };
        $client = new Client([
            'api_key' => getenv('TYPESENSE_API_KEY'), 'num_retries' => 0, 'client' => $transport,
            'nodes' => [['host' => getenv('TYPESENSE_HOST'), 'port' => (string) (getenv('TYPESENSE_PORT') ?: 8108), 'protocol' => 'http']],
        ]);
        try {
            (new Reindexer($this->db, $client, $this->profile, static function (): void {
            }, $this->state))->run();
            self::fail('Failed import was promoted.');
        } catch (\DRESearch\Indexer\Exception\BatchImportException) {
            self::assertSame($old, (new GenerationPublisher($this->client, $this->profile->collection()))->target());
            self::assertCount(1, $this->queue->page($this->profile->name()));
            self::assertSame('batch_import_failed', $this->state->all()[$this->profile->name()]['last_error_code']);
            $owned = array_filter($this->client->collections->retrieve(), fn(array $row): bool => str_starts_with($row['name'], $this->prefix));
            self::assertCount(1, $owned);
        }
    }

    private function build(?\Closure $cancel = null): Reindexer
    {
        return new Reindexer($this->db, $this->client, $this->profile, static function (): void {
        }, $this->state, 30, 'test', $cancel);
    }

    private function incremental(): IncrementalIndexer
    {
        return new IncrementalIndexer($this->db, $this->provider, $this->registry, $this->logger, $this->state);
    }

    private function proxy(int $exclusionLimit = 250): SearchProxy
    {
        return new SearchProxy(
            $this->provider,
            $this->registry,
            new BlockScopeResolver($this->db),
            $this->logger,
            [],
            $this->db,
            new \DRESearch\Search\ReadinessGate($this->db, $exclusionLimit),
        );
    }

    /** @return list<int> ids queued for the test profile */
    private function queued(): array
    {
        return array_map('intval', array_column($this->queue->page($this->profile->name()), 'item_id'));
    }

    private function entity(int $id): Item
    {
        $item = new Item();
        (new \ReflectionProperty(\Omeka\Entity\Resource::class, 'id'))->setValue($item, $id);
        return $item;
    }

    private function resource(int $id, string $title, bool $public = true): void
    {
        $this->db->insert('resource', ['id' => $id, 'title' => $title, 'is_public' => (int) $public, 'resource_type' => Item::class]);
    }

    private function value(int $id, int $source, int $property, ?int $target, ?string $literal = null, bool $public = true): void
    {
        $this->db->insert('value', [
            'id' => $id, 'resource_id' => $source, 'property_id' => $property,
            'value_resource_id' => $target, 'value' => $literal, 'is_public' => (int) $public,
        ]);
    }
}
