<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use PHPUnit\Framework\TestCase;

/** The proxy's own orchestration, without a Typesense to answer it. */
final class SearchProxyTest extends TestCase
{
    use ProfileFixture;

    protected function setUp(): void
    {
        if (!class_exists(DriverManager::class)) {
            self::markTestSkipped('Requires Omeka core (Doctrine DBAL).');
        }
    }

    public function testFederatedSearchResolvesTheBlockScopeOnce(): void
    {
        $db = $this->blockDatabase();
        $log = new \Doctrine\DBAL\Logging\DebugStack();
        $db->getConfiguration()->setSQLLogger($log);
        // Nothing listens there: the counts and the active search both fail
        // fast, which is all this test needs to reach the active search.
        $provider = new TypesenseClientProvider('127.0.0.1', 18199, 'http', 'k', 2.0, 2.0, 0.5);

        $result = $this->proxy($provider, $db)->searchAll('research_items', ['q' => 'river', 'block_id' => 7]);

        self::assertFalse($result['available']);
        $lookups = array_filter(
            $log->queries,
            static fn(array $query): bool => str_contains((string) $query['sql'], 'site_page_block'),
        );
        self::assertCount(1, $lookups, 'The active search reuses the scope searchAll() resolved.');
    }

    /**
     * A map whose pages came back short used to be refused without a word in
     * the log, unlike an export. Both now log a failed request as the
     * operation and a short pull as `<operation>_verification`.
     */
    public function testShortAndFailedBulkPullsAreLogged(): void
    {
        $writer = new \Laminas\Log\Writer\Mock();
        $proxy = $this->proxy(new TypesenseClientProvider('', 0, 'http', ''), $this->blockDatabase(), $writer);
        $pull = new \ReflectionMethod($proxy, 'pull');
        $profile = $this->researchItems();

        $short = static fn(int $page): array => ['found' => 3, 'hits' => [['document' => ['id' => '1']]]];
        self::assertNull($pull->invoke($proxy, 'map', new \DRESearch\Search\BulkPager(1000), $short, $profile, 'req-1'));
        $down = static fn(int $page): array => throw new \RuntimeException('Typesense is down');
        self::assertNull($pull->invoke($proxy, 'export', new \DRESearch\Search\BulkPager(1000), $down, $profile, 'req-2'));

        self::assertSame(
            [['map_verification', 'req-1'], ['export', 'req-2']],
            array_map(static fn(array $event): array => [$event['extra']['operation'], $event['extra']['request_id']], $writer->events),
        );
        self::assertSame('Typesense returned 1 of 3 expected documents.', $writer->events[0]['extra']['message']);
    }

    private function proxy(TypesenseClientProvider $provider, Connection $db, ?\Laminas\Log\Writer\WriterInterface $writer = null): SearchProxy
    {
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter($writer ?? new \Laminas\Log\Writer\Noop());
        return new SearchProxy(
            $provider,
            new ProfileRegistry(['research_items' => $this->researchItems()]),
            new BlockScopeResolver($db),
            $logger,
        );
    }

    /** A small profile under the name the stored `dreSearch` layout maps to. */
    private function researchItems(): \DRESearch\Settings\SearchProfile
    {
        return \DRESearch\Settings\SearchProfile::fromArray('research_items', [
            'label' => 'Research items',
            'collection' => 'records_current',
            'template_id' => 10,
            'query_by' => 'title',
            'facets' => ['type_s' => ['property' => 'dcterms:type', 'label' => 'Type', 'array' => false]],
        ]);
    }

    /** One saved research-items block (layout `dreSearch`) with a locked filter. */
    private function blockDatabase(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE site_page_block (id INTEGER PRIMARY KEY, layout TEXT, data TEXT)');
        $db->executeStatement(
            'INSERT INTO site_page_block (id, layout, data) VALUES (7, ?, ?)',
            ['dreSearch', json_encode(['locked_filter' => 'type_s:=`Book`'], JSON_THROW_ON_ERROR)],
        );
        return $db;
    }
}
