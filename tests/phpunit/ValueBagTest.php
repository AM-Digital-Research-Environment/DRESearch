<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\ValueBag;
use PHPUnit\Framework\TestCase;

final class ValueBagTest extends TestCase
{
    public function testDeduplicatesLabelsAndKeepsPeopleIdsParallel(): void
    {
        $bag = new ValueBag(['p' => [
            ['vrid' => 7, 'value' => null, 'uri' => null, 'title' => 'Jane Doe'],
            ['vrid' => 8, 'value' => null, 'uri' => null, 'title' => 'Jane Doe'],
            ['vrid' => null, 'value' => 'John Smith', 'uri' => null, 'title' => null],
        ]]);

        self::assertSame(['Jane Doe', 'John Smith'], $bag->labels('p'));
        self::assertSame([['Jane Doe', 'Jane Doe', 'John Smith'], ['7', '8', '']], $bag->people('p'));
    }

    public function testAllowsOnlyHttpLinksAndNormalizesDoi(): void
    {
        $bag = new ValueBag([
            'url' => [['vrid' => null, 'value' => 'javascript:alert(1)', 'uri' => null, 'title' => null]],
            'bibo:doi' => [['vrid' => null, 'value' => 'doi:10.1234/example', 'uri' => null, 'title' => null]],
        ]);

        self::assertNull($bag->firstUrl('url'));
        self::assertSame('https://doi.org/10.1234/example', $bag->firstDoi());
    }

    public function testParsesFiniteCoordinatesOnly(): void
    {
        $bag = new ValueBag([
            'geo:lat' => [['vrid' => null, 'value' => '12.345', 'uri' => null, 'title' => null]],
            'bad' => [['vrid' => null, 'value' => 'NaN', 'uri' => null, 'title' => null]],
        ]);
        self::assertSame(12.345, $bag->firstFloat('geo:lat'));
        self::assertNull($bag->firstFloat('bad'));
    }

    public function testIntegersOutsideInt32AreAbsentNotClamped(): void
    {
        $int = static fn(string ...$raw): ?int => (new ValueBag(['n' => array_values(array_map(
            static fn(string $value): array => ['vrid' => null, 'value' => $value, 'uri' => null, 'title' => null],
            $raw,
        ))]))->firstInt('n');
        self::assertSame(2147483647, $int('2147483647'));
        self::assertNull($int('2147483648'));
        self::assertSame(42, $int('00000000000000000042'), 'Leading zeros do not count towards the limit.');
        self::assertNull($int('99999999999999999999'), 'Never converted through a float.');
        self::assertSame(0, $int('0'));
        self::assertSame(3, $int('n/a', '3'));
        self::assertNull($int());
    }
}
