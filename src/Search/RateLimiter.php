<?php

declare(strict_types=1);

namespace DRESearch\Search;

use Doctrine\DBAL\Connection;

/**
 * Database-backed fixed-window limit for the public endpoints, shared by every
 * PHP worker.
 *
 * Identity is the client address. Behind a reverse proxy REMOTE_ADDR is the
 * proxy, which would put every visitor in one bucket, so X-Forwarded-For is
 * honoured only when the direct peer is a configured trusted proxy, read from
 * the right and skipping further trusted hops (a client can forge entries on
 * the left, never the hop the proxy appended). IPv6 clients are bucketed by
 * /64, the block a single subscriber usually controls.
 */
final class RateLimiter
{
    /**
     * @param list<string> $trustedProxies IPs or CIDR ranges of reverse proxies
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $trustedProxies = [],
    ) {
    }

    /**
     * Count a request of the given weight. Returns 0 when it is allowed, else
     * the seconds until the window resets (the Retry-After value).
     */
    public function hit(string $scope, string $identity, int $limit, int $weight = 1, int $windowSeconds = 60): int
    {
        $windowSeconds = max(1, $windowSeconds);
        $window = intdiv(time(), $windowSeconds);
        $key = hash('sha256', $scope . "\0" . $identity . "\0" . $window);
        $started = gmdate('Y-m-d H:i:s', $window * $windowSeconds);
        $weight = max(1, $weight);
        $this->connection->executeStatement(
            'INSERT INTO dre_search_rate_limit (bucket_key, window_started, request_count)'
            . ' VALUES (:key, :started, :weight) ON DUPLICATE KEY UPDATE request_count = request_count + :increment',
            ['key' => $key, 'started' => $started, 'weight' => $weight, 'increment' => $weight],
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
        if ($count <= max(1, $limit)) {
            return 0;
        }
        return max(1, ($window + 1) * $windowSeconds - time());
    }

    public function allow(string $scope, string $identity, int $limit, int $windowSeconds = 60): bool
    {
        return $this->hit($scope, $identity, $limit, 1, $windowSeconds) === 0;
    }

    /** The client identity to bucket on, from the request's server parameters. */
    public function identity(string $remoteAddr, string $forwardedFor = ''): string
    {
        $client = trim($remoteAddr);
        if ($client !== '' && $forwardedFor !== '' && $this->trusted($client)) {
            $hops = array_reverse(array_filter(array_map('trim', explode(',', $forwardedFor))));
            foreach ($hops as $hop) {
                if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                    break; // malformed: stop at the last address a trusted hop vouched for
                }
                $client = $hop;
                if (!$this->trusted($hop)) {
                    break;
                }
            }
        }
        return self::bucket($client);
    }

    /** IPv6 → its /64 prefix; IPv4 and anything unparseable unchanged. */
    public static function bucket(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $address !== '' ? $address : 'unknown';
        }
        $packed = inet_pton($address);
        if ($packed === false) {
            return $address;
        }
        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    private function trusted(string $address): bool
    {
        foreach ($this->trustedProxies as $range) {
            if (self::inRange($address, (string) $range)) {
                return true;
            }
        }
        return false;
    }

    private static function inRange(string $address, string $range): bool
    {
        $range = trim($range);
        if ($range === '') {
            return false;
        }
        [$subnet, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $ip = @inet_pton($address);
        $net = @inet_pton((string) $subnet);
        if ($ip === false || $net === false || strlen($ip) !== strlen($net)) {
            return false;
        }
        $bits = $bits === null ? strlen($ip) * 8 : max(0, min(strlen($ip) * 8, (int) $bits));
        $bytes = intdiv($bits, 8);
        if (substr($ip, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;
        return (ord($ip[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
