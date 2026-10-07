<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\FilterExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilterExpressionTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function safe(): iterable
    {
        yield 'single clause' => ['section_ss:=`Mobilities`'];
        yield 'grouped or' => ['(section_ss:=`A` || section_ss:=`B`) && year:>=2000'];
        yield 'parens inside a quoted value' => ['title:=`Notes (draft`'];
        yield 'is_public as quoted text' => ['tag_ss:=`is_public`'];
    }

    #[DataProvider('safe')]
    public function testStructurallySafeFragmentsPass(string $filter): void
    {
        self::assertNull(FilterExpression::problem($filter));
    }

    /** @return iterable<string,array{string}> */
    public static function unsafe(): iterable
    {
        // Typesense: && and || share one precedence level, left-associative,
        // so this closes the proxy's wrapper and escapes the visibility guard.
        yield 'escaping or-branch' => ['section_ss:=`A`) || (year:>0'];
        yield 'unopened close' => [')'];
        yield 'unclosed group' => ['(section_ss:=`A`'];
        yield 'unterminated quote' => ['section_ss:=`A'];
        yield 'visibility override' => ['is_public:=false'];
        yield 'too long' => [str_repeat('a', FilterExpression::MAX_LENGTH + 1)];
        yield 'invalid utf-8' => ["section_ss:=`\xff`"];
    }

    #[DataProvider('unsafe')]
    public function testUnsafeFragmentsAreRejected(string $filter): void
    {
        self::assertNotNull(FilterExpression::problem($filter));
    }
}
