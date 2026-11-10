<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\PodcastMapper;
use DRESearch\Test\Support\MapperFixtures;
use PHPUnit\Framework\TestCase;

/** Podcast episodes: numbers the schema can store. */
final class PodcastMapperTest extends TestCase
{
    use MapperFixtures;

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

    /**
     * A field's property comes from the profile alone: a profile that does not
     * declare the engineer credit has no such field in its schema, so the
     * mapper does not fall back to marcrel:sde and emit one.
     */
    public function testAFieldTheProfileDoesNotDeclareIsNotEmitted(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $definition = $config['dre_search']['profiles']['research_podcasts'];
        unset($definition['display_fields']['engineer_ss'], $definition['display_fields']['engineer_ids']);
        $mapper = new PodcastMapper(\DRESearch\Settings\SearchProfile::fromArray('research_podcasts', $definition));

        $doc = $mapper->map(
            ['id' => 5, 'is_public' => true, 'title' => 'Episode'],
            [
                'marcrel:sde' => [$this->link(9, 'Engineer')],
                'marcrel:hst' => [$this->link(7, 'Host'), $this->literal('Guest host')],
            ],
            null,
        );
        self::assertArrayNotHasKey('engineer_ss', $doc);
        self::assertSame(['Host', 'Guest host'], $doc['host_ss']);
        self::assertSame(['7', ''], $doc['host_ids'], 'An unlinked name keeps its place with an empty id.');
        self::assertSame(['Host', 'Guest host'], $doc['people_ss']);
    }
}
