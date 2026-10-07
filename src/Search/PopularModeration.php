<?php

declare(strict_types=1);

namespace DRESearch\Search;

use Doctrine\DBAL\Connection;

/**
 * Editors' decisions on popular-search candidates, per corpus.
 *
 * Analytics count whatever anyone sends, and a query that matches one term
 * still finds something, so a determined visitor can make any text "popular".
 * Nothing is therefore shown until an editor approves it on the maintenance
 * page; a hidden query stays out of the review queue. Revoking a decision puts
 * the query back in review. Every decision invalidates the search cache in the
 * same transaction, so the public list reflects it on the next request.
 */
final class PopularModeration
{
    public const TABLE = 'dre_search_popular_moderation';
    public const APPROVED = 'approved';
    public const HIDDEN = 'hidden';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The approved spelling of every approved query.
     *
     * @return array<string,string> keyed by {@see PopularSearches::key()}
     */
    public function approved(string $profile): array
    {
        $approved = [];
        foreach ($this->decisions($profile) as $key => $decision) {
            if ($decision['status'] === self::APPROVED) {
                $approved[$key] = $decision['query'];
            }
        }
        return $approved;
    }

    /**
     * @return array<string,array{query:string,status:string,decided_at:string}>
     *         keyed by {@see PopularSearches::key()}, oldest decision first
     */
    public function decisions(string $profile): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT query, status, decided_at FROM ' . self::TABLE . ' WHERE profile = :profile ORDER BY decided_at, query',
            ['profile' => $profile],
        )->fetchAllAssociative();
        $decisions = [];
        foreach ($rows as $row) {
            $query = (string) $row['query'];
            $decisions[PopularSearches::key($query)] = [
                'query' => $query,
                'status' => (string) $row['status'],
                'decided_at' => (string) $row['decided_at'],
            ];
        }
        return $decisions;
    }

    /** @throws \InvalidArgumentException when the query could never be shown */
    public function approve(string $profile, string $query, ?int $userId = null): void
    {
        $this->decide($profile, $query, self::APPROVED, $userId);
    }

    /** @throws \InvalidArgumentException when the query could never be shown */
    public function hide(string $profile, string $query, ?int $userId = null): void
    {
        $this->decide($profile, $query, self::HIDDEN, $userId);
    }

    /** Withdraw the decision on a query. False when there was none. */
    public function revoke(string $profile, string $query): bool
    {
        return $this->connection->transactional(function () use ($profile, $query): bool {
            $deleted = $this->connection->executeStatement(
                'DELETE FROM ' . self::TABLE . ' WHERE profile = :profile AND query_hash = :hash',
                ['profile' => $profile, 'hash' => self::hash($query)],
            );
            if ($deleted > 0) {
                (new SearchCache($this->connection))->invalidate();
            }
            return $deleted > 0;
        });
    }

    private function decide(string $profile, string $query, string $status, ?int $userId): void
    {
        $query = PopularSearches::clean($query);
        if (!PopularSearches::presentable($query)) {
            throw new \InvalidArgumentException('This query can never be shown to visitors.');
        }
        $this->connection->transactional(function () use ($profile, $query, $status, $userId): void {
            $this->connection->executeStatement(
                'INSERT INTO ' . self::TABLE . ' (profile, query_hash, query, status, decided_by, decided_at)'
                . ' VALUES (:profile, :hash, :query, :status, :user, :now)'
                . ' ON DUPLICATE KEY UPDATE query = VALUES(query), status = VALUES(status),'
                . ' decided_by = VALUES(decided_by), decided_at = VALUES(decided_at)',
                [
                    'profile' => $profile,
                    'hash' => self::hash($query),
                    'query' => $query,
                    'status' => $status,
                    'user' => $userId,
                    'now' => gmdate('Y-m-d H:i:s'),
                ],
            );
            (new SearchCache($this->connection))->invalidate();
        });
    }

    /** Case and whitespace variants of a query share one decision. */
    private static function hash(string $query): string
    {
        return hash('sha256', PopularSearches::key($query));
    }
}
