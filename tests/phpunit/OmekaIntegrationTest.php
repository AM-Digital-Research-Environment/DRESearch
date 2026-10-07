<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DRESearch\Indexer\AnalyticsSync;
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
use DRESearch\Search\PopularModeration;
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
    use OmekaSchema;

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
        if (!self::omekaAvailable() || !getenv('TYPESENSE_HOST')) {
            self::markTestSkipped('Requires Omeka core, disposable MySQL and Typesense; see CONTRIBUTING.md.');
        }
        $this->createOmekaDatabase();
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
        $client = $this->provider->getClient();
        self::assertNotNull($client);
        $this->client = $client;
        $this->state = new RebuildStateStore($this->db);
        $this->queue = new ChangeQueue($this->db);
        $this->logger = new Logger();
        $this->logger->addWriter(new \Laminas\Log\Writer\Noop());
        $this->item(1, 'Public title');
        $this->item(2, 'Private author', false);
        $this->item(3, 'Public author');
        $this->member(1, 29918);
        $this->property(1, 'bibo:abstract');
        $this->property(2, 'bibo:authorList');
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
        $this->dropOmekaDatabase();
    }

    public function testPublicDocumentExcludesPrivateValuesTargetsAndMedia(): void
    {
        $this->value(1, 1, 1, null, 'Secret abstract', false);
        $this->value(2, 1, 2, 2);
        $this->value(3, 1, 2, 3);
        $this->media(4, 1, 'secret', false);
        $this->media(5, 1, 'public');
        $this->build()->run();
        $doc = $this->client->collections[$this->profile->collection()]->documents['1']->retrieve();
        self::assertStringNotContainsString('Secret', json_encode($doc, JSON_THROW_ON_ERROR));
        self::assertSame(['Public author'], $doc['author_ss']);
        self::assertSame('/files/medium/public.jpg', $doc['thumbnail_url']);
        $this->db->executeStatement('UPDATE resource SET is_public = 0 WHERE id = 1');
        $this->queue->enqueue($this->registry->names(), [1]);
        $this->incremental()->drain();
        self::assertSame(0, $this->client->collections[$this->profile->collection()]->retrieve()['num_documents']);
    }

    public function testPrivateCachedTitlesAreRedactedOnItemsAndLinkedResources(): void
    {
        $this->property(3, 'dcterms:title');
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
        $this->item(5, 'Volume A');
        $this->item(6, 'Volume B');
        $this->member(5, 29918);
        $this->member(6, 29918);
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

        $this->item(7, 'New publication');
        $this->member(7, 29918);
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
        $this->item(8, 'Second publication');
        $this->member(8, 29918);
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

    public function testMediaDeletionQueuesItsParentAndDropsItsThumbnail(): void
    {
        $this->media(4, 1, 'scan');
        $this->build()->run();
        $documents = $this->client->collections[$this->profile->collection()]->documents;
        self::assertSame('/files/medium/scan.jpg', $documents['1']->retrieve()['thumbnail_url']);
        $listener = new ItemEventListener($this->incremental(), $this->db);

        // The media row, and with it the parent's id, is gone after the write:
        // only the pre event can name the item to refresh.
        $request = new Request('delete', 'media');
        $request->setId(4);
        $listener->onMediaDeletePre(new Event('api.delete.pre', null, ['request' => $request]));
        $this->db->executeStatement('DELETE FROM resource WHERE id = 4');
        self::assertSame([], $this->queued(), 'Nothing is queued before the write.');
        $listener->onMediaDelete(new Event('api.delete.post', null, ['request' => $request]));
        self::assertSame([1], $this->queued());

        $this->incremental()->drain();
        self::assertArrayNotHasKey('thumbnail_url', $documents['1']->retrieve());
        self::assertSame([], $this->queued());
    }

    public function testItemSetDeletionQueuesItsMembersAndRemovesThemFromTheCorpus(): void
    {
        $this->item(8, 'Second publication');
        $this->member(8, 29918);
        $this->item(9, 'Elsewhere');
        $this->member(9, 30000);
        $this->build()->run();
        self::assertSame(2, $this->client->collections[$this->profile->collection()]->retrieve()['num_documents']);
        $listener = new ItemEventListener($this->incremental(), $this->db);

        // Deleting the set cascades its membership rows away: the members must
        // be captured before the write, and the corpus scoped to the set must
        // drop their documents.
        $set = new Request('delete', 'item_sets');
        $set->setId(29918);
        $listener->onItemSetDeletePre(new Event('api.delete.pre', null, ['request' => $set]));
        $this->db->executeStatement('DELETE FROM resource WHERE id = 29918');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM item_item_set WHERE item_set_id = 29918'));
        $listener->onItemSetDelete(new Event('api.delete.post', null, ['request' => $set]));
        self::assertSame([1, 8], $this->queued(), 'Its members only, not item 9 of another set.');

        $this->incremental()->drain();
        self::assertSame(0, $this->client->collections[$this->profile->collection()]->retrieve()['num_documents']);
        self::assertSame([], $this->queued());
    }

    public function testTemplateChangesQueueTheirItems(): void
    {
        $listener = new ItemEventListener($this->incremental(), $this->db);
        $this->template(11);
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
                $this->addToAssertionCount(1);
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
        $this->item(8, 'Public second title');
        $this->member(8, 29918);
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

    public function testHealthReportsCorpusStatesWithoutRecordIds(): void
    {
        $this->item(8, 'Second publication');
        $this->member(8, 29918);
        $this->build()->run();
        $health = $this->proxy()->health();
        self::assertTrue($health['ok']);
        self::assertSame(['public' => 'live', 'pending' => 0], $health['profiles'][$this->profile->name()]);
        $this->queue->enqueue($this->registry->names(), [1]);
        $health = $this->proxy()->health();
        self::assertSame('hiding', $health['profiles'][$this->profile->name()]['public']);
        self::assertSame(1, $health['profiles'][$this->profile->name()]['pending']);
        self::assertIsInt($health['oldest_pending_seconds']);
        self::assertStringNotContainsString('"1"', json_encode($health['profiles'], JSON_THROW_ON_ERROR));
        self::assertSame('paused', $this->proxy(0)->health()['profiles'][$this->profile->name()]['public']);
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
        $this->item(8, 'Second publication');
        $this->member(8, 29918);
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
            $this->item($id, 'Publication ' . $id);
            $this->member($id, 29918);
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
        $this->property(3, 'dcterms:title');
        $this->property(4, 'dcterms:type');
        $this->property(5, 'dcterms:isPartOf');
        // A city that is part of a country (location set 1851), and a project
        // (set 20). Items and item sets share Omeka's resource ids.
        foreach ([[40, 'Nairobi', 1851], [41, 'Kenya', 1851], [42, 'A project', 20]] as [$id, $title, $setId]) {
            $this->item($id, $title);
            $this->member($id, $setId);
            $this->value(100 + $id, $id, 3, null, $title);
        }
        $this->item(3168, 'Country');
        $this->value(150, 40, 5, 41);
        $this->value(151, 41, 4, 3168);
        $full = new \DRESearch\Indexer\AuthorityResolver($this->db, $items);
        $full->load();
        $targeted = new \DRESearch\Indexer\AuthorityResolver($this->db, $items);
        $targeted->prime([40, 42]);
        foreach ([40, 41, 42] as $id) {
            self::assertSame($full->title($id), $targeted->title($id), "title of $id");
            self::assertSame($full->partOfId($id), $targeted->partOfId($id), "partOf of $id");
            self::assertSame($full->typeItemId($id), $targeted->typeItemId($id), "type of $id");
        }
        self::assertTrue($targeted->inSet(42, 20));
        self::assertSame('Kenya', $targeted->title((int) $targeted->partOfId(40)), 'Parents are primed for the country roll-up.');
    }

    public function testUpgradeAddsQueueAndRevisionWithoutLosingExistingGeneration(): void
    {
        $old = $this->build()->run()['collection'];
        $this->db->executeStatement('ALTER TABLE dre_search_profile_state DROP COLUMN dirty_revision');
        $this->db->executeStatement('DROP TABLE dre_search_change');
        $this->db->executeStatement('DROP TABLE dre_search_cache');
        $this->db->executeStatement('DROP TABLE dre_search_worker');
        $this->db->executeStatement('ALTER TABLE dre_search_profile_state DROP COLUMN rejected_ids');
        $this->db->executeStatement('DROP TABLE dre_search_popular_moderation');
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
        self::assertSame([], (new PopularModeration($this->db))->decisions('research_items'), 'The 1.25 migration creates the moderation table.');
        self::assertSame([], $this->queue->counts());
        $proxy = new SearchProxy($this->provider, $this->registry, new BlockScopeResolver($this->db), $this->logger, [], $this->db);
        self::assertFalse($proxy->search($this->profile->name(), [])['available']);
        $this->build()->run();
        self::assertTrue($proxy->search($this->profile->name(), [])['available']);
    }

    public function testUpgradeTo125CreatesTheModerationTableWithoutARebuild(): void
    {
        $old = $this->build()->run()['collection'];
        $this->db->executeStatement('DROP TABLE dre_search_popular_moderation');
        $services = new ServiceManager();
        $services->setService('Omeka\\Connection', $this->db);
        (new \DRESearch\Module())->upgrade('1.24.0', '1.25.0', $services);
        $state = $this->state->all()[$this->profile->name()];
        self::assertSame(0, (int) $state['dirty'], 'Moderation needs no rebuild.');
        self::assertSame($old, $state['live_collection']);
        $moderation = new PopularModeration($this->db);
        self::assertSame([], $moderation->decisions($this->profile->name()), 'Nothing starts approved.');
        $moderation->approve($this->profile->name(), 'kenya');
        (new \DRESearch\Module())->upgrade('1.24.0', '1.25.0', $services);
        self::assertSame(['kenya' => 'kenya'], $moderation->approved($this->profile->name()), 'Running the migration again keeps decisions.');
    }

    public function testOnlyApprovedPopularSearchesAreServedAndDecisionsInvalidateTheCache(): void
    {
        // A profile of its own, so no parallel run shares its analytics collections.
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $definition = $config['dre_search']['profiles']['research_publications'];
        $definition['collection'] = $this->prefix . '_popular_current';
        $name = $this->prefix;
        $registry = new ProfileRegistry([$name => SearchProfile::fromArray($name, $definition)]);
        $collections = [];
        foreach (
            [
                'popular' => [
                    ['id' => 'a', 'q' => 'kenya', 'count' => 30],
                    ['id' => 'b', 'q' => 'offensive text', 'count' => 25],
                    ['id' => 'c', 'q' => 'nothing here', 'count' => 20],
                    ['id' => 'd', 'q' => 'bayreuth', 'count' => 12],
                    ['id' => 'e', 'q' => 'koforidua', 'count' => 6],
                    ['id' => 'f', 'q' => 'zanzibar', 'count' => 3],
                ],
                'nohits' => [['id' => 'a', 'q' => 'nothing here', 'count' => 20]],
            ] as $suffix => $documents
        ) {
            $collections[] = $collection = AnalyticsSync::collectionName($name, $suffix);
            $this->client->collections->create(['name' => $collection, 'fields' => [
                ['name' => 'q', 'type' => 'string'],
                ['name' => 'count', 'type' => 'int32'],
            ]]);
            $this->client->collections[$collection]->documents->import($documents);
        }
        try {
            $popular = ['enabled' => true, 'min_count' => 5, 'limit' => 2];
            $proxy = new SearchProxy($this->provider, $registry, new BlockScopeResolver($this->db), $this->logger, [], $this->db, null, $popular);
            $moderation = new PopularModeration($this->db);
            self::assertSame(['available' => true, 'queries' => []], $proxy->popular($name), 'Nothing is served before an editor approves it.');

            $moderation->approve($name, ' Kenya ');
            $moderation->approve($name, 'zanzibar');
            self::assertSame(
                ['Kenya'],
                $proxy->popular($name)['queries'],
                'The approval replaced the cached empty list; the approved spelling is shown; a query run three times is no candidate.',
            );
            $moderation->hide($name, 'offensive text');
            $moderation->approve($name, 'bayreuth');
            $moderation->approve($name, 'koforidua');
            self::assertSame(['Kenya', 'bayreuth'], $proxy->popular($name)['queries'], 'Most-run approved queries first, up to the limit.');
            self::assertTrue($moderation->revoke($name, 'KENYA'));
            self::assertFalse($moderation->revoke($name, 'never decided'));
            self::assertSame(['bayreuth', 'koforidua'], $proxy->popular($name)['queries'], 'A revoked query is withdrawn at once.');

            try {
                $moderation->approve($name, 'jane.doe@example.org');
                self::fail('A query shaped like personal data cannot be approved.');
            } catch (\InvalidArgumentException) {
            }

            $controller = new \DRESearch\Controller\Admin\MaintenanceController(
                $this->provider,
                $registry,
                $this->state,
                $this->queue,
                null,
                250,
                $moderation,
                $popular,
            );
            $page = (new \ReflectionMethod($controller, 'collectPopular'))->invoke($controller);
            self::assertTrue($page['store']);
            self::assertTrue($page['analytics']);
            $corpus = $page['profiles'][0];
            $summary = static fn(array $rows): array => array_map(
                static fn(array $row): array => [$row['q'], $row['count'], $row['status'], $row['shown']],
                $rows,
            );
            self::assertSame(
                [
                    ['kenya', 30, 'pending', false],
                    ['bayreuth', 12, 'approved', true],
                    ['koforidua', 6, 'approved', true],
                    ['zanzibar', null, 'approved', false],
                ],
                $summary($corpus['rows']),
                'Candidates most-run first with their decision, then decisions on queries that are no candidates; no-hit queries are left out.',
            );
            self::assertSame([['offensive text', 25, 'hidden', false]], $summary($corpus['hidden']));

            $cache = new SearchCache($this->db);
            $before = $cache->key('popular');
            $moderation->hide($name, 'bayreuth');
            self::assertNotSame($before, $cache->key('popular'), 'A decision moves the cache epoch, so an earlier fill is never read.');
            self::assertSame(['koforidua'], $proxy->popular($name)['queries']);

            $this->db->executeStatement('DROP TABLE dre_search_popular_moderation');
            $cache->invalidate();
            self::assertSame(['available' => true, 'queries' => []], $proxy->popular($name), 'Without the moderation table nothing is served.');
        } finally {
            foreach ($collections as $collection) {
                $this->client->collections[$collection]->delete();
            }
        }
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
                    ], JSON_THROW_ON_ERROR));
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

    /**
     * @return list<int> ids queued for the test profile
     * @phpstan-impure reads the live queue table
     */
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
}
