<?php
declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\SchemaProvider;
use PHPUnit\Framework\TestCase;

final class SchemaProviderTest extends TestCase
{
    use ProfileFixture;

    public function testUnionMarkersAndGeopointContractAreGenerated(): void
    {
        $profile = $this->profile(['display_fields' => [
            'geo' => ['property' => null, 'type' => 'geopoint', 'facet' => false, 'index' => true],
            'has_coords' => ['property' => null, 'type' => 'bool', 'facet' => true, 'index' => true],
        ]]);
        $schema = (new SchemaProvider())->collection('test', $profile);
        $fields = array_column($schema['fields'], null, 'name');
        self::assertFalse($fields['_profile']['index']);
        self::assertFalse($fields['_kind']['index']);
        self::assertSame('geopoint', $fields['geo']['type']);
        self::assertTrue($fields['has_coords']['facet']);
        // Typesense builds the geo index from the sort index and rejects the
        // whole collection when a geopoint declares `sort: false` — which is the
        // FieldDefinition default, so the schema has to force it on.
        self::assertTrue($fields['geo']['sort']);
        self::assertFalse($fields['has_coords']['sort']);
    }

    public function testGeopointArrayIsAlsoForcedSortable(): void
    {
        $profile = $this->profile(['display_fields' => [
            'tracks' => ['property' => null, 'type' => 'geopoint[]', 'facet' => false, 'index' => true],
        ]]);
        $fields = array_column(
            (new SchemaProvider())->collection('test', $profile)['fields'],
            null,
            'name',
        );
        self::assertTrue($fields['tracks']['sort']);
    }
}
