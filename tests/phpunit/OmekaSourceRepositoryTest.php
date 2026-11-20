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

    public function testAProfileThatTypesByClassReadsEachRowsClass(): void
    {
        $this->item(1, 'An essay', 10, [7]);
        $this->classify(1, 'fabio:Essay');
        $this->item(2, 'Unclassed', 10, [7]);
        $this->item(3, 'A photograph', 10, [7]);
        $this->classify(3, 'fabio:StillImage');

        $typed = new OmekaSourceRepository($this->db, $this->profile([
            'item_set_id' => 7,
            'class_types' => ['fabio:Essay' => 'Essay'],
        ]));
        self::assertSame(
            ['fabio:Essay', null, 'fabio:StillImage'],
            array_column($typed->rows(after: 0), 'resource_class'),
            'Every class is read, listed or not: the mapper decides which ones it labels.',
        );

        $untyped = new OmekaSourceRepository($this->db, $this->profile(['item_set_id' => 7]));
        self::assertArrayNotHasKey('resource_class', $untyped->rows(after: 0)[0], 'Other profiles read no class.');
    }

    public function testPendingIdsAreEveryQueuedIdOfTheProfile(): void
    {
        $this->queueFor('records', [8, 3]);
        $this->queueFor('other', [5]);
        self::assertSame([3, 8], (new ChangeQueue($this->db))->pendingIds('records'));
        self::assertSame([], (new ChangeQueue($this->db))->pendingIds('unused'));
    }

    /**
     * A template whose title property changed re-titles no item: Omeka's
     * cached title still holds the first value of the former property,
     * whatever its visibility. It is kept only when no private value of the
     * resource carries that text.
     */
    public function testAStaleCachedTitleFromAPrivateValueIsNeverIndexed(): void
    {
        $this->db->insert('resource_template', ['id' => 10, 'title_property_id' => $this->term('dcterms:alternative')]);
        // Titled by a private dcterms:title before the template switched to
        // dcterms:alternative, of which these items have none.
        $this->item(1, 'Secret former title', 10, [7]);
        $this->db->executeStatement('UPDATE value SET is_public = 0 WHERE resource_id = 1');
        $this->item(2, 'Public former title', 10, [7]);
        $this->item(3, 'Cached', 10, [7]);
        $this->literalValue(3, 'dcterms:alternative', 'Current public title');

        $titles = (new OmekaSourceRepository($this->db, $this->profile(['item_set_id' => 7])))->publicTitles([1, 2, 3]);
        self::assertSame('', $titles[1]);
        self::assertSame('Public former title', $titles[2], 'A public former title is not a secret.');
        self::assertSame('Current public title', $titles[3]);
    }
}
