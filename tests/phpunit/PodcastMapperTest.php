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
}
