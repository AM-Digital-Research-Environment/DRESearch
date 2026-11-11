<?php

declare(strict_types=1);

namespace DRESearch\Test\Support;

use Doctrine\DBAL\Connection;

/**
 * An in-memory SQLite connection that reads MySQL's
 * `ON DUPLICATE KEY UPDATE` as SQLite's upsert, for the one statement the
 * rate limiter writes, so a controller test can count requests without a
 * MySQL server. Use it as DriverManager's `wrapperClass`.
 */
final class SqliteUpsertConnection extends Connection
{
    /** @param array<int|string,mixed> $params */
    public function executeStatement($sql, array $params = [], array $types = [])
    {
        $sql = str_replace(
            ' ON DUPLICATE KEY UPDATE request_count = request_count + :increment',
            ' ON CONFLICT (bucket_key) DO UPDATE SET request_count = request_count + :increment',
            (string) $sql,
        );
        return parent::executeStatement($sql, $params, $types);
    }
}
