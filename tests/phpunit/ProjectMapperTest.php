<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\ProjectMapper;
use DRESearch\Test\Support\MapperFixtures;
use PHPUnit\Framework\TestCase;

/** Research projects: linked-title facets, the people on them, a year range and a count. */
final class ProjectMapperTest extends TestCase
{
    use MapperFixtures;

    public function testProjectDocument(): void
    {
        $mapper = new ProjectMapper($this->configProfile('research_projects'));
        $doc = $mapper->map(
            $this->record(5, 'Mobile religion', ['item_count' => 3]),
            [
                'dcterms:abstract' => [$this->literal('  '), $this->literal(' On the move. ')],
                'frapo:isFundedBy' => [$this->link(30, 'DFG'), $this->literal('DFG'), $this->literal(' Volkswagen Foundation ')],
                'dcterms:isPartOf' => [$this->link(17, 'Knowledges')],
                'dcterms:creator' => [$this->link(12, 'Doe, Jane'), $this->literal('Roe, Rick')],
                'foaf:member' => [$this->link(13, 'Poe, Ann'), $this->link(12, 'Doe, Jane')],
                'dcterms:temporal' => [$this->literal('2020-01-01/2023-12-31')],
            ],
            '/files/medium/project.jpg',
        );

        self::assertSame([
            'id' => '5',
            'is_public' => true,
            'title' => 'Mobile religion',
            'abstract' => 'On the move.',
            'institution_ss' => ['DFG', 'Volkswagen Foundation'],
            'section_ss' => ['Knowledges'],
            // A PI without a person record keeps an empty id, so the lists stay parallel.
            'pi_ss' => ['Doe, Jane', 'Roe, Rick'],
            'pi_ids' => ['12', ''],
            'member_ss' => ['Poe, Ann', 'Doe, Jane'],
            'people_ss' => ['Doe, Jane', 'Roe, Rick', 'Poe, Ann'],
            'year_start' => 2020,
            'year_end' => 2023,
            'item_count' => 3,
            'has_items' => 'Yes',
            'thumbnail_url' => '/files/medium/project.jpg',
        ], $doc);
    }

    public function testAnOpenEndedUntitledProjectWithoutItems(): void
    {
        $doc = (new ProjectMapper($this->configProfile('research_projects')))->map(
            $this->record(6, ''),
            ['dcterms:temporal' => [$this->literal('2021')]],
            null,
        );
        self::assertSame('[Untitled #6]', $doc['title']);
        self::assertSame(2021, $doc['year_start']);
        self::assertSame(2021, $doc['year_end'], 'A start alone is a one-year range.');
        self::assertSame(0, $doc['item_count']);
        self::assertSame('No', $doc['has_items']);
        foreach (['abstract', 'institution_ss', 'pi_ss', 'pi_ids', 'member_ss', 'people_ss', 'thumbnail_url'] as $absent) {
            self::assertArrayNotHasKey($absent, $doc);
        }

        $undated = (new ProjectMapper($this->configProfile('research_projects')))->map($this->record(7, 'P'), [], null);
        self::assertArrayNotHasKey('year_start', $undated);
        self::assertArrayNotHasKey('year_end', $undated);
    }
}
