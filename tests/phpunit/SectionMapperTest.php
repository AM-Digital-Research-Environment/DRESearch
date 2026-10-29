<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\SectionMapper;
use DRESearch\Test\Support\MapperFixtures;
use PHPUnit\Framework\TestCase;

/** Research sections: a phase derived from who leads them. */
final class SectionMapperTest extends TestCase
{
    use MapperFixtures;

    public function testSectionPhaseFollowsItsLeadershipProperty(): void
    {
        $mapper = new SectionMapper($this->configProfile('research_sections'));
        $item = ['id' => 3, 'is_public' => true, 'title' => 'Section', 'item_count' => 4];
        $member = ['foaf:member' => [$this->link(9, 'Member'), $this->link(10, 'Lead')]];

        $phase1 = $mapper->map($item, ['dcterms:creator' => [$this->link(10, 'Lead')]] + $member, null);
        self::assertSame('Phase 1', $phase1['phase_s']);
        self::assertSame(['Lead'], $phase1['pi_ss']);
        self::assertSame(['Lead', 'Member'], $phase1['people_ss']);
        self::assertSame(2, $phase1['member_count']);
        self::assertSame(4, $phase1['project_count']);

        $phase2 = $mapper->map($item, ['marcrel:spk' => [$this->link(11, 'Speaker')]] + $member, null);
        self::assertSame('Phase 2', $phase2['phase_s']);
        self::assertSame(['Speaker'], $phase2['spokesperson_ss']);

        $both = $mapper->map($item, ['dcterms:creator' => [$this->link(10, 'Lead')], 'marcrel:spk' => [$this->link(11, 'Speaker')]], null);
        self::assertSame('Phase 1', $both['phase_s'], 'PIs mark a Phase 1 section.');

        $external = $mapper->map(['id' => 4, 'is_public' => true, 'title' => 'External'], $member, null);
        self::assertArrayNotHasKey('phase_s', $external);
        self::assertSame(0, $external['project_count']);
    }
}
