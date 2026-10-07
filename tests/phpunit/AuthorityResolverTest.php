<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\AuthorityResolver;
use DRESearch\Settings\SearchProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** What load() and an incremental batch's prime() resolve, on Omeka's real schema. */
#[Group('omeka')]
final class AuthorityResolverTest extends TestCase
{
    use OmekaSchema;

    private const TITLE = 1;
    private const TYPE = 2;
    private const PART_OF = 3;

    private SearchProfile $profile;
    private int $valueId = 0;

    protected function setUp(): void
    {
        if (!self::omekaAvailable()) {
            self::markTestSkipped('Requires Omeka core and disposable MySQL; see CONTRIBUTING.md.');
        }
        $this->createOmekaDatabase();
        $this->profile = $this->researchProfile('research_items');
        $this->property(self::TITLE, 'dcterms:title');
        $this->property(self::TYPE, 'dcterms:type');
        $this->property(self::PART_OF, 'dcterms:isPartOf');
        // Locations (set 1851): a city in a region in a country, the country
        // typed by the configured "country" type item. Plus a project (set 20).
        $this->item(3168, 'Country');
        $this->authority(40, 'Kisumu', 1851);
        $this->authority(41, 'Nyanza', 1851);
        $this->authority(42, 'Kenya', 1851);
        $this->link(40, self::PART_OF, 41);
        $this->link(41, self::PART_OF, 42);
        $this->link(42, self::TYPE, 3168);
        $this->authority(50, 'A project', 20);
    }

    protected function tearDown(): void
    {
        $this->dropOmekaDatabase();
    }

    public function testPrimeResolvesTheRequestedIdsAndTheirWholeIsPartOfChain(): void
    {
        $auth = new AuthorityResolver($this->db, $this->profile);
        $auth->prime([40]);
        self::assertSame(3, $auth->count(), 'The city, its region and its country; nothing else.');
        self::assertSame(41, $auth->partOfId(40));
        self::assertSame(42, $auth->partOfId(41));
        self::assertSame('Kenya', $auth->title(42));
        self::assertSame(3168, $auth->typeItemId(42));
        self::assertTrue($auth->inSet(40, 1851));
        self::assertFalse($auth->inSet(40, 20));
        self::assertFalse($auth->inSet(40, null));
        self::assertNull($auth->title(50), 'Unreferenced authorities are not loaded.');
    }

    public function testPrimeSkipsPrivateUntrackedAndPrivatelyLinkedAuthorities(): void
    {
        $this->authority(60, 'Private place', 1851, false);
        $this->authority(61, 'Untracked', 30000);
        $this->authority(62, 'Under a private parent', 1851);
        $this->authority(63, 'Private parent', 1851, false);
        $this->link(62, self::PART_OF, 63);
        $this->authority(64, 'Privately typed', 1851);
        $this->link(64, self::TYPE, 3168, false);
        $this->authority(65, 'Secret title', 1851);
        $this->db->executeStatement('UPDATE value SET is_public = 0 WHERE resource_id = 65');

        $auth = new AuthorityResolver($this->db, $this->profile);
        $auth->prime([60, 61, 62, 64, 65]);
        self::assertFalse($auth->inSet(60, 1851));
        self::assertNull($auth->title(60));
        self::assertNull($auth->title(61));
        self::assertSame('Under a private parent', $auth->title(62));
        self::assertNull($auth->partOfId(62));
        self::assertNull($auth->title(63), 'A private parent is never primed.');
        self::assertNull($auth->typeItemId(64));
        self::assertTrue($auth->inSet(65, 1851));
        self::assertNull($auth->title(65), 'The cached title came from a private value.');
    }

    public function testPrimeReadsEachIdOnceAndIsANoOpAfterAFullLoad(): void
    {
        $auth = new AuthorityResolver($this->db, $this->profile);
        $auth->prime([50, 50, 0, -1]);
        self::assertSame('A project', $auth->title(50));
        $this->db->executeStatement("UPDATE value SET value = 'Renamed' WHERE resource_id = 50");
        $auth->prime([50]);
        self::assertSame('A project', $auth->title(50), 'A primed id is not queried again.');

        $fresh = new AuthorityResolver($this->db, $this->profile);
        $fresh->load();
        self::assertSame(4, $fresh->count(), 'Every public member of a tracked set.');
        self::assertSame('Renamed', $fresh->title(50));
        self::assertSame('Kenya', $fresh->title((int) $fresh->partOfId(41)));
        $this->authority(51, 'Created after the load', 20);
        $fresh->prime([51]);
        self::assertNull($fresh->title(51), 'A full load is already complete.');
    }

    public function testCorpusWithoutAuthoritySetsResolvesNothing(): void
    {
        $auth = new AuthorityResolver($this->db, $this->researchProfile('research_sections'));
        $auth->prime([40, 50]);
        $auth->load();
        self::assertSame(0, $auth->count());
        self::assertNull($auth->title(40));
    }

    private function researchProfile(string $name): SearchProfile
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        return SearchProfile::fromArray($name, $config['dre_search']['profiles'][$name]);
    }

    /** An item in a set, titled by a dcterms:title value as Omeka writes it. */
    private function authority(int $id, string $title, int $set, bool $public = true): void
    {
        $this->item($id, $title, $public);
        $this->member($id, $set);
        $this->value(++$this->valueId, $id, self::TITLE, null, $title);
    }

    private function link(int $source, int $property, int $target, bool $public = true): void
    {
        $this->value(++$this->valueId, $source, $property, $target, null, $public);
    }
}
