<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\VideoMapper;
use DRESearch\Test\Support\MapperFixtures;
use PHPUnit\Framework\TestCase;

/** YouTube videos: playlist, speakers, the Watch link, a date and the transcript. */
final class VideoMapperTest extends TestCase
{
    use MapperFixtures;

    public function testVideoDocument(): void
    {
        $mapper = new VideoMapper($this->configProfile('research_videos'));
        $doc = $mapper->map(
            $this->record(9, 'Cinema Africa: opening night'),
            [
                'dcterms:abstract' => [$this->literal('A screening and a talk.')],
                'dcterms:isPartOf' => [$this->link(700, 'Cinema Africa 2024/25'), $this->link(701, 'Another playlist')],
                'marcrel:spk' => [$this->link(12, 'Doe, Jane'), $this->literal('Roe, Rick'), $this->link(12, 'Doe, Jane')],
                'dcterms:language' => [$this->link(80, 'English'), $this->literal('French ')],
                'fabio:hasURL' => [$this->uri('https://www.youtube.com/watch?v=abc', 'Watch on YouTube')],
                'dcterms:date' => [$this->literal('14 November 2024')],
                'bibo:content' => [$this->literal('Good evening and welcome.')],
            ],
            '/files/medium/poster.jpg',
        );

        self::assertSame([
            'id' => '9',
            'is_public' => true,
            'title' => 'Cinema Africa: opening night',
            'abstract' => 'A screening and a talk.',
            'playlist_s' => 'Cinema Africa 2024/25',
            'language_ss' => ['English', 'French'],
            'speaker_ss' => ['Doe, Jane', 'Roe, Rick'],
            'speaker_ids' => ['12', ''],
            'playlist_id' => '700',
            'url_s' => 'https://www.youtube.com/watch?v=abc',
            'date_s' => '14 November 2024',
            'year' => 2024,
            'transcript' => 'Good evening and welcome.',
            'has_transcript' => true,
            'thumbnail_url' => '/files/medium/poster.jpg',
        ], $doc, 'One playlist (the first), the speakers with parallel ids, the URI over its label.');
    }

    public function testAVideoWithoutTranscriptOrUsableLinks(): void
    {
        $doc = (new VideoMapper($this->configProfile('research_videos')))->map(
            $this->record(10, ''),
            [
                'fabio:hasURL' => [$this->literal('javascript:alert(1)')],
                'dcterms:date' => [$this->literal('undated')],
                'bibo:content' => [$this->literal('   ')],
                'dcterms:isPartOf' => [$this->literal('Unlinked playlist')],
            ],
            null,
        );
        self::assertSame('[Untitled #10]', $doc['title']);
        self::assertFalse($doc['has_transcript'], 'Always present, so the card can rely on it.');
        self::assertArrayNotHasKey('transcript', $doc);
        self::assertArrayNotHasKey('url_s', $doc, 'Only an http(s) link is a Watch link.');
        self::assertSame('undated', $doc['date_s']);
        self::assertArrayNotHasKey('year', $doc);
        self::assertSame('Unlinked playlist', $doc['playlist_s']);
        self::assertArrayNotHasKey('playlist_id', $doc, 'A literal playlist links nowhere.');
        self::assertArrayNotHasKey('speaker_ss', $doc);
    }
}
