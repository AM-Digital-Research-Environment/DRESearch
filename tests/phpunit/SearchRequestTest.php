<?php
declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\Exception\RequestValidationException;
use DRESearch\Search\SearchRequest;
use PHPUnit\Framework\TestCase;

final class SearchRequestTest extends TestCase
{
    use ProfileFixture;

    public function testNormalizesYearsAndFilters(): void
    {
        $request = SearchRequest::fromArray([
            'q' => ' archive ',
            'filters' => ['type_s' => ['Book', 'Book']],
            'year_from' => 2024,
            'year_to' => 1990,
        ], $this->profile())->toArray();

        self::assertSame('archive', $request['q']);
        self::assertSame(['type_s' => ['Book']], $request['filters']);
        self::assertSame(1990, $request['year_from']);
        self::assertSame(2024, $request['year_to']);
    }

    public function testRejectsUnknownParameters(): void
    {
        $this->expectException(RequestValidationException::class);
        SearchRequest::fromArray(['locked_filter' => 'is_public:=false'], $this->profile());
    }

    public function testRejectsUnconfiguredFilterFields(): void
    {
        $this->expectException(RequestValidationException::class);
        SearchRequest::fromArray(['filters' => ['private_field' => ['x']]], $this->profile());
    }

    public function testRejectsNonBooleanCountSwitch(): void
    {
        $this->expectException(RequestValidationException::class);
        SearchRequest::fromArray(['include_counts' => 'false'], $this->profile());
    }
}
