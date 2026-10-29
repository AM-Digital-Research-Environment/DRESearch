<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\TermMapper;
use DRESearch\Test\Support\MapperFixtures;
use PHPUnit\Framework\TestCase;

/** Authority terms: the reverse-link entity document, plus a location's geopoint. */
final class TermMapperTest extends TestCase
{
    use MapperFixtures;
    use ProfileFixture;

    public function testLocationMapperEmitsOnlyValidGeopoints(): void
    {
        $profile = $this->profile([
            'kind' => 'term',
            'query_by' => 'title',
            'display_fields' => [
                'geo' => ['property' => null, 'type' => 'geopoint', 'facet' => false, 'index' => true],
                'has_coords' => ['property' => null, 'type' => 'bool', 'facet' => true, 'index' => true],
            ],
        ]);
        $mapper = new TermMapper($profile);
        $base = $this->record(8, 'Lagos');
        $valid = $mapper->map($base, [
            'geo:lat' => [$this->literal('6.455')],
            'geo:long' => [$this->literal('3.384')],
        ], null);
        self::assertSame([6.455, 3.384], $valid['geo']);
        self::assertTrue($valid['has_coords']);

        $invalid = $mapper->map($base, [
            'geo:lat' => [$this->literal('100')],
            'geo:long' => [$this->literal('3.384')],
        ], null);
        self::assertArrayNotHasKey('geo', $invalid);
        self::assertFalse($invalid['has_coords']);

        $missing = $mapper->map($base, ['geo:lat' => [$this->literal('6.455')]], null);
        self::assertArrayNotHasKey('geo', $missing);
        self::assertFalse($missing['has_coords']);
    }

    public function testLocationDocument(): void
    {
        $mapper = new TermMapper($this->configProfile('research_locations'));
        $doc = $mapper->map(
            $this->record(77, 'Lagos', ['counts' => ['item_count' => 4], 'roles' => ['Place of origin', 'Current location']]),
            [
                'dcterms:type' => [$this->link(3170, 'Geographic location')],
                'geo:lat' => [$this->literal('6.455')],
                'geo:long' => [$this->literal('3.384')],
            ],
            null,
        );
        self::assertSame([
            'id' => '77',
            'is_public' => true,
            'title' => 'Lagos',
            'type_s' => 'Geographic location',
            'roles_ss' => ['Place of origin', 'Current location'],
            'item_count' => 4,
            'has_coords' => true,
            'geo' => [6.455, 3.384],
        ], $doc);
    }

    public function testTermsWithoutAGeopointFieldCarryNoCoordinates(): void
    {
        $doc = (new TermMapper($this->configProfile('research_languages')))->map(
            $this->record(5, 'Swahili', ['counts' => ['publication_count' => 2]]),
            ['geo:lat' => [$this->literal('6.455')], 'geo:long' => [$this->literal('3.384')]],
            null,
        );
        self::assertSame(['id' => '5', 'is_public' => true, 'title' => 'Swahili', 'item_count' => 0, 'publication_count' => 2], $doc);
    }
}
