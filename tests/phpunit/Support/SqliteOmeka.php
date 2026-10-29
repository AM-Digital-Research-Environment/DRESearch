<?php

declare(strict_types=1);

namespace DRESearch\Test\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Omeka\Entity\Item;

/**
 * The handful of Omeka tables the indexer's source reads, in an in-memory
 * SQLite database: enough to run {@see \DRESearch\Indexer\OmekaSourceRepository}
 * and the queue without MySQL. Only the columns the module queries exist, so
 * this checks the module's logic, not its SQL against Omeka's real schema —
 * {@see \DRESearch\Test\OmekaSchema} and the integration suite do that.
 */
trait SqliteOmeka
{
    private Connection $db;
    /** @var array<string,int> term => property id */
    private array $terms = [];

    private function createSqliteOmeka(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (
            [
                'CREATE TABLE vocabulary (id INTEGER PRIMARY KEY, prefix TEXT)',
                'CREATE TABLE property (id INTEGER PRIMARY KEY, vocabulary_id INTEGER, local_name TEXT, label TEXT)',
                'CREATE TABLE resource_template (id INTEGER PRIMARY KEY, title_property_id INTEGER)',
                'CREATE TABLE resource_template_property (resource_template_id INTEGER, property_id INTEGER, alternate_label TEXT)',
                'CREATE TABLE resource (id INTEGER PRIMARY KEY, resource_type TEXT, resource_template_id INTEGER,'
                    . ' title TEXT, is_public INTEGER)',
                'CREATE TABLE item_item_set (item_id INTEGER, item_set_id INTEGER)',
                'CREATE TABLE value (id INTEGER PRIMARY KEY AUTOINCREMENT, resource_id INTEGER, property_id INTEGER,'
                    . ' value_resource_id INTEGER, value TEXT, uri TEXT, is_public INTEGER)',
                'CREATE TABLE media (id INTEGER PRIMARY KEY, item_id INTEGER, storage_id TEXT, has_thumbnails INTEGER,'
                    . ' position INTEGER)',
                'CREATE TABLE dre_search_change (profile TEXT, item_id INTEGER, revision TEXT, queued_at TEXT,'
                    . ' PRIMARY KEY (profile, item_id))',
            ] as $sql
        ) {
            $this->db->executeStatement($sql);
        }
    }

    /** A property id for "prefix:local", created on first use. */
    private function term(string $term): int
    {
        if (!isset($this->terms[$term])) {
            [$prefix, $local] = explode(':', $term, 2);
            $vocabulary = $this->db->fetchOne('SELECT id FROM vocabulary WHERE prefix = ?', [$prefix]);
            if ($vocabulary === false) {
                $this->db->insert('vocabulary', ['prefix' => $prefix]);
                $vocabulary = $this->db->lastInsertId();
            }
            $this->db->insert('property', ['vocabulary_id' => (int) $vocabulary, 'local_name' => $local, 'label' => $local]);
            $this->terms[$term] = (int) $this->db->lastInsertId();
        }
        return $this->terms[$term];
    }

    /** An item, optionally in a template and item sets; its title is also a public dcterms:title value. */
    private function item(int $id, string $title, ?int $template = null, array $sets = [], bool $public = true): void
    {
        $this->db->insert('resource', [
            'id' => $id, 'resource_type' => Item::class, 'resource_template_id' => $template,
            'title' => $title, 'is_public' => (int) $public,
        ]);
        if ($title !== '') {
            $this->literalValue($id, 'dcterms:title', $title);
        }
        foreach ($sets as $set) {
            $this->db->insert('item_item_set', ['item_id' => $id, 'item_set_id' => $set]);
        }
    }

    private function literalValue(int $resource, string $term, string $literal, bool $public = true): void
    {
        $this->db->insert('value', [
            'resource_id' => $resource, 'property_id' => $this->term($term), 'value' => $literal, 'is_public' => (int) $public,
        ]);
    }

    private function linkValue(int $resource, string $term, int $target, bool $public = true): void
    {
        $this->db->insert('value', [
            'resource_id' => $resource, 'property_id' => $this->term($term), 'value_resource_id' => $target, 'is_public' => (int) $public,
        ]);
    }

    private function uriValue(int $resource, string $term, string $uri, ?string $label = null): void
    {
        $this->db->insert('value', [
            'resource_id' => $resource, 'property_id' => $this->term($term), 'uri' => $uri, 'value' => $label, 'is_public' => 1,
        ]);
    }

    private function thumbnail(int $media, int $item, string $storage): void
    {
        $this->db->insert('resource', ['id' => $media, 'resource_type' => 'Omeka\Entity\Media', 'title' => '', 'is_public' => 1]);
        $this->db->insert('media', ['id' => $media, 'item_id' => $item, 'storage_id' => $storage, 'has_thumbnails' => 1, 'position' => 1]);
    }

    /** @param list<int> $ids */
    private function queueFor(string $profile, array $ids, string $revision = 'r1'): void
    {
        foreach ($ids as $id) {
            $this->db->insert('dre_search_change', [
                'profile' => $profile, 'item_id' => $id, 'revision' => $revision, 'queued_at' => '2026-10-29 00:00:00',
            ]);
        }
    }
}
