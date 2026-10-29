<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Doctrine\DBAL\DriverManager;
use DRESearch\Indexer\AuthorityResolver;
use DRESearch\Indexer\ResearchItemMapper;
use DRESearch\Test\Support\MapperFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Research items: the origin date, and the labels a research item carries. */
final class ResearchItemMapperTest extends TestCase
{
    use MapperFixtures;

    private string $timezone;

    protected function setUp(): void
    {
        // Omeka's bootstrap runs every request in UTC.
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    /** @return array<string,array{string,int,int}> raw value, year, epoch */
    public static function originDates(): array
    {
        return [
            'full date' => ['1950-05-12', 1950, self::utc('1950-05-12')],
            'date and time' => ['1950-05-12T14:30:00Z', 1950, self::utc('1950-05-12 14:30')],
            'month' => ['May 1950', 1950, self::utc('1950-05-01')],
            // strtotime() alone reads these as a clock time today, or fills in
            // today's month and day.
            'bare year' => ['1950', 1950, self::utc('1950-01-01')],
            'current year' => [gmdate('Y'), (int) gmdate('Y'), self::utc(gmdate('Y') . '-01-01')],
            'decade' => ['1890s', 1890, self::utc('1890-01-01')],
            'range' => ['1950-1960', 1950, self::utc('1950-01-01')],
            'slashed range' => ['1999/2001', 1999, self::utc('1999-01-01')],
            'approximate' => ['ca. 1950', 1950, self::utc('1950-01-01')],
            'impossible day' => ['2024-02-30', 2024, self::utc('2024-01-01')],
        ];
    }

    #[DataProvider('originDates')]
    public function testResearchItemOriginDate(string $raw, int $year, int $epoch): void
    {
        $doc = $this->researchItem(['dcterms:issued' => [$this->literal($raw)]]);
        self::assertSame($year, $doc['year']);
        self::assertSame($epoch, $doc['date']);
    }

    public function testResearchItemDateFallsThroughUnusableValuesInPropertyOrder(): void
    {
        $doc = $this->researchItem([
            'dcterms:date' => [$this->literal('2001')],
            'dcterms:created' => [$this->literal('1975')],
            'dcterms:issued' => [$this->literal('undated'), $this->literal('')],
        ]);
        self::assertSame(1975, $doc['year'], 'Issued has no year; created comes before date.');

        $none = $this->researchItem(['dcterms:issued' => [$this->literal('n.d.')]]);
        self::assertArrayNotHasKey('year', $none);
        self::assertArrayNotHasKey('date', $none);
    }

    public function testResearchItemLiteralLabelsAreTrimmedAndCreatorsMergedOnce(): void
    {
        $doc = $this->researchItem([
            'dcterms:creator' => [$this->link(9, 'Doe, Jane'), $this->literal(' Roe, Rick ')],
            'marcrel:aut' => [$this->literal('Roe, Rick'), $this->literal('  ')],
            'dcterms:language' => [$this->literal(' Swahili'), $this->literal('Swahili')],
            'dcterms:spatial' => [$this->literal("\t")],
        ]);
        self::assertSame(['Doe, Jane', 'Roe, Rick'], $doc['creator_ss']);
        self::assertSame(['Swahili'], $doc['language_ss']);
        self::assertArrayNotHasKey('origin_ss', $doc, 'A whitespace-only place is no place.');
    }

    /** @param array<string,list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values */
    private function researchItem(array $values): array
    {
        $profile = $this->configProfile('research_items');
        // Nothing here needs an authority: the resolver is never primed, so it
        // never queries the (empty) database.
        $auth = new AuthorityResolver(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $profile);
        return (new ResearchItemMapper($auth, $profile))->map(['id' => 1, 'is_public' => true, 'title' => 'Item'], $values, null);
    }

    private static function utc(string $time): int
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
