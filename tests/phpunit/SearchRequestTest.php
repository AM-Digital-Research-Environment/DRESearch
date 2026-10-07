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

    public function testUnionRequestIsBoundedAndRejectsExtraScope(): void
    {
        self::assertSame(
            ['q' => 'archive', 'page' => 250, 'per_page' => 50],
            SearchRequest::union(['q' => ' archive ', 'page' => 250, 'per_page' => 50]),
        );
        $this->expectException(RequestValidationException::class);
        SearchRequest::union(['q' => 'archive', 'filters' => ['type_s' => ['Book']]]);
    }

    public function testUnionRequestRejectsOutOfRangePagination(): void
    {
        $this->expectException(RequestValidationException::class);
        SearchRequest::union(['page' => 251]);
    }

    /** @return iterable<string,array{string}> */
    public static function reinterpretedValues(): iterable
    {
        yield 'prefix wildcard' => ['Hist*'];
        yield 'escaped closing backtick' => ['Book\\'];
        yield 'phrase quotes' => ['"Oral history"'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reinterpretedValues')]
    public function testFilterValuesTypesenseWouldReinterpretAreRefused(string $value): void
    {
        $this->expectException(RequestValidationException::class);
        SearchRequest::fromArray(['filters' => ['type_s' => [$value]]], $this->profile());
    }

    public function testQuotesInsideAValueAreStillLiteral(): void
    {
        $req = SearchRequest::fromArray(['filters' => ['type_s' => ['The "Big" Book']]], $this->profile())->toArray();
        self::assertSame(['The "Big" Book'], $req['filters']['type_s']);
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $this->expectException(RequestValidationException::class);
        SearchRequest::fromArray(['q' => "\xff\xfe"], $this->profile());
    }
}
