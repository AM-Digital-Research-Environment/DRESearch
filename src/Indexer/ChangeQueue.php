<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;

/** Durable, deduplicated work. Revision-checked acknowledgements cannot erase a newer edit. */
final class ChangeQueue
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function enqueue(array $profiles, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids !== []) {
            (new \DRESearch\Search\SearchCache($this->connection))->invalidate();
        }
        foreach ($profiles as $profile) {
            foreach (array_chunk($ids, 100) as $chunk) {
                $params = [];
                $values = [];
                foreach ($chunk as $id) {
                    $values[] = '(?, ?, ?, ?)';
                    array_push($params, $profile, $id, bin2hex(random_bytes(16)), gmdate('Y-m-d H:i:s'));
                }
                $this->connection->executeStatement(
                    'INSERT INTO dre_search_change (profile, item_id, revision, queued_at) VALUES '
                    . implode(',', $values)
                    . ' ON DUPLICATE KEY UPDATE revision = VALUES(revision), queued_at = VALUES(queued_at)',
                    $params,
                );
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
        foreach (array_chunk($rows, 100) as $chunk) {
            $conditions = [];
            $params = [$profile];
            foreach ($chunk as $row) {
                $conditions[] = '(item_id = ? AND revision = ?)';
                array_push($params, (int) $row['item_id'], (string) $row['revision']);
            }
            $this->connection->executeStatement(
                'DELETE FROM dre_search_change WHERE profile = ? AND (' . implode(' OR ', $conditions) . ')',
                $params,
            );
        }
    }

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
}
