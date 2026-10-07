<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Doctrine\DBAL\DriverManager;
use DRESearch\Indexer\AuthorityResolver;
use DRESearch\Indexer\PodcastMapper;
use DRESearch\Indexer\ResearchItemMapper;
use DRESearch\Indexer\SectionMapper;
use DRESearch\Settings\SearchProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Field derivations the mappers compute rather than copy. */
final class IndexerMapperTest extends TestCase
{
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

    public function testSectionPhaseFollowsItsLeadershipProperty(): void
    {
        $mapper = new SectionMapper($this->configProfile('research_sections'));
        $item = ['id' => 3, 'is_public' => true, 'title' => 'Section', 'item_count' => 4];
        $member = ['foaf:member' => [$this->link(9, 'Member'), $this->link(10, 'Lead')]];

        $phase1 = $mapper->map($item, ['dcterms:creator' => [$this->link(10, 'Lead')]] + $member, null);
        self::assertSame('Phase 1', $phase1['phase_s']);
        self::assertSame(['Lead'], $phase1['pi_ss']);
        self::assertSame(['Lead', 'Member'], $phase1['people_ss']);
        self::assertSame(2, $phase1['member_count']);
        self::assertSame(4, $phase1['project_count']);

        $phase2 = $mapper->map($item, ['marcrel:spk' => [$this->link(11, 'Speaker')]] + $member, null);
        self::assertSame('Phase 2', $phase2['phase_s']);
        self::assertSame(['Speaker'], $phase2['spokesperson_ss']);

        $both = $mapper->map($item, ['dcterms:creator' => [$this->link(10, 'Lead')], 'marcrel:spk' => [$this->link(11, 'Speaker')]], null);
        self::assertSame('Phase 1', $both['phase_s'], 'PIs mark a Phase 1 section.');

        $external = $mapper->map(['id' => 4, 'is_public' => true, 'title' => 'External'], $member, null);
        self::assertArrayNotHasKey('phase_s', $external);
        self::assertSame(0, $external['project_count']);
    }

    public function testPodcastEpisodeNumberStaysWithinInt32(): void
    {
        $mapper = new PodcastMapper($this->configProfile('research_podcasts'));
        $episode = fn(string ...$raw): array => $mapper->map(
            ['id' => 5, 'is_public' => true, 'title' => 'Episode'],
            ['bibo:number' => array_values(array_map($this->literal(...), $raw))],
            null,
        );
        self::assertSame(12, $episode('Episode 0012')['episode']);
        self::assertSame(2147483647, $episode('2147483647')['episode']);
        self::assertArrayNotHasKey('episode', $episode('2147483648'), 'Typesense would reject the whole document.');
        self::assertSame(7, $episode('99999999999999999999', '7')['episode'], 'An out-of-range value falls through to the next.');
        self::assertArrayNotHasKey('episode', $episode('no number'));
    }

    /** @param array<string,list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values */
    private function researchItem(array $values): array
    {
        $profile = $this->configProfile('research_items');
        // Date values link nothing, so the resolver is never queried.
        $auth = new AuthorityResolver(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $profile);
        return (new ResearchItemMapper($auth, $profile))->map(['id' => 1, 'is_public' => true, 'title' => 'Item'], $values, null);
    }

    private static function utc(string $time): int
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('UTC')))->getTimestamp();
    }

    private function configProfile(string $name): SearchProfile
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        return SearchProfile::fromArray($name, $config['dre_search']['profiles'][$name]);
    }

    /** @return array{vrid:?int,value:?string,uri:?string,title:?string} */
    private function literal(string $value): array
    {
        return ['vrid' => null, 'value' => $value, 'uri' => null, 'title' => null];
    }

    /** @return array{vrid:?int,value:?string,uri:?string,title:?string} */
    private function link(int $id, string $title): array
    {
        return ['vrid' => $id, 'value' => null, 'uri' => null, 'title' => $title];
    }
}
