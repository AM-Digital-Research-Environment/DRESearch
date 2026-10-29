<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\BulkPager;
use DRESearch\Search\Exception\IncompletePullException;
use PHPUnit\Framework\TestCase;

final class BulkPagerTest extends TestCase
{
    public function testPagesUntilEveryMatchIsInHand(): void
    {
        $asked = [];
        $pull = (new BulkPager(10, 2))->pull(function (int $page) use (&$asked): array {
            $asked[] = $page;
            return self::page(5, array_values(array_filter([$page * 2 - 1, $page * 2], static fn(int $id): bool => $id <= 5)));
        });

        self::assertSame([1, 2, 3], $asked, 'Stops once all five are in hand.');
        self::assertSame(5, $pull['found']);
        self::assertSame(['1', '2', '3', '4', '5'], array_column($pull['docs'], 'id'));
    }

    public function testADocumentThatMovesAcrossAPageBoundaryIsReturnedOnce(): void
    {
        // A drain re-ranked between pages: id 2 shows up on both.
        $pages = [1 => self::page(4, [1, 2]), 2 => self::page(4, [2, 3]), 3 => self::page(4, [4])];
        $pull = (new BulkPager(10, 2))->pull(static fn(int $page): array => $pages[$page]);
        self::assertSame(['1', '2', '3', '4'], array_column($pull['docs'], 'id'));
    }

    public function testStopsAtTheCap(): void
    {
        $pager = new BulkPager(3, 2);
        $pull = $pager->pull(static fn(int $page): array => self::page(100, [$page * 2 - 1, $page * 2]));
        self::assertCount(3, $pull['docs']);
        self::assertTrue($pager->capped($pull['found']));
        self::assertFalse($pager->capped(3));
    }

    public function testAShortPullFailsClosed(): void
    {
        try {
            (new BulkPager(10, 2))->pull(static fn(int $page): array => self::page(5, $page === 1 ? [1, 2] : []));
            self::fail('A pull missing three documents was accepted.');
        } catch (IncompletePullException $short) {
            self::assertSame([2, 5], [$short->received, $short->expected]);
        }
    }

    public function testAFailedRequestPropagates(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('down');
        (new BulkPager(10, 2))->pull(static fn(): array => throw new \RuntimeException('down'));
    }

    /**
     * @param list<int> $ids
     * @return array<string,mixed>
     */
    private static function page(int $found, array $ids): array
    {
        return ['found' => $found, 'hits' => array_map(
            static fn(int $id): array => ['document' => ['id' => (string) $id, 'title' => 'Doc ' . $id]],
            $ids,
        )];
    }
}
