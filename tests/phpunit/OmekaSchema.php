<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Omeka\Entity\Item;
use Omeka\Entity\ItemSet;
use Omeka\Entity\Media;

/**
 * A disposable MySQL database built from Omeka's own install schema — the
 * `application/data/install/schema.sql` of the Omeka tree OMEKA_VENDOR points
 * into — with its foreign keys and cascades, plus helpers that write rows the
 * way Omeka's entities would. A core schema change the module's SQL does not
 * match fails here instead of in production.
 */
trait OmekaSchema
{
    private Connection $db;
    private Connection $admin;
    private string $database;
    /** @var array<string,string|int> */
    private array $dbParams;

    private static function omekaAvailable(): bool
    {
        $host = getenv('DRE_TEST_MYSQL_HOST');
        return is_string($host) && $host !== '' && class_exists(Item::class) && is_readable(self::omekaSchemaPath());
    }

    private static function omekaSchemaPath(): string
    {
        $core = getenv('OMEKA_VENDOR') ?: '/var/www/html/vendor/autoload.php';
        return dirname($core, 2) . '/application/data/install/schema.sql';
    }

    private function createOmekaDatabase(): void
    {
        $this->dbParams = [
            'driver' => 'pdo_mysql', 'host' => getenv('DRE_TEST_MYSQL_HOST') ?: '',
            'port' => (int) (getenv('DRE_TEST_MYSQL_PORT') ?: 3306),
            'user' => getenv('DRE_TEST_MYSQL_USER') ?: 'root',
            'password' => getenv('DRE_TEST_MYSQL_PASSWORD') ?: '', 'charset' => 'utf8mb4',
        ];
        $this->admin = DriverManager::getConnection($this->dbParams);
        $this->database = 'dre_test_' . bin2hex(random_bytes(6));
        $this->admin->executeStatement('CREATE DATABASE ' . $this->database);
        $this->db = DriverManager::getConnection($this->dbParams + ['dbname' => $this->database]);
        // Statement by statement, as Omeka's InstallSchemaTask runs it. The
        // file ends by switching foreign-key checks back on.
        foreach (explode(';', (string) file_get_contents(self::omekaSchemaPath())) as $statement) {
            if (trim($statement) !== '') {
                $this->db->executeStatement(trim($statement));
            }
        }
    }

    private function dropOmekaDatabase(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
        if (isset($this->admin, $this->database) && preg_match('/^dre_test_[a-f0-9]{12}$/D', $this->database)) {
            $this->admin->executeStatement('DROP DATABASE ' . $this->database);
            $this->admin->close();
        }
    }

    private function item(int $id, string $title, bool $public = true): void
    {
        $this->resource($id, $title, $public, Item::class);
        $this->db->insert('item', ['id' => $id]);
    }

    /** Adds an item to a set, creating the set on first use. */
    private function member(int $item, int $set): void
    {
        if ($this->db->fetchOne('SELECT id FROM item_set WHERE id = ?', [$set]) === false) {
            $this->resource($set, 'Item set ' . $set, true, ItemSet::class);
            $this->db->insert('item_set', ['id' => $set, 'is_open' => 1]);
        }
        $this->db->insert('item_item_set', ['item_id' => $item, 'item_set_id' => $set]);
    }

    private function media(int $id, int $item, string $storage, bool $public = true): void
    {
        $this->resource($id, '', $public, Media::class);
        $this->db->insert('media', [
            'id' => $id, 'item_id' => $item, 'ingester' => 'upload', 'renderer' => 'file', 'storage_id' => $storage,
            'extension' => 'jpg', 'has_original' => 1, 'has_thumbnails' => 1, 'position' => $id,
        ]);
    }

    private function template(int $id): void
    {
        $this->db->insert('resource_template', ['id' => $id, 'label' => 'Template ' . $id]);
    }

    /** A vocabulary term, e.g. "dcterms:title", creating its vocabulary on first use. */
    private function property(int $id, string $term): void
    {
        [$prefix, $localName] = explode(':', $term, 2);
        $vocabulary = $this->db->fetchOne('SELECT id FROM vocabulary WHERE prefix = ?', [$prefix]);
        if ($vocabulary === false) {
            $this->db->insert('vocabulary', ['namespace_uri' => 'http://example.org/' . $prefix . '/', 'prefix' => $prefix, 'label' => $prefix]);
            $vocabulary = $this->db->lastInsertId();
        }
        $this->db->insert('property', ['id' => $id, 'vocabulary_id' => (int) $vocabulary, 'local_name' => $localName, 'label' => $localName]);
    }

    private function value(int $id, int $source, int $property, ?int $target, ?string $literal = null, bool $public = true): void
    {
        $this->db->insert('value', [
            'id' => $id, 'resource_id' => $source, 'property_id' => $property, 'type' => $target !== null ? 'resource' : 'literal',
            'value_resource_id' => $target, 'value' => $literal, 'is_public' => (int) $public,
        ]);
    }

    private function resource(int $id, string $title, bool $public, string $type): void
    {
        $this->db->insert('resource', [
            'id' => $id, 'title' => $title, 'is_public' => (int) $public, 'created' => '2026-01-01 00:00:00', 'resource_type' => $type,
        ]);
    }
}
