<?php
declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\PublicationMapper;
use DRESearch\Indexer\TermMapper;
use PHPUnit\Framework\TestCase;

final class ImprovementMapperTest extends TestCase
{
    use ProfileFixture;

    public function testPublicationFullTextIsSearchableAndFacetFlagged(): void
    {
        $profile = $this->profile([
            'kind' => 'publication',
            'query_by' => 'title,fulltext',
            'display_fields' => [
                'fulltext' => [
                    'property' => 'bibo:content',
                    'type' => 'string',
                    'facet' => false,
                    'index' => true,
                    'search_only' => true,
                ],
            ],
            'facets' => [
                'has_fulltext' => ['property' => null, 'label' => 'Full text available', 'array' => false, 'derived' => true],
            ],
        ]);
        $doc = (new PublicationMapper($profile))->map(
            ['id' => 7, 'is_public' => true, 'title' => 'Publication'],
            ['bibo:content' => [['vrid' => null, 'value' => 'Complete text', 'uri' => null, 'title' => null]]],
            null,
        );
        self::assertSame('Complete text', $doc['fulltext']);
        self::assertSame('Yes', $doc['has_fulltext']);
    }

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
        $base = ['id' => 8, 'is_public' => true, 'title' => 'Lagos'];
        $valid = $mapper->map($base, [
            'geo:lat' => [['vrid' => null, 'value' => '6.455', 'uri' => null, 'title' => null]],
            'geo:long' => [['vrid' => null, 'value' => '3.384', 'uri' => null, 'title' => null]],
        ], null);
        self::assertSame([6.455, 3.384], $valid['geo']);
        self::assertTrue($valid['has_coords']);

        $invalid = $mapper->map($base, [
            'geo:lat' => [['vrid' => null, 'value' => '100', 'uri' => null, 'title' => null]],
            'geo:long' => [['vrid' => null, 'value' => '3.384', 'uri' => null, 'title' => null]],
        ], null);
        self::assertArrayNotHasKey('geo', $invalid);
        self::assertFalse($invalid['has_coords']);
    }
}
