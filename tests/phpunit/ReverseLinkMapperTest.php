<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\ReverseLinkMapper;
use DRESearch\Test\Support\MapperFixtures;
use PHPUnit\Framework\TestCase;

/** People and organisations: a name, a facet or two, and what refers to them. */
final class ReverseLinkMapperTest extends TestCase
{
    use MapperFixtures;

    public function testPersonDocument(): void
    {
        $mapper = new ReverseLinkMapper($this->configProfile('research_people'));
        $doc = $mapper->map(
            $this->record(12, 'Doe, Jane', [
                'counts' => ['item_count' => 3],
                'roles' => ['Principal investigator', 'Author', 'Author'],
            ]),
            ['dcterms:isPartOf' => [
                $this->link(30, 'University of Bayreuth'),
                $this->link(31, 'University of Bayreuth'),
                $this->literal('Moi University'),
            ]],
            '/files/medium/portrait.jpg',
        );

        self::assertSame([
            'id' => '12',
            'is_public' => true,
            'title' => 'Doe, Jane',
            'affiliation_ss' => ['University of Bayreuth', 'Moi University'],
            'roles_ss' => ['Principal investigator', 'Author'],
            'item_count' => 3,
            'publication_count' => 0,
            'thumbnail_url' => '/files/medium/portrait.jpg',
        ], $doc, 'Every count is emitted, zero included, so the card and the sort have it.');
    }

    public function testOrganisationTypeIsSingleValuedAndNamelessRecordsAreLabelled(): void
    {
        $mapper = new ReverseLinkMapper($this->configProfile('research_organisations'));
        $doc = $mapper->map(
            $this->record(40, '', ['counts' => ['project_count' => 2, 'people_count' => 5], 'roles' => []]),
            ['dcterms:type' => [$this->literal('Institution'), $this->literal('Group')]],
            null,
        );

        self::assertSame('[Unnamed #40]', $doc['title']);
        self::assertSame('Institution', $doc['type_s'], 'The first type, not a list.');
        self::assertSame(2, $doc['project_count']);
        self::assertSame(0, $doc['item_count']);
        self::assertSame(5, $doc['people_count']);
        self::assertArrayNotHasKey('roles_ss', $doc, 'No role is better than an empty facet value.');
        self::assertArrayNotHasKey('thumbnail_url', $doc);
    }

    public function testLabelsAreTrimmedAndWhitespaceOnlyValuesAreNotIndexed(): void
    {
        $people = new ReverseLinkMapper($this->configProfile('research_people'));
        $doc = $people->map(
            $this->record(12, 'Doe, Jane'),
            ['dcterms:isPartOf' => [$this->literal(' Moi University  '), $this->literal('Moi University'), $this->literal('   ')]],
            null,
        );
        self::assertSame(['Moi University'], $doc['affiliation_ss']);

        $blank = $people->map($this->record(13, 'Roe, Rick'), ['dcterms:isPartOf' => [$this->literal("\t")]], null);
        self::assertArrayNotHasKey('affiliation_ss', $blank);

        $organisations = new ReverseLinkMapper($this->configProfile('research_organisations'));
        $typed = $organisations->map($this->record(40, 'Band'), ['dcterms:type' => [$this->literal(' '), $this->literal('Group ')]], null);
        self::assertSame('Group', $typed['type_s'], 'A blank first value no longer becomes the type.');
    }
}
