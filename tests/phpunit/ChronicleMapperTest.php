<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\ChronicleMapper;
use DRESearch\Test\Support\MapperFixtures;
use PHPUnit\Framework\TestCase;

/** Cluster Chronicles: the type from the class, people by role, the full text. */
final class ChronicleMapperTest extends TestCase
{
    use MapperFixtures;

    public function testChronicleDocument(): void
    {
        $mapper = new ChronicleMapper($this->configProfile('research_chronicles'));
        $doc = $mapper->map(
            $this->record(50120, 'Kakamega Meets Bayreuth', ['resource_class' => 'fabio:Essay']),
            [
                'dcterms:alternative' => [$this->literal('Kakamega rencontre Bayreuth')],
                'dcterms:abstract' => [$this->literal('A residency as a literary encounter.')],
                'marcrel:aut' => [$this->link(50103, 'Kabaji, Egara'), $this->link(50103, 'Kabaji, Egara')],
                'marcrel:trl' => [$this->literal('Roe, Rick')],
                'marcrel:pht' => [$this->link(14, 'Doe, Jane')],
                'foaf:depicts' => [$this->link(15, 'Moe, Mary'), $this->link(50103, 'Kabaji, Egara')],
                'dcterms:subject' => [$this->link(30247, 'narratives'), $this->literal('Creative writing')],
                'dcterms:spatial' => [$this->link(1896, 'Kenya'), $this->literal('Kakamega')],
                'dcterms:language' => [$this->link(1858, 'English')],
                'bibo:number' => [$this->literal('3')],
                'dcterms:date' => [$this->literal('2023-05')],
                'fabio:hasURL' => [$this->uri('https://www.africamultiple.uni-bayreuth.de/en/news/chronicle-3/', 'Read')],
                'bibo:content' => [$this->literal('The invitation arrived. / L’invitation est arrivée.')],
            ],
            '/files/medium/photo.jpg',
        );

        self::assertSame([
            'id' => '50120',
            'is_public' => true,
            'title' => 'Kakamega Meets Bayreuth',
            'abstract' => 'A residency as a literary encounter.',
            'subject_ss' => ['narratives', 'Creative writing'],
            'place_ss' => ['Kenya', 'Kakamega'],
            'language_ss' => ['English'],
            'type_s' => 'Essay',
            'alternative_s' => 'Kakamega rencontre Bayreuth',
            'author_ss' => ['Kabaji, Egara'],
            'author_ids' => ['50103'],
            'photographer_ss' => ['Doe, Jane'],
            'photographer_ids' => ['14'],
            'translator_ss' => ['Roe, Rick'],
            'translator_ids' => [''],
            'depicted_ss' => ['Moe, Mary', 'Kabaji, Egara'],
            'depicted_ids' => ['15', '50103'],
            'people_ss' => ['Kabaji, Egara', 'Doe, Jane', 'Roe, Rick', 'Moe, Mary'],
            'number' => 3,
            'url_s' => 'https://www.africamultiple.uni-bayreuth.de/en/news/chronicle-3/',
            'date_s' => '2023-05',
            'year' => 2023,
            'content' => 'The invitation arrived. / L’invitation est arrivée.',
            'has_content' => true,
            'thumbnail_url' => '/files/medium/photo.jpg',
        ], $doc, 'Each role with parallel ids, one People facet without repeats, the class as the type.');
    }

    public function testAnUnlistedClassAndAMissingTextLeaveNoTrace(): void
    {
        $mapper = new ChronicleMapper($this->configProfile('research_chronicles'));
        $doc = $mapper->map(
            $this->record(50121, '', ['resource_class' => 'bibo:Document']),
            [
                'bibo:content' => [$this->literal('   ')],
                'fabio:hasURL' => [$this->literal('javascript:alert(1)')],
                'dcterms:date' => [$this->literal('2026')],
            ],
            null,
        );
        self::assertSame('[Untitled #50121]', $doc['title']);
        self::assertArrayNotHasKey('type_s', $doc, 'Only a class listed in class_types is a type.');
        self::assertFalse($doc['has_content'], 'Always present, so the card can rely on it.');
        self::assertArrayNotHasKey('content', $doc);
        self::assertArrayNotHasKey('url_s', $doc, 'Only an http(s) link is a link out.');
        self::assertArrayNotHasKey('people_ss', $doc);
        self::assertSame(2026, $doc['year']);

        $unclassed = $mapper->map($this->record(50122, 'No class'), [], null);
        self::assertArrayNotHasKey('type_s', $unclassed);
    }
}
