<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DRESearch\Indexer\MapperInterface;
use DRESearch\Indexer\MapperRegistry;
use DRESearch\Indexer\OrganisationMapper;
use DRESearch\Indexer\PersonMapper;
use DRESearch\Indexer\PodcastMapper;
use DRESearch\Indexer\ProjectMapper;
use DRESearch\Indexer\PublicationMapper;
use DRESearch\Indexer\ResearchItemMapper;
use DRESearch\Indexer\SectionMapper;
use DRESearch\Indexer\TermMapper;
use DRESearch\Indexer\VideoMapper;
use DRESearch\Settings\SearchProfile;
use PHPUnit\Framework\TestCase;

/** The single kind → mapper map that the indexer and profile validation share. */
final class MapperRegistryTest extends TestCase
{
    use ProfileFixture;

    public function testEveryConfiguredProfileGetsTheMapperOfItsKind(): void
    {
        $expected = [
            'item' => ResearchItemMapper::class,
            'project' => ProjectMapper::class,
            'publication' => PublicationMapper::class,
            'podcast' => PodcastMapper::class,
            'video' => VideoMapper::class,
            'section' => SectionMapper::class,
            'person' => PersonMapper::class,
            'organisation' => OrganisationMapper::class,
            'term' => TermMapper::class,
        ];
        self::assertSame(array_keys($expected), MapperRegistry::default()->kinds());

        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $seen = [];
        foreach ($config['dre_search']['profiles'] as $name => $definition) {
            $profile = SearchProfile::fromArray($name, $definition);
            // Targeted, so the research-item mapper does not load authorities.
            $mapper = MapperRegistry::default()->create($profile, $this->connection(), true);
            self::assertInstanceOf($expected[$profile->kind()], $mapper, $name);
            $seen[$profile->kind()] = true;
        }
        self::assertSame([], array_diff(array_keys($expected), array_keys($seen)), 'Every kind is in use by a shipped profile.');
    }

    public function testOnlyAFullBuildLoadsEveryAuthorityUpFront(): void
    {
        $profile = $this->profile(['authority_item_sets' => ['location' => 7]]);
        // The database holds no Omeka tables: a targeted batch queries nothing
        // until it maps, while a full build reads the authorities at once.
        self::assertInstanceOf(ResearchItemMapper::class, MapperRegistry::default()->create($profile, $this->connection(), true));
        $this->expectException(\Doctrine\DBAL\Exception::class);
        MapperRegistry::default()->create($profile, $this->connection());
    }

    public function testProfileValidationReadsTheRegistry(): void
    {
        try {
            $this->profile(['kind' => 'gazetteer']);
            self::fail('An unregistered kind was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Unsupported mapper kind "gazetteer"', $error->getMessage());
            self::assertStringContainsString('item, project, publication', $error->getMessage());
        }
    }

    public function testAKindRegisteredTwiceFailsAtConstruction(): void
    {
        $factory = static fn(SearchProfile $profile): MapperInterface => new ProjectMapper($profile);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Mapper kind "project" is registered twice.');
        new MapperRegistry([['project', $factory], ['item', $factory], ['project', $factory]]);
    }

    public function testAnEmptyOrMalformedRegistryIsRefused(): void
    {
        try {
            new MapperRegistry([]);
            self::fail('An empty registry was accepted.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\LogicException::class);
        new MapperRegistry([['Not a kind', static fn(SearchProfile $profile): MapperInterface => new ProjectMapper($profile)]]);
    }

    public function testARegistryWithoutTheKindRefusesToGuess(): void
    {
        $registry = new MapperRegistry([['project', static fn(SearchProfile $profile): MapperInterface => new ProjectMapper($profile)]]);
        self::assertFalse($registry->has('item'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No mapper is registered for kind "item" (profile "records").');
        $registry->create($this->profile(), $this->connection());
    }

    private function connection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }
}
