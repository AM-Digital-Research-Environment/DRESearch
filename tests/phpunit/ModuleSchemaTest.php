<?php

declare(strict_types=1);

namespace DRESearch\Test;

use PHPUnit\Framework\TestCase;

/**
 * Install creates and uninstall drops the same tables because both read
 * Module::TABLES; this pins that each entry creates the table it is named
 * after, so a copied statement cannot create one table and drop another.
 * OmekaIntegrationTest runs both directions against MySQL.
 */
final class ModuleSchemaTest extends TestCase
{
    public function testEveryTableEntryCreatesTheTableItIsNamedAfter(): void
    {
        require_once dirname(__DIR__, 2) . '/Module.php';
        $tables = (new \ReflectionClassConstant(\DRESearch\Module::class, 'TABLES'))->getValue();
        self::assertIsArray($tables);
        self::assertSame([
            'dre_search_profile_state',
            'dre_search_generation',
            'dre_search_rate_limit',
            'dre_search_change',
            'dre_search_worker',
            'dre_search_cache',
            'dre_search_popular_moderation',
        ], array_keys($tables));
        foreach ($tables as $name => $create) {
            self::assertIsString($create);
            self::assertMatchesRegularExpression('/^CREATE TABLE IF NOT EXISTS ' . preg_quote((string) $name, '/') . ' \(/', $create);
            self::assertStringEndsWith('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $create);
        }
    }
}
