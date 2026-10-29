<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\ChangeQueue;
use DRESearch\Indexer\OmekaSourceRepository;
use DRESearch\Test\Support\SqliteOmeka;
use PHPUnit\Framework\TestCase;

/** The source count a rebuild reconciles its staging collection against. */
final class OmekaSourceRepositoryTest extends TestCase
{
    use ProfileFixture;
    use SqliteOmeka;

    protected function setUp(): void
    {
        $this->createSqliteOmeka();
    }

    public function testTheCountIsWhatTheScanReads(): void
    {
        $profile = $this->profile([
            'item_set_id' => 7,
            'extra_sources' => [['template_id' => 2, 'require_property' => 'geo:lat']],
        ]);
        $this->item(1, 'In scope', 10, [7]);
        $this->item(2, 'Private', 10, [7], false);
        $this->item(3, 'Other set', 10, [8]);
        $this->item(4, 'Other template', 11, [7]);
        $this->item(5, 'Geocoded institution', 2);
        $this->literalValue(5, 'geo:lat', '6.4');
        $this->item(6, 'Institution without coordinates', 2);
        $this->item(7, 'Second in scope', 10, [7]);
        $source = new OmekaSourceRepository($this->db, $profile);

        self::assertSame([1, 5, 7], array_map('intval', array_column($source->rows(after: 0), 'id')));
        self::assertSame(3, $source->count());
        self::assertSame(1, $source->count([5, 7, 7, 99]), 'Queued ids are left out; ids outside the scope change nothing.');
    }

    public function testPendingIdsAreEveryQueuedIdOfTheProfile(): void
    {
        $this->queueFor('records', [8, 3]);
        $this->queueFor('other', [5]);
        self::assertSame([3, 8], (new ChangeQueue($this->db))->pendingIds('records'));
        self::assertSame([], (new ChangeQueue($this->db))->pendingIds('unused'));
    }
}
