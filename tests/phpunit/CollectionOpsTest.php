<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\CollectionOps;
use DRESearch\Indexer\Exception\BatchImportException;
use DRESearch\Test\Support\Typesense\FakeTypesense;
use PHPUnit\Framework\TestCase;

/** Promotion, cleanup and imports against an in-memory Typesense. */
final class CollectionOpsTest extends TestCase
{
    private FakeTypesense $server;
    private CollectionOps $ops;

    protected function setUp(): void
    {
        $this->server = new FakeTypesense();
        $this->ops = new CollectionOps($this->server->client());
    }

    public function testPromotionPointsTheAliasAtTheNewGeneration(): void
    {
        self::assertNull($this->ops->aliasTarget('records_current'), 'A corpus never built has no alias.');
        $this->ops->promote('records_current', 'records_g1');
        $this->ops->promote('records_current', 'records_g2');
        self::assertSame('records_g2', $this->ops->aliasTarget('records_current'));
    }

    public function testALostResponseToAnAppliedPromotionCountsAsDone(): void
    {
        $this->server->aliases['records_current'] = 'records_g1';
        $this->server->aliasWriteFailure = new \RuntimeException('Operation timed out');
        $this->server->aliasWriteApplied = true;
        $this->ops->promote('records_current', 'records_g2');
        self::assertSame('records_g2', $this->server->aliases['records_current'], 'Read back, the alias is at the new target.');
    }

    public function testAFailedPromotionKeepsTheLiveAliasAndItsError(): void
    {
        $this->server->aliases['records_current'] = 'records_g1';
        $error = new \RuntimeException('Connection refused');
        $this->server->aliasWriteFailure = $error;
        try {
            $this->ops->promote('records_current', 'records_g2');
            self::fail('An unapplied alias write passed.');
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertSame('records_g1', $this->server->aliases['records_current']);

        // When even the read-back fails, nothing proves the write: the original error stands.
        $this->server->aliasWriteApplied = true;
        $this->server->aliasReadFailure = new \RuntimeException('Still unreachable');
        try {
            $this->ops->promote('records_current', 'records_g3');
            self::fail('An unverified alias write passed.');
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
    }

    public function testCleanupNeverDeletesACollectionAnyAliasHolds(): void
    {
        $this->server->seed('records_g1', [1]);
        $this->server->seed('records_g2', [1]);
        $this->server->seed('shared_g1', [1]);
        $this->server->aliases = ['records_current' => 'records_g2', 'other_current' => 'shared_g1'];

        self::assertFalse($this->ops->deleteUnaliased('records_g2'), 'The live generation is kept.');
        self::assertFalse($this->ops->deleteUnaliased('shared_g1'), 'So is one another alias points at.');
        self::assertTrue($this->ops->deleteUnaliased('records_g1'));
        self::assertTrue($this->ops->deleteUnaliased('records_g0'), 'A collection already gone is gone.');
        self::assertSame(['records_g1'], $this->server->dropped);

        $this->server->aliasListFailure = new \RuntimeException('Unreachable');
        try {
            $this->ops->deleteUnaliased('records_g2');
            self::fail('A collection was deleted without checking the aliases.');
        } catch (\RuntimeException) {
            self::assertArrayHasKey('records_g2', $this->server->collections);
        }
    }

    public function testImportFailsOnAnyRejectedDocumentAndNamesIt(): void
    {
        $this->ops->create(['name' => 'records_g1', 'fields' => []]);
        self::assertSame(2, $this->ops->import('records_g1', [['id' => '1'], ['id' => '2']]));

        $this->server->importDecision = static fn(array $doc): bool => $doc['id'] !== '4';
        try {
            $this->ops->import('records_g1', [['id' => '3'], ['id' => '4'], ['id' => '5']]);
            self::fail('A batch with a rejected document was reported complete.');
        } catch (BatchImportException $error) {
            self::assertSame(2, $error->successful());
            self::assertSame(['4'], $error->failedIds());
            self::assertSame(['Field `title` must be a string.'], $error->errors());
            self::assertSame('records_g1', $error->collection());
        }
        self::assertSame(4, $this->ops->documentCount('records_g1'), 'The accepted documents of the batch are stored.');
    }

    public function testDocumentsAreDeletedInBoundedIdFilters(): void
    {
        $this->server->seed('records_g1', range(1, 600));
        $this->ops->deleteDocuments('records_g1', array_merge(range(1, 600), [1, 2]));
        self::assertSame(0, $this->ops->documentCount('records_g1'));
        self::assertCount(3, $this->server->filterDeletes, '600 distinct ids: 250 + 250 + 100.');
        self::assertSame('id:=[501,' . implode(',', range(502, 600)) . ']', $this->server->filterDeletes[2][1]);
        $this->ops->deleteDocuments('records_g1', []);
        self::assertCount(3, $this->server->filterDeletes, 'Nothing to delete sends nothing.');
    }

    public function testDocumentCountOfAMissingCollectionThrows(): void
    {
        $this->expectException(\Typesense\Exceptions\ObjectNotFound::class);
        $this->ops->documentCount('records_g9');
    }
}
