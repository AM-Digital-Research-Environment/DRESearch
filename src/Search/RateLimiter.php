<?php
declare(strict_types=1);

namespace DRESearch\Search;

use Doctrine\DBAL\Connection;

/** Small database-backed fixed-window fallback for expensive public endpoints. */
final class RateLimiter
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function allow(string $scope, string $identity, int $limit, int $windowSeconds = 60): bool
    {
        $window = (int) floor(time() / max(1, $windowSeconds));
        $key = hash('sha256', $scope . "\0" . $identity . "\0" . $window);
        $started = gmdate('Y-m-d H:i:s', $window * $windowSeconds);
        $this->connection->executeStatement(
            'INSERT INTO dre_search_rate_limit (bucket_key, window_started, request_count)'
            . ' VALUES (:key, :started, 1) ON DUPLICATE KEY UPDATE request_count = request_count + 1',
            compact('key', 'started'),
        );
        $count = (int) $this->connection->executeQuery(
            'SELECT request_count FROM dre_search_rate_limit WHERE bucket_key = :key',
            ['key' => $key],
        )->fetchOne();
        if (random_int(1, 100) === 1) {
            $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
            try {
                $this->connection->executeStatement(
                    'DELETE FROM dre_search_rate_limit WHERE window_started < :cutoff',
                    compact('cutoff'),
                );
            } catch (\Throwable) {
                // Opportunistic housekeeping must not change this request's
                // already-determined rate-limit result.
            }
        }
        return $count <= max(1, $limit);
    }
}
