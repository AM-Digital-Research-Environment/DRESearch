<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\FacetRecount;
use DRESearch\Search\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class FacetRecountTest extends TestCase
{
    use ProfileFixture;

    public function testTheMainSearchComesFirstThenOneRecountPerRefinedField(): void
    {
        $builder = new QueryBuilder($this->profile());
        $req = ['q' => 'river', 'filters' => ['type_s' => ['Book']]];
        $searches = FacetRecount::searches($builder, $builder->search($req), $req, ['type_s'], 'records_current');

        self::assertCount(2, $searches);
        self::assertSame('records_current', $searches[0]['collection']);
        self::assertStringContainsString('type_s:=[`Book`]', $searches[0]['filter_by']);
        self::assertSame('type_s', $searches[1]['facet_by']);
        self::assertStringNotContainsString('type_s:=', $searches[1]['filter_by']);
    }

    public function testRecountedCountsReplaceTheNarrowedOnes(): void
    {
        $results = [
            ['hits' => [], 'found' => 3, 'facet_counts' => [
                ['field_name' => 'type_s', 'counts' => [['value' => 'Book', 'count' => 3]]],
                ['field_name' => 'language_ss', 'counts' => [['value' => 'French', 'count' => 3]]],
            ]],
            ['facet_counts' => [['field_name' => 'type_s', 'counts' => [
                ['value' => 'Book', 'count' => 3], ['value' => 'Map', 'count' => 9],
            ]]]],
        ];
        $merged = FacetRecount::merge($results, ['filters' => ['type_s' => ['Book']]], ['type_s']);

        self::assertNotNull($merged);
        self::assertSame(
            [['value' => 'Book', 'count' => 3], ['value' => 'Map', 'count' => 9]],
            $merged['facet_counts'][0]['counts'],
        );
        self::assertSame([['value' => 'French', 'count' => 3]], $merged['facet_counts'][1]['counts']);
    }

    public function testASelectedValueMissingFromTheRecountStaysListedWithAnUnknownCount(): void
    {
        $results = [
            ['hits' => [], 'facet_counts' => []],
            ['facet_counts' => [['field_name' => 'type_s', 'counts' => [['value' => 'Map', 'count' => 2]]]]],
        ];
        $merged = FacetRecount::merge($results, ['filters' => ['type_s' => ['Rare']]], ['type_s']);

        // Appended: the main search returned no entry for the field at all.
        self::assertSame(
            [['field_name' => 'type_s', 'counts' => [['value' => 'Map', 'count' => 2], ['value' => 'Rare', 'count' => null]]]],
            $merged['facet_counts'] ?? null,
        );
    }

    public function testAFailedMainEntryFallsBackAndAFailedRecountKeepsTheNarrowedCounts(): void
    {
        self::assertNull(FacetRecount::merge([['error' => 'Could not find the stopword set.']], [], ['type_s']));
        self::assertNull(FacetRecount::merge([], [], ['type_s']));

        $narrowed = [['field_name' => 'type_s', 'counts' => [['value' => 'Book', 'count' => 3]]]];
        $merged = FacetRecount::merge([['hits' => [], 'facet_counts' => $narrowed], ['error' => 'timeout']], ['filters' => ['type_s' => ['Book']]], ['type_s']);
        self::assertSame($narrowed, $merged['facet_counts'] ?? null);
    }

    public function testCountsOfReadsOneFieldOrNothing(): void
    {
        $result = ['facet_counts' => [['field_name' => 'type_s', 'counts' => [['value' => 'Book', 'count' => 1]]]]];
        self::assertSame([['value' => 'Book', 'count' => 1]], FacetRecount::countsOf($result, 'type_s'));
        self::assertNull(FacetRecount::countsOf($result, 'language_ss'));
        self::assertNull(FacetRecount::countsOf(['error' => 'x'], 'type_s'));
        self::assertNull(FacetRecount::countsOf(null, 'type_s'));
    }
}
