<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\CorpusCounts;
use DRESearch\Settings\ProfileRegistry;
use PDO;
use PHPUnit\Framework\TestCase;

final class CorpusCountsTest extends TestCase
{
    private function counts(bool $propertyExists = true): CorpusCounts
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE resource (id INTEGER, resource_type TEXT,'
            . ' is_public INTEGER, resource_template_id INTEGER)');
        $db->exec('CREATE TABLE item_item_set (item_id INTEGER, item_set_id INTEGER)');
        $db->exec('CREATE TABLE value (resource_id INTEGER, property_id INTEGER)');
        $db->exec('CREATE TABLE item_site (item_id INTEGER, site_id INTEGER)');
        $db->exec('CREATE TABLE site (id INTEGER, is_public INTEGER)');
        $db->exec('CREATE TABLE property (id INTEGER, vocabulary_id INTEGER, local_name TEXT)');
        $db->exec('CREATE TABLE vocabulary (id INTEGER, prefix TEXT)');
        $db->exec("INSERT INTO vocabulary VALUES (1, 'geo')");
        if ($propertyExists) {
            $db->exec("INSERT INTO property VALUES (5, 1, 'coordinates')");
        }
        // 1 overlaps both sources; 2 lacks coordinates; 3 is private; 4 is on another site.
        $insert = $db->prepare('INSERT INTO resource VALUES (?, ?, ?, ?)');
        foreach ([[1, 1, 10], [2, 1, 20], [3, 0, 10], [4, 1, 10], [5, 1, 20]] as [$id, $public, $template]) {
            $insert->execute([$id, 'Omeka\\Entity\\Item', $public, $template]);
        }
        $db->exec('INSERT INTO item_item_set VALUES (1, 7), (2, 7), (5, 7)');
        $db->exec('INSERT INTO value VALUES (1, 5), (5, 5), (5, 5)');
        $db->exec('INSERT INTO site VALUES (1, 1), (2, 1), (3, 0)');
        $db->exec('INSERT INTO item_site VALUES (1, 1), (2, 1), (3, 1), (4, 2), (5, 1), (1, 3)');
        $profiles = ProfileRegistry::fromArray(['research_locations' => [
            'label' => 'Locations',
            'collection' => 'locations',
            'kind' => 'item',
            'template_id' => 10,
            'query_by' => 'title',
            'date' => ['mode' => 'none'],
            'extra_sources' => [['item_set_id' => 7, 'require_property' => 'geo:coordinates']],
        ]]);
        return new CorpusCounts(static function (string $sql, array $params) use ($db) {
            $statement = $db->prepare($sql);
            $statement->execute($params);
            return $statement->fetchColumn();
        }, $profiles);
    }

    public function testSiteCountsExcludePrivateAndCrossSiteRecordsAndDeduplicateSources(): void
    {
        self::assertSame(2, $this->counts()->forSite(1)[0]['n']);
        self::assertSame(3, $this->counts()->forSite()[0]['n']);
        self::assertSame(0, $this->counts()->forSite(3)[0]['n']);
    }

    public function testMissingRequiredPropertySkipsOnlyTheExtraSource(): void
    {
        self::assertSame(1, $this->counts(false)->forSite(1)[0]['n']);
    }

    public function testInvalidSiteCannotBecomeAnUnscopedQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->counts()->forSite(0);
    }
}
