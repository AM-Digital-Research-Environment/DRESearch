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

    public function testFullTextSourceFlagsTheRepositoryRecordNotExtractedText(): void
    {
        $profile = $this->profile([
            'kind' => 'publication',
            'query_by' => 'title,fulltext',
            'display_fields' => [
                'fulltext' => ['property' => 'bibo:content', 'type' => 'string', 'index' => true, 'search_only' => true],
                'fulltext_url_s' => ['property' => null, 'type' => 'string', 'index' => false],
            ],
            'facets' => [
                'has_fulltext' => ['property' => null, 'label' => 'Full text available', 'array' => false, 'derived' => true],
            ],
            'fulltext_source' => ['property' => 'bibo:uri', 'url_prefix' => 'https://epub.uni-bayreuth.de/id/eprint/'],
        ]);
        self::assertContains('bibo:uri', $profile->readProperties());
        $uri = static fn (string $url): array => ['vrid' => null, 'value' => $url, 'uri' => $url, 'title' => null];
        $mapper = new PublicationMapper($profile);
        $base = ['id' => 29919, 'is_public' => true, 'title' => 'Publication'];

        // Cross-listed: the EPub permalink is found behind the ERef one.
        $epub = $mapper->map($base, ['bibo:uri' => [
            $uri('https://eref.uni-bayreuth.de/id/eprint/95983/'),
            $uri('https://epub.uni-bayreuth.de/id/eprint/9405/'),
            $uri('https://nbn-resolving.org/urn:nbn:de:bvb:703-epub-9405-4'),
        ]], null);
        self::assertSame('Yes', $epub['has_fulltext']);
        self::assertSame('https://epub.uni-bayreuth.de/id/eprint/9405/', $epub['fulltext_url_s']);
        self::assertArrayNotHasKey('fulltext', $epub);

        // ERef only: extracted text stays searchable but is not "available".
        $eref = $mapper->map($base, [
            'bibo:uri' => [$uri('https://eref.uni-bayreuth.de/id/eprint/95983/')],
            'bibo:content' => [['vrid' => null, 'value' => 'Complete text', 'uri' => null, 'title' => null]],
        ], null);
        self::assertSame('Complete text', $eref['fulltext']);
        self::assertArrayNotHasKey('has_fulltext', $eref);
        self::assertArrayNotHasKey('fulltext_url_s', $eref);
    }

    public function testFullTextSourceReadsTheLinkedIdentifierFirst(): void
    {
        $profile = $this->profile([
            'kind' => 'publication',
            'query_by' => 'title',
            'display_fields' => [
                'fulltext_url_s' => ['property' => null, 'type' => 'string', 'index' => false],
            ],
            'facets' => [
                'has_fulltext' => ['property' => null, 'label' => 'Full text available', 'array' => false, 'derived' => true],
            ],
            'fulltext_source' => [
                'property' => ['dcterms:identifier', 'bibo:uri'],
                'url_prefix' => 'https://epub.uni-bayreuth.de/id/eprint/',
            ],
        ]);
        self::assertContains('dcterms:identifier', $profile->readProperties());
        self::assertContains('bibo:uri', $profile->readProperties());
        $link = static fn (string $url, string $label): array => ['vrid' => null, 'value' => $label, 'uri' => $url, 'title' => null];
        $mapper = new PublicationMapper($profile);
        $base = ['id' => 30087, 'is_public' => true, 'title' => 'Publication'];

        // Since 27 October 2026 each source id labels its permalink link.
        $linked = $mapper->map($base, ['dcterms:identifier' => [
            $link('https://eref.uni-bayreuth.de/id/eprint/70514/', 'eref-70514'),
            $link('https://epub.uni-bayreuth.de/id/eprint/5720/', 'epub-5720'),
            $link('http://www.wikidata.org/entity/Q141722498', 'Q141722498'),
        ]], null);
        self::assertSame('https://epub.uni-bayreuth.de/id/eprint/5720/', $linked['fulltext_url_s']);

        // A record synced before still carries the permalink in bibo:uri.
        $older = $mapper->map($base, ['bibo:uri' => [
            $link('https://epub.uni-bayreuth.de/id/eprint/5720/', 'https://epub.uni-bayreuth.de/id/eprint/5720/'),
        ]], null);
        self::assertSame('Yes', $older['has_fulltext']);
    }

    public function testFullTextSourceNeedsAnHttpPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profile([
            'kind' => 'publication',
            'fulltext_source' => ['property' => 'bibo:uri', 'url_prefix' => 'epub'],
        ]);
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
