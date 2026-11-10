<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use PHPUnit\Framework\TestCase;

final class SearchProfileTest extends TestCase
{
    use ProfileFixture;

    public function testUnknownExplicitProfileDoesNotFallBack(): void
    {
        $profile = $this->profile();
        $registry = new ProfileRegistry(['records' => $profile]);
        self::assertNull($registry->get('typo'));
        self::assertSame($profile, $registry->get(''));
    }

    public function testRejectsQueryFieldThatIsNotIndexed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profile(['query_by' => 'title,missing']);
    }

    public function testRejectsDuplicateCollectionAliases(): void
    {
        $config = [
            'label' => 'Records',
            'collection' => 'same_current',
            'kind' => 'item',
            'template_id' => 10,
            'query_by' => 'title',
            'date' => ['mode' => 'none'],
        ];
        $this->expectException(\InvalidArgumentException::class);
        ProfileRegistry::fromArray(['one' => $config, 'two' => $config]);
    }

    public function testShippedProfileConfigurationPassesTheStrictSchema(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $registry = ProfileRegistry::fromArray($config['dre_search']['profiles']);

        self::assertCount(12, $registry->all());
        self::assertSame('research_items', $registry->default()?->name());
    }

    /**
     * The shipped corpora keep the views the client used to decide from their
     * names: the gallery on research items, the map on locations, the list
     * everywhere.
     */
    public function testTheShippedCorporaKeepTheirViews(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $registry = ProfileRegistry::fromArray($config['dre_search']['profiles']);
        $views = [];
        foreach ($registry->all() as $profile) {
            $views[$profile->name()] = $profile->views();
        }
        $expected = array_fill_keys(array_keys($views), ['list']);
        $expected['research_items'] = ['list', 'gallery'];
        $expected['research_locations'] = ['list', 'map'];
        self::assertSame($expected, $views);
        self::assertTrue($registry->get('research_locations')?->supportsMap());
        self::assertFalse($registry->get('research_items')?->supportsMap());
    }

    /** The map follows the coordinate fields, whatever the corpus is called. */
    public function testAnyProfileWithCoordinatesOffersTheMap(): void
    {
        $profile = $this->profile([
            'kind' => 'term',
            'display_fields' => [
                'geo'        => ['property' => null, 'type' => 'geopoint', 'facet' => false],
                'has_coords' => ['property' => null, 'type' => 'bool', 'facet' => true],
            ],
        ]);
        self::assertSame('records', $profile->name());
        self::assertSame(['list', 'map'], $profile->views());
    }
}
