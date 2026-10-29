<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\DocumentAssembler;
use DRESearch\Indexer\OmekaSourceRepository;
use DRESearch\Indexer\ProjectMapper;
use DRESearch\Indexer\ReverseLinkMapper;
use DRESearch\Test\Support\MapperFixtures;
use DRESearch\Test\Support\RecordingMapper;
use DRESearch\Test\Support\SqliteOmeka;
use PHPUnit\Framework\TestCase;

/** What a mapper is handed for a batch, and what the assembler adds to every document. */
final class DocumentAssemblerTest extends TestCase
{
    use MapperFixtures;
    use ProfileFixture;
    use SqliteOmeka;

    protected function setUp(): void
    {
        $this->createSqliteOmeka();
    }

    public function testAnEmptyBatchReadsNothing(): void
    {
        $mapper = new RecordingMapper();
        $this->db->executeStatement('DROP TABLE value');
        $assembler = new DocumentAssembler(new OmekaSourceRepository($this->db, $this->profile()), $mapper, $this->profile());
        self::assertSame([], $assembler->documents([]));
        self::assertSame([], $mapper->prepared);
    }

    public function testEachRowIsMappedWithItsOwnValuesOnceTheBatchIsPrepared(): void
    {
        $profile = $this->profile();
        $this->item(1, 'First', 10);
        $this->item(2, 'Second', 10);
        $this->literalValue(1, 'dcterms:creator', 'Doe, Jane');
        $this->literalValue(1, 'dcterms:creator', 'Secret, Sam', false);
        $this->linkValue(1, 'dcterms:type', 3);
        $this->item(3, 'Interview', 99);
        $this->thumbnail(50, 2, 'abc');
        $source = new OmekaSourceRepository($this->db, $profile);
        $mapper = new RecordingMapper();

        $docs = (new DocumentAssembler($source, $mapper, $profile))->documents($source->rows([1, 2]));

        self::assertCount(1, $mapper->prepared, 'The whole batch is prepared once, before any document is mapped.');
        self::assertSame([1], array_keys($mapper->prepared[0]), 'Only records with values appear.');
        self::assertSame([
            [['id' => 1, 'title' => 'First', 'is_public' => true], [
                'dcterms:creator' => [['vrid' => null, 'value' => 'Doe, Jane', 'uri' => null, 'title' => null]],
                'dcterms:type' => [['vrid' => 3, 'value' => null, 'uri' => null, 'title' => 'Interview']],
            ], null],
            [['id' => 2, 'title' => 'Second', 'is_public' => true], [], '/files/medium/abc.jpg'],
        ], $mapper->mapped, 'Neither a count nor roles are handed to a profile that configures none.');
        self::assertSame(['1', '2'], array_column($docs, 'id'));
        foreach ($docs as $doc) {
            self::assertSame('records', $doc['_profile']);
            self::assertSame('item', $doc['_kind']);
        }
    }

    public function testReverseLinksReachTheMapperAndIdentifiersAreLaidIn(): void
    {
        $profile = $this->configProfile('research_organisations');
        $this->item(40, 'University of Bayreuth', 2, [110]);
        $this->item(41, 'A choir', 2, [110]);
        $this->literalValue(40, 'dcterms:type', 'Institution');
        $this->uriValue(40, 'dcterms:identifier', 'http://www.wikidata.org/entity/Q317053', 'Q317053');
        $this->literalValue(40, 'dcterms:identifier', 'not-a-link');
        $this->item(50, 'Funded project', 5);
        $this->linkValue(50, 'frapo:isFundedBy', 40);
        $this->item(51, 'Private project', 5, [], false);
        $this->linkValue(51, 'frapo:isFundedBy', 40);
        $this->item(60, 'Recording', 10);
        $this->linkValue(60, 'marcrel:ctb', 41);
        $this->item(70, 'Doe, Jane', 4);
        $this->linkValue(70, 'dcterms:isPartOf', 40);
        $source = new OmekaSourceRepository($this->db, $profile);

        $docs = (new DocumentAssembler($source, new ReverseLinkMapper($profile), $profile))->documents($source->rows([40, 41]));

        self::assertSame([
            'id' => '40',
            'is_public' => true,
            'title' => 'University of Bayreuth',
            'type_s' => 'Institution',
            'roles_ss' => ['Funder', 'Host institution'],
            'project_count' => 1,
            'item_count' => 0,
            'people_count' => 1,
            'same_as_ss' => ['http://www.wikidata.org/entity/Q317053'],
            '_profile' => 'research_organisations',
            '_kind' => 'organisation',
        ], $docs[0], 'The private project is not counted; only links are identifiers.');
        self::assertSame(['Contributor'], $docs[1]['roles_ss']);
        self::assertSame(1, $docs[1]['item_count']);
        self::assertArrayNotHasKey('same_as_ss', $docs[1]);
    }

    public function testItemLinkCountsTheResearchItemsOfAProject(): void
    {
        $profile = $this->configProfile('research_projects');
        $this->item(5, 'A project', 5, [20]);
        foreach ([[100, true], [101, true], [102, false]] as [$id, $public]) {
            $this->item($id, 'Item ' . $id, 10, [], $public);
            $this->linkValue($id, 'dcterms:isPartOf', 5);
        }
        $this->item(6, 'Another project', 5, [20]);
        $source = new OmekaSourceRepository($this->db, $profile);

        $docs = (new DocumentAssembler($source, new ProjectMapper($profile), $profile))->documents($source->rows(after: 0));

        self::assertSame(['5', '6'], array_column($docs, 'id'));
        self::assertSame([2, 0], array_column($docs, 'item_count'));
        self::assertSame(['Yes', 'No'], array_column($docs, 'has_items'));
    }

    public function testAMapperThatSetsItsOwnIdentifiersKeepsThem(): void
    {
        $profile = $this->configProfile('research_people');
        $this->item(70, 'Doe, Jane', 4, [18]);
        $this->uriValue(70, 'dcterms:identifier', 'https://orcid.org/0000-0002-1825-0097');
        $source = new OmekaSourceRepository($this->db, $profile);
        $mapper = new RecordingMapper(['same_as_ss' => ['https://example.org/own']]);

        $docs = (new DocumentAssembler($source, $mapper, $profile))->documents($source->rows([70]));

        self::assertSame(['https://example.org/own'], $docs[0]['same_as_ss']);
        self::assertSame(['item_count' => 0, 'publication_count' => 0], $mapper->mapped[0][0]['counts'] ?? null, 'Every count bucket is handed in, zero included.');
        self::assertSame([], $mapper->mapped[0][0]['roles'] ?? null);
    }
}
