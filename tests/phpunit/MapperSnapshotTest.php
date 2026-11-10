<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Doctrine\DBAL\DriverManager;
use DRESearch\Indexer\AuthorityResolver;
use DRESearch\Indexer\MapperInterface;
use DRESearch\Indexer\MapperRegistry;
use DRESearch\Indexer\PropertyResolver;
use DRESearch\Indexer\ResearchItemMapper;
use DRESearch\Settings\SearchProfile;
use DRESearch\Test\Support\MapperFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every shipped profile's mapper, run on the same generated records and
 * compared with the documents in snapshots/mapper-documents.json.
 *
 * The documents are the indexer's output contract: a refactoring of the
 * mappers must leave them byte-for-byte equal (key order aside, which
 * Typesense ignores). The inputs are built from each profile's own
 * read_properties, the terms the indexer actually loads, so the fixture grows
 * with the configuration instead of being retyped per mapper. Every term gets
 * linked, literal and URI values; terms the mappers parse (dates, numbers,
 * coordinates, DOIs, page ranges) lead with a value of their shape.
 *
 * After a deliberate change to the documents, regenerate with
 * DRE_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter MapperSnapshotTest and
 * review the diff: every changed line is a document a reindex will change.
 */
final class MapperSnapshotTest extends TestCase
{
    use MapperFixtures;

    private const SNAPSHOT = __DIR__ . '/snapshots/mapper-documents.json';

    /** Leading literals for the terms a mapper parses rather than labels. */
    private const SHAPED = [
        'dcterms:date'      => ['2019-03-04', '2020'],
        'dcterms:issued'    => ['ca. 1950', '1951-02-03'],
        'dcterms:created'   => ['1890s'],
        'dcterms:temporal'  => ['2019-2023'],
        'bibo:number'       => ['Episode 0012'],
        'geo:lat'           => ['12.5'],
        'geo:long'          => ['-1.25'],
        'bibo:doi'          => ['https://doi.org/10.1234/ABC.5'],
        'bibo:pageStart'    => ['10'],
        'bibo:pageEnd'      => ['20'],
        'bibo:numPages'     => ['300'],
        'bibo:volume'       => ['7'],
        'bibo:issue'        => ['2'],
    ];

    private string $timezone;

    protected function setUp(): void
    {
        // Omeka's bootstrap runs every request in UTC; the origin date's epoch depends on it.
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    /** @return array<string,array{string}> */
    public static function profiles(): array
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $cases = [];
        foreach (array_keys($config['dre_search']['profiles']) as $name) {
            $cases[(string) $name] = [(string) $name];
        }
        return $cases;
    }

    #[DataProvider('profiles')]
    public function testTheMapperKeepsItsDocuments(string $name): void
    {
        $profile = $this->configProfile($name);
        $actual = $this->documents($profile);

        $snapshots = is_file(self::SNAPSHOT) ? json_decode((string) file_get_contents(self::SNAPSHOT), true, 512, JSON_THROW_ON_ERROR) : [];
        if (getenv('DRE_UPDATE_SNAPSHOTS') === '1') {
            $snapshots[$name] = $actual;
            ksort($snapshots);
            file_put_contents(self::SNAPSHOT, json_encode($snapshots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n");
        }
        self::assertIsArray($snapshots);
        self::assertArrayHasKey($name, $snapshots, 'No snapshot for this profile: regenerate with DRE_UPDATE_SNAPSHOTS=1.');
        // Through JSON both ways, so an int that became a float (or a string) fails.
        self::assertSame(
            json_encode($this->sorted($snapshots[$name]), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($this->sorted($actual), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string,array<string,mixed>> the documents of a full record and an empty one */
    private function documents(SearchProfile $profile): array
    {
        $values = $this->values($profile);
        $mapper = $this->mapper($profile, $values);
        $counts = [];
        $n = 0;
        foreach ($profile->displayFields() as $field => $def) {
            if (($def['type'] ?? '') === 'int32') {
                $counts[$field] = ++$n * 3;
            }
        }
        return [
            'full' => $mapper->map(
                $this->record(101, 'A full record', ['item_count' => 4, 'counts' => $counts, 'roles' => ['Author', 'Host', 'Author', 'Member']]),
                $values,
                'https://example.org/thumb/101.jpg',
            ),
            'empty' => $mapper->map(['id' => 102, 'is_public' => false, 'title' => ''], [], null),
        ];
    }

    /**
     * Values for every term the indexer loads for this profile.
     *
     * @return array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>>
     */
    private function values(SearchProfile $profile): array
    {
        $prefix = $profile->fulltextSource()['url_prefix'] ?? null;
        $values = [];
        foreach ($profile->readProperties() as $term) {
            $id = 1000 + crc32($term) % 9000;
            $slug = (string) preg_replace('/[^a-z0-9]+/i', '-', $term);
            $rows = array_map($this->literal(...), self::SHAPED[$term] ?? []);
            array_push(
                $rows,
                $this->link($id, $term . ' A'),
                $this->link($id + 1, '  ' . $term . ' B  '),
                $this->literal($term . ' literal'),
                $this->uri('https://example.org/' . $slug, $term . ' uri'),
                $this->link($id, $term . ' A'),
                $this->literal('   '),
            );
            if ($prefix !== null) {
                $rows[] = $this->uri($prefix . $slug);
            }
            $values[$term] = $rows;
        }
        return $values;
    }

    /** @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values */
    private function mapper(SearchProfile $profile, array $values): MapperInterface
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        if ($profile->kind() !== 'item') {
            return MapperRegistry::default()->create($profile, $connection, true, new PropertyResolver($connection));
        }
        // Research items consult their authorities: seed them directly (the
        // resolver is never primed, so it never queries the empty database).
        // Each linked id gets a deterministic mix of project / digital set
        // membership, tag / country type and a parent place.
        $auth = new AuthorityResolver($connection, $profile);
        $byId = [999 => ['title' => 'Parent country', 'sets' => [], 'typeItemId' => null, 'partOfId' => null]];
        foreach ($values as $rows) {
            foreach ($rows as $row) {
                $id = $row['vrid'];
                if ($id === null) {
                    continue;
                }
                $sets = [];
                if ($id % 2 === 0 && $profile->itemSet('project') !== null) {
                    $sets[$profile->itemSet('project')] = true;
                }
                if ($id % 3 === 0 && $profile->itemSet('digital') !== null) {
                    $sets[$profile->itemSet('digital')] = true;
                }
                $byId[$id] = [
                    'title' => 'Authority ' . $id,
                    'sets' => $sets,
                    'typeItemId' => $id % 4 === 0 ? $profile->typeItem('tag') : ($id % 5 === 0 ? $profile->typeItem('country') : null),
                    'partOfId' => $id % 2 === 1 ? 999 : null,
                ];
            }
        }
        (new \ReflectionProperty(AuthorityResolver::class, 'byId'))->setValue($auth, $byId);
        return new ResearchItemMapper($auth, $profile);
    }

    /** Associative arrays by key, recursively; lists keep their order. */
    private function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map($this->sorted(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }
}
