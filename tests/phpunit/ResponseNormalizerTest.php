<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\QueryBuilder;
use DRESearch\Search\ResponseNormalizer;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseNormalizerTest extends TestCase
{
    use ProfileFixture;

    private const S = QueryBuilder::HL_START;
    private const E = QueryBuilder::HL_END;

    public function testASearchResponseKeepsItsPublicShape(): void
    {
        $normalized = ResponseNormalizer::search([
            'found' => 2, 'page' => 3, 'search_time_ms' => 4,
            'hits' => [
                ['document' => ['id' => '1', 'title' => 'River'], 'highlight' => ['title' => ['value' => self::S . 'River' . self::E]]],
                ['document' => ['id' => '2', 'title' => 'Lake'], 'highlight' => ['title' => ['snippet' => 'Lake']]],
            ],
            'facet_counts' => [
                ['field_name' => 'type_s', 'counts' => [['value' => 'Book', 'count' => 2], ['value' => 'Rare', 'count' => null]]],
                ['field_name' => '', 'counts' => []],
            ],
        ], $this->profile());

        self::assertSame([
            'available' => true,
            'found' => 2,
            'page' => 3,
            'hits' => [
                ['id' => '1', 'title' => 'River', '_highlights' => ['title' => [self::S . 'River' . self::E]]],
                // Nothing marked: no _highlights key at all.
                ['id' => '2', 'title' => 'Lake'],
            ],
            'facets' => [[
                'field' => 'type_s',
                'label' => 'Type',
                'counts' => [['value' => 'Book', 'count' => 2], ['value' => 'Rare', 'count' => null]],
            ]],
            'search_time_ms' => 4,
        ], $normalized);
    }

    public function testListFieldHighlightsKeepOnlyTheMarkedElements(): void
    {
        $highlights = ResponseNormalizer::highlights([
            'creator_ss' => [
                ['matched_tokens' => [], 'snippet' => 'Smith, Ann'],
                ['matched_tokens' => ['Watkins'], 'snippet' => 'x', 'value' => self::S . 'Watkins' . self::E . ', Lee'],
            ],
            'abstract' => ['snippet' => '… the ' . self::S . 'river' . self::E . ' …'],
            'subject_ss' => ['snippets' => ['Lakes', self::S . 'Rivers' . self::E]],
        ]);

        self::assertSame([
            'creator_ss' => [self::S . 'Watkins' . self::E . ', Lee'],
            'abstract' => ['… the ' . self::S . 'river' . self::E . ' …'],
            'subject_ss' => [self::S . 'Rivers' . self::E],
        ], $highlights);
    }

    /** @return iterable<string,array{string,array<string,mixed>,?string}> */
    public static function subtitles(): iterable
    {
        yield 'item: type and year' => ['research_items', ['type_s' => 'Photograph', 'year' => 1972], 'Photograph · 1972'];
        yield 'project: section and year range' => ['research_projects', ['section_ss' => ['Mobilities'], 'year_start' => 2019, 'year_end' => 2023], 'Mobilities · 2019–2023'];
        yield 'project: one year' => ['research_projects', ['section_ss' => [], 'year_start' => 2020, 'year_end' => 2020], '2020'];
        yield 'publication: author' => ['research_publications', ['author_ss' => ['Ndlovu, T.'], 'editor_ss' => ['E'], 'year' => 2021], 'Ndlovu, T. · 2021'];
        yield 'publication: edited volume' => ['research_publications', ['author_ss' => [], 'editor_ss' => ['Ed, A.'], 'type_s' => 'Book'], 'Ed, A.'];
        yield 'publication: neither' => ['research_publications', ['type_s' => 'Report', 'year' => 2001], 'Report · 2001'];
        yield 'podcast: series and year' => ['research_podcasts', ['series_s' => 'Cluster Conversations', 'year' => 2024], 'Cluster Conversations · 2024'];
        yield 'video: playlist and year' => ['research_videos', ['playlist_s' => 'Cinema Africa', 'year' => 2025], 'Cinema Africa · 2025'];
        yield 'person: affiliation and role' => ['research_people', ['affiliation_ss' => ['Bayreuth'], 'roles_ss' => ['Author', 'Editor']], 'Bayreuth · Author'];
        yield 'section: phase and spokesperson' => ['research_sections', ['phase_s' => 'Phase 2', 'pi_ss' => [], 'spokesperson_ss' => ['Doe, J.']], 'Phase 2 · Doe, J.'];
        yield 'organisation: type and role' => ['research_organisations', ['type_s' => 'Institution', 'roles_ss' => ['Funder']], 'Institution · Funder'];
        yield 'term: sub-type' => ['research_subjects', ['type_s' => 'Tag', 'item_count' => 4], 'Tag'];
        yield 'term without a sub-type' => ['research_genres', ['item_count' => 4], null];
    }

    /**
     * The per-kind subtitles the proxy's former switch built, now from profile
     * configuration ({@see SearchProfile::subtitleFields()}).
     *
     * @param array<string,mixed> $doc
     */
    #[DataProvider('subtitles')]
    public function testShippedProfilesKeepTheirSuggestionSubtitles(string $profile, array $doc, ?string $expected): void
    {
        $profile = $this->registry()->get($profile);
        self::assertNotNull($profile);
        self::assertSame($expected, ResponseNormalizer::subtitle($profile, $doc));
        // The suggestion request asks for exactly what the subtitle reads.
        $include = explode(',', (new QueryBuilder($profile))->suggest('x')['include_fields']);
        foreach (array_keys($doc) as $field) {
            if ($field !== 'item_count') {
                self::assertContains($field, $include);
            }
        }
    }

    public function testAProfileCanConfigureItsSubtitle(): void
    {
        $profile = $this->profile([
            'suggest_subtitle' => ['creator_ss|type_s', 'year_range'],
            'date' => ['mode' => 'range', 'property' => 'dcterms:temporal'],
        ]);
        self::assertSame([['creator_ss', 'type_s'], ['year_range']], $profile->subtitleFields());
        self::assertSame('Book · 1990–1999', ResponseNormalizer::subtitle($profile, ['type_s' => 'Book', 'year_start' => 1990, 'year_end' => 1999]));
        self::assertSame(['creator_ss', 'type_s', 'year_start', 'year_end'], $profile->subtitleDocumentFields());
    }

    public function testASubtitleNamingAFieldTheCorpusLacksIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('suggest_subtitle field "author_ss"');
        $this->profile(['suggest_subtitle' => ['author_ss']]);
    }

    public function testSuggestionRowsCarryIdTitleAndSubtitle(): void
    {
        $rows = ResponseNormalizer::suggestions(['hits' => [
            ['document' => ['id' => '9', 'title' => 'Rivers', 'type_s' => 'Map']],
        ]], $this->profile());
        self::assertSame([['id' => '9', 'title' => 'Rivers', 'subtitle' => 'Map']], $rows);
    }

    public function testYearBoundsSpanEveryStatField(): void
    {
        $range = $this->profile(['date' => ['mode' => 'range', 'property' => 'dcterms:temporal', 'facet' => true]]);
        $bounds = ResponseNormalizer::yearBounds(['facet_counts' => [
            ['field_name' => 'year_start', 'stats' => ['min' => 1950, 'max' => 2010]],
            ['field_name' => 'year_end', 'stats' => ['min' => 1960, 'max' => 2024]],
            ['field_name' => 'type_s', 'stats' => ['min' => 1, 'max' => 99999]],
        ]], $range);
        self::assertSame(['min' => 1950, 'max' => 2024], $bounds);
        self::assertNull(ResponseNormalizer::yearBounds(['facet_counts' => []], $range));
    }

    private function registry(): ProfileRegistry
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        return ProfileRegistry::fromArray($config['dre_search']['profiles']);
    }
}
