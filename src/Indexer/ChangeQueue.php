<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;

/** Durable, deduplicated work. Revision-checked acknowledgements cannot erase a newer edit. */
final class ChangeQueue
{
    /** Attempts for a statement that lost a lock-wait/deadlock race. */
    private const ATTEMPTS = 3;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Queue the same ids for every listed profile (rebuild replay tests, the
     * maintenance retry). Omeka writes use {@see enqueueScoped()}.
     *
     * @param list<string> $profiles
     * @param list<int> $ids
     */
    public function enqueue(array $profiles, array $ids): void
    {
        $ids = ScopeMatcher::normalize($ids);
        $this->enqueueScoped(array_fill_keys($profiles, $ids));
    }

    /**
     * @param array<string,list<int>> $byProfile profile name => item ids
     */
    public function enqueueScoped(array $byProfile): void
    {
        $byProfile = array_filter(
            array_map([ScopeMatcher::class, 'normalize'], $byProfile),
            static fn(array $ids): bool => $ids !== [],
        );
        if ($byProfile === []) {
            return;
        }
        (new \DRESearch\Search\SearchCache($this->connection))->invalidate();
        ksort($byProfile);
        foreach ($byProfile as $profile => $ids) {
            foreach (array_chunk($ids, 100) as $chunk) {
                $params = [];
                $values = [];
                foreach ($chunk as $id) {
                    $values[] = '(?, ?, ?, ?)';
                    array_push($params, (string) $profile, $id, bin2hex(random_bytes(16)), gmdate('Y-m-d H:i:s'));
                }
                $this->retrying(fn() => $this->connection->executeStatement(
                    'INSERT INTO dre_search_change (profile, item_id, revision, queued_at) VALUES '
                    . implode(',', $values)
                    . ' ON DUPLICATE KEY UPDATE revision = VALUES(revision), queued_at = VALUES(queued_at)',
                    $params,
                ));
            }
        }
    }

    /** Keyset paging also bounds replay while edits continue to arrive. */
    public function page(string $profile, int $after = 0): array
    {
        return $this->connection->executeQuery(
            'SELECT item_id, revision FROM dre_search_change WHERE profile = :profile AND item_id > :after'
            . ' ORDER BY item_id LIMIT 100',
            compact('profile', 'after'),
        )->fetchAllAssociative();
    }

    public function acknowledge(string $profile, array $rows): void
    {
        usort($rows, static fn(array $a, array $b): int => (int) $a['item_id'] <=> (int) $b['item_id']);
        foreach (array_chunk($rows, 100) as $chunk) {
            $conditions = [];
            $params = [$profile];
            foreach ($chunk as $row) {
                $conditions[] = '(item_id = ? AND revision = ?)';
                array_push($params, (int) $row['item_id'], (string) $row['revision']);
            }
            $this->retrying(fn() => $this->connection->executeStatement(
                'DELETE FROM dre_search_change WHERE profile = ? AND (' . implode(' OR ', $conditions) . ')',
                $params,
            ));
        }
    }

    /**
     * Drop all queued work for a profile that has never been built: its first
     * rebuild reads current SQL state, so the rows would only hold it paused.
     * The caller must hold the profile lock (no build can be in progress).
     */
    public function clear(string $profile): void
    {
        $this->retrying(fn() => $this->connection->executeStatement(
            'DELETE FROM dre_search_change WHERE profile = :profile',
            ['profile' => $profile],
        ));
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $counts = [];
        foreach (
            $this->connection->executeQuery(
                'SELECT profile, COUNT(*) AS pending FROM dre_search_change GROUP BY profile',
            )->fetchAllAssociative() as $row
        ) {
            $counts[$row['profile']] = (int) $row['pending'];
        }
        return $counts;
    }

    /** @return array<string,string> profile => oldest queued_at (UTC) */
    public function oldest(): array
    {
        $oldest = [];
        foreach (
            $this->connection->executeQuery(
                'SELECT profile, MIN(queued_at) AS oldest FROM dre_search_change GROUP BY profile',
            )->fetchAllAssociative() as $row
        ) {
            $oldest[(string) $row['profile']] = (string) $row['oldest'];
        }
        return $oldest;
    }

    /**
     * @template T
     * @param callable():T $statement
     * @return T
     */
    private function retrying(callable $statement): mixed
    {
        for ($attempt = 1;; $attempt++) {
            try {
                return $statement();
            } catch (RetryableException $error) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $error;
                }
                usleep(20_000 * $attempt);
            }
        }
    }
}
