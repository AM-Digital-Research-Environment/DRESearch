<?php

declare(strict_types=1);

namespace DRESearch\Search;

use Doctrine\DBAL\Connection;

/** Shared across PHP workers; namespace epochs prevent stale fills after invalidation. */
final class SearchCache
{
    private const EPOCH = 'dre-search-cache-epoch';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function key(string $context): string
    {
        $epoch = $this->connection->executeQuery(
            'SELECT payload FROM dre_search_cache WHERE cache_key = :key',
            ['key' => hash('sha256', self::EPOCH)],
        )->fetchOne();
        return hash('sha256', $context . ':' . (string) $epoch);
    }

    public function invalidate(): void
    {
        $this->put(hash('sha256', self::EPOCH), bin2hex(random_bytes(16)), 315360000);
    }

    public function get(string $key): ?array
    {
        $raw = $this->connection->executeQuery(
            'SELECT payload FROM dre_search_cache WHERE cache_key = :key AND expires_at > :now',
            ['key' => $key, 'now' => gmdate('Y-m-d H:i:s')],
        )->fetchOne();
        $value = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($value) ? $value : null;
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        $this->connection->executeStatement(
            'INSERT INTO dre_search_cache (cache_key, payload, expires_at) VALUES (:key, :payload, :expires)'
            . ' ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)',
            ['key' => $key, 'payload' => json_encode($value, JSON_THROW_ON_ERROR), 'expires' => gmdate('Y-m-d H:i:s', time() + $ttl)],
        );
        $this->connection->executeStatement(
            'DELETE FROM dre_search_cache WHERE expires_at < :now LIMIT 100',
            ['now' => gmdate('Y-m-d H:i:s')],
        );
    }
}
