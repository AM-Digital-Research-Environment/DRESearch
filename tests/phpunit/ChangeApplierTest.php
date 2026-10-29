<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\ChangeApplier;
use DRESearch\Indexer\ChangeQueue;
use DRESearch\Indexer\CollectionOps;
use DRESearch\Indexer\Exception\BatchImportException;
use DRESearch\Test\Support\SqliteOmeka;
use DRESearch\Test\Support\Typesense\FakeTypesense;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Mock;
use PHPUnit\Framework\TestCase;

/** Queued changes applied to a collection: reconciliation, rejections, acknowledgements. */
final class ChangeApplierTest extends TestCase
{
    use ProfileFixture;
    use SqliteOmeka;

    private FakeTypesense $server;
    private Mock $log;
    private int $invalidated = 0;

    protected function setUp(): void
    {
        $this->createSqliteOmeka();
        $this->server = new FakeTypesense();
        $this->log = new Mock();
        // In scope: public items of template 10. A previous build indexed 1–3.
        $this->item(1, 'Public record', 10);
        $this->item(2, 'Made private', 10, [], false);
        $this->item(3, 'Re-templated', 99);
        $this->item(5, 'New record', 10);
        $this->server->seed('records_g1', [1, 2, 3]);
    }

    public function testApplyReconcilesEveryIdWithCurrentSqlState(): void
    {
        self::assertSame([], $this->applier()->apply([1, 2, 3, 4, 5, 5], 'records_g1'));
        self::assertSame(['1', '5'], $this->server->ids('records_g1'), 'Private, descoped and deleted records are removed.');
        self::assertSame('New record', $this->server->collections['records_g1'][1]['title']);
    }

    public function testARejectedDocumentFailsAStagingReplay(): void
    {
        $this->server->importDecision = static fn(array $doc): bool => $doc['id'] !== '1';
        try {
            $this->applier()->apply([1, 5], 'records_g1');
            self::fail('A rejection was tolerated where it must stop the promotion.');
        } catch (BatchImportException $error) {
            self::assertSame(['1'], $error->failedIds());
        }
        self::assertSame([], $this->log->events);
    }

    public function testDrainRemovesARejectedDocumentAndAcknowledgesItsRow(): void
    {
        $this->queueFor('records', [1, 5]);
        $this->queueFor('other', [1]);
        $this->server->importDecision = static fn(array $doc): bool => $doc['id'] !== '1';

        $rejected = $this->applier()->drain(new ChangeQueue($this->db), 'records_g1');

        self::assertSame([1], $rejected);
        self::assertSame(['2', '3', '5'], $this->server->ids('records_g1'), 'Its stale copy is gone (fail closed); the batch carried on.');
        self::assertSame([['other', 1]], $this->queued(), 'One bad record cannot hold the queue; other profiles are untouched.');
        self::assertSame(1, $this->invalidated);
        $errors = array_values(array_filter($this->log->events, static fn(array $event): bool => $event['priorityName'] === 'ERR'));
        self::assertCount(1, $errors);
        self::assertStringContainsString('rejected 1 "records" document(s)', $errors[0]['message']);
        self::assertSame([1], $errors[0]['extra']['ids']);
        self::assertSame('records_g1', $errors[0]['extra']['collection']);
    }

    public function testDrainAcknowledgesOnlyTheRevisionItRead(): void
    {
        $this->queueFor('records', [5]);
        $edits = 0;
        // An edit lands while the batch is being applied: a new revision.
        $applier = $this->applier(function () use (&$edits): void {
            if ($edits++ === 0) {
                $this->db->executeStatement("UPDATE dre_search_change SET revision = 'r2' WHERE item_id = 5");
            }
        });

        self::assertSame([], $applier->drain(new ChangeQueue($this->db), 'records_g1'));
        self::assertSame(2, $edits, 'The newer revision stayed queued and was applied in its own batch.');
        self::assertSame([], $this->queued());
    }

    public function testACancelledDrainLeavesTheQueue(): void
    {
        $this->queueFor('records', [5]);
        $beats = 0;
        $this->applier()->drain(new ChangeQueue($this->db), 'records_g1', static fn(): bool => true, function () use (&$beats): void {
            $beats++;
        });
        self::assertSame([['records', 5]], $this->queued());
        self::assertSame(['1', '2', '3'], $this->server->ids('records_g1'));
        self::assertSame(0, $beats);
    }

    private function applier(?\Closure $invalidate = null): ChangeApplier
    {
        $logger = new Logger();
        $logger->addWriter($this->log);
        return new ChangeApplier(
            $this->db,
            new CollectionOps($this->server->client()),
            $this->profile(),
            $logger,
            null,
            $invalidate ?? function (): void {
                $this->invalidated++;
            },
        );
    }

    /** @return list<array{0:string,1:int}> */
    private function queued(): array
    {
        return array_values(array_map(
            static fn(array $row): array => [(string) $row['profile'], (int) $row['item_id']],
            $this->db->fetchAllAssociative('SELECT profile, item_id FROM dre_search_change ORDER BY profile, item_id'),
        ));
    }
}
