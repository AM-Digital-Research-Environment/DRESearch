<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Doctrine\DBAL\DriverManager;
use DRESearch\Search\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testUntrustedPeersCannotForgeTheirAddress(): void
    {
        $limiter = $this->limiter([]);
        self::assertSame('203.0.113.9', $limiter->identity('203.0.113.9', '198.51.100.1'));
    }

    public function testTrustedProxyHopsAreSkippedFromTheRight(): void
    {
        $limiter = $this->limiter(['10.0.0.0/8', '172.18.0.2']);
        // Client-forged left entry, then the real client, then two trusted hops.
        self::assertSame('198.51.100.7', $limiter->identity('172.18.0.2', '1.2.3.4, 198.51.100.7, 10.1.2.3'));
        self::assertSame('172.18.0.2', $limiter->identity('172.18.0.2', 'garbage'));
    }

    public function testIpv6ClientsShareTheirSlash64(): void
    {
        $limiter = $this->limiter([]);
        self::assertSame(
            $limiter->identity('2001:db8:abcd:12::1'),
            $limiter->identity('2001:db8:abcd:12:ffff::9'),
        );
        self::assertNotSame($limiter->identity('2001:db8:abcd:12::1'), $limiter->identity('2001:db8:abcd:13::1'));
        self::assertSame('2001:db8:abcd:12::/64', RateLimiter::bucket('2001:db8:abcd:12::1'));
    }

    public function testWeightedRequestsExhaustTheBudgetAndReportTheWait(): void
    {
        if (!getenv('DRE_TEST_MYSQL_HOST')) {
            self::markTestSkipped('Requires disposable MySQL.');
        }
        $params = [
            'driver' => 'pdo_mysql', 'host' => getenv('DRE_TEST_MYSQL_HOST'),
            'port' => (int) (getenv('DRE_TEST_MYSQL_PORT') ?: 3306),
            'user' => getenv('DRE_TEST_MYSQL_USER') ?: 'root', 'password' => getenv('DRE_TEST_MYSQL_PASSWORD') ?: '',
        ];
        $admin = DriverManager::getConnection($params);
        $database = 'dre_test_' . bin2hex(random_bytes(6));
        $admin->executeStatement('CREATE DATABASE ' . $database);
        try {
            $db = DriverManager::getConnection($params + ['dbname' => $database]);
            $db->executeStatement('CREATE TABLE dre_search_rate_limit (bucket_key CHAR(64) PRIMARY KEY, window_started DATETIME NOT NULL, request_count INT NOT NULL DEFAULT 0)');
            $limiter = new RateLimiter($db);
            self::assertSame(0, $limiter->hit('federated', 'client', 5, 3));
            $wait = $limiter->hit('federated', 'client', 5, 3);
            self::assertGreaterThan(0, $wait, 'Two fan-out requests weigh six units against a budget of five.');
            self::assertLessThanOrEqual(60, $wait);
            self::assertSame(0, $limiter->hit('federated', 'someone-else', 5, 3));
            $db->close();
        } finally {
            $admin->executeStatement('DROP DATABASE ' . $database);
            $admin->close();
        }
    }

    /** @param list<string> $trusted */
    private function limiter(array $trusted): RateLimiter
    {
        return new RateLimiter(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $trusted);
    }
}
