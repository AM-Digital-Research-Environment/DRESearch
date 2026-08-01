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
    }
}
