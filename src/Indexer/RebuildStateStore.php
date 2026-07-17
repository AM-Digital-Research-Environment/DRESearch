<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;

/** Persists operator-visible rebuild state and session-owned generations. */
final class RebuildStateStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function activeJobId(string $profile): ?string
    {
        $value = $this->connection->executeQuery(
            'SELECT active_job_id FROM dre_search_profile_state WHERE profile = :profile',
            ['profile' => $profile],
        )->fetchOne();
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> Session-owned builds left by a worker that no longer holds the profile lock. */
    public function orphanedCollections(string $profile): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT collection_name FROM dre_search_generation'
            . ' WHERE profile = :profile AND status IN (:building, :verifying)',
            ['profile' => $profile, 'building' => 'building', 'verifying' => 'verifying'],
        )->fetchFirstColumn();
        return array_values(array_map('strval', $rows));
    }

    public function markBuilding(
        string $profile,
        string $alias,
        string $collection,
        string $token,
        string $jobId,
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->transactional(function (Connection $connection) use (
            $profile,
            $alias,
            $collection,
            $token,
            $jobId,
            $now,
        ): void {
            $connection->executeStatement(
                'INSERT INTO dre_search_profile_state'
                . ' (profile, collection_alias, status, active_job_id, active_collection, started_at, updated_at)'
                . ' VALUES (:profile, :alias, :status, :job, :collection, :now, :now)'
                . ' ON DUPLICATE KEY UPDATE collection_alias = VALUES(collection_alias), status = VALUES(status),'
                . ' active_job_id = VALUES(active_job_id), active_collection = VALUES(active_collection),'
                . ' started_at = VALUES(started_at), updated_at = VALUES(updated_at)',
                [
                    'profile' => $profile,
                    'alias' => $alias,
                    'collection' => $collection,
                    'status' => 'building',
                    'job' => $jobId,
                    'now' => $now,
                ],
            );
            $connection->executeStatement(
                'INSERT INTO dre_search_generation (profile, collection_name, session_token, status, created_at)'
                . ' VALUES (:profile, :collection, :token, :status, :now)'
                . ' ON DUPLICATE KEY UPDATE session_token = VALUES(session_token), status = VALUES(status)',
                ['profile' => $profile, 'collection' => $collection, 'token' => $token, 'status' => 'building', 'now' => $now],
            );
        });
    }

    public function markLive(
        string $profile,
        string $collection,
        ?string $previous,
        int $durationMs,
        int $attempted,
        int $imported,
        int $failed,
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->transactional(function (Connection $connection) use (
            $profile,
            $collection,
            $previous,
            $durationMs,
            $attempted,
            $imported,
            $failed,
            $now,
        ): void {
            $connection->executeStatement(
                'UPDATE dre_search_generation SET status = :retired'
                . ' WHERE profile = :profile AND status IN (:live, :rollback)',
                ['retired' => 'retired', 'profile' => $profile, 'live' => 'live', 'rollback' => 'rollback'],
            );
            if ($previous !== null && $previous !== '') {
                $connection->executeStatement(
                    'UPDATE dre_search_generation SET status = :status'
                    . ' WHERE profile = :profile AND collection_name = :collection',
                    ['status' => 'rollback', 'profile' => $profile, 'collection' => $previous],
                );
            }
            $connection->executeStatement(
                'UPDATE dre_search_generation SET status = :status, promoted_at = :now'
                . ' WHERE profile = :profile AND collection_name = :collection',
                ['status' => 'live', 'now' => $now, 'profile' => $profile, 'collection' => $collection],
            );
            $connection->executeStatement(
                'UPDATE dre_search_profile_state SET status = :status, live_collection = :collection,'
                . ' previous_collection = :previous, active_job_id = NULL, active_collection = NULL,'
                . ' dirty = 0, dirty_reason = NULL, last_success_at = :now, finished_at = :now,'
                . ' last_duration_ms = :duration, last_documents = :documents, documents_attempted = :attempted,'
                . ' documents_imported = :imported, documents_failed = :failed, last_error_code = NULL,'
                . ' updated_at = :now WHERE profile = :profile',
                [
                    'status' => 'live',
                    'collection' => $collection,
                    'previous' => $previous,
                    'now' => $now,
                    'duration' => $durationMs,
                    'documents' => $imported,
                    'attempted' => $attempted,
                    'imported' => $imported,
                    'failed' => $failed,
                    'profile' => $profile,
                ],
            );
        });
    }

    public function markVerifying(string $profile, int $attempted, int $imported, int $failed): void
    {
        $this->connection->executeStatement(
            'UPDATE dre_search_profile_state SET status = :status, documents_attempted = :attempted,'
            . ' documents_imported = :imported, documents_failed = :failed, updated_at = :now'
            . ' WHERE profile = :profile',
            [
                'status' => 'verifying',
                'attempted' => $attempted,
                'imported' => $imported,
                'failed' => $failed,
                'now' => gmdate('Y-m-d H:i:s'),
                'profile' => $profile,
            ],
        );
    }

    public function markTerminal(
        string $profile,
        string $status,
        string $errorCode,
        int $durationMs,
        int $attempted,
        int $imported,
        int $failed,
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->executeStatement(
            'UPDATE dre_search_profile_state SET status = :status, active_job_id = NULL, active_collection = NULL,'
            . ' last_failure_at = :now, finished_at = :now, last_duration_ms = :duration,'
            . ' documents_attempted = :attempted, documents_imported = :imported, documents_failed = :failed,'
            . ' last_error_code = :code, updated_at = :now WHERE profile = :profile',
            [
                'status' => $status,
                'now' => $now,
                'duration' => $durationMs,
                'attempted' => $attempted,
                'imported' => $imported,
                'failed' => $failed,
                'code' => $errorCode,
                'profile' => $profile,
            ],
        );
    }

    /** @param list<string> $profiles */
    public function markDirty(array $profiles, string $reason): void
    {
        $now = gmdate('Y-m-d H:i:s');
        foreach ($profiles as $profile) {
            $this->connection->executeStatement(
                'INSERT INTO dre_search_profile_state (profile, status, dirty, dirty_reason, updated_at)'
                . ' VALUES (:profile, :status, 1, :reason, :now)'
                . ' ON DUPLICATE KEY UPDATE dirty = 1, dirty_reason = VALUES(dirty_reason), updated_at = VALUES(updated_at)',
                ['profile' => $profile, 'status' => 'stale', 'reason' => mb_substr($reason, 0, 255), 'now' => $now],
            );
        }
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->connection->executeQuery('SELECT * FROM dre_search_profile_state')->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['profile']] = $row;
        }
        return $out;
    }

    /** @param list<string> $keep @return list<string> */
    public function cleanupCandidates(string $profile, array $keep, int $retentionDays): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $retentionDays) * 86400);
        $rows = $this->connection->executeQuery(
            'SELECT collection_name FROM dre_search_generation'
            . ' WHERE profile = :profile AND status IN (:retired, :failed, :cancelled) AND created_at < :cutoff',
            ['profile' => $profile, 'retired' => 'retired', 'failed' => 'failed', 'cancelled' => 'cancelled', 'cutoff' => $cutoff],
        )->fetchFirstColumn();
        return array_values(array_filter(array_map('strval', $rows), static fn(string $name): bool => !in_array($name, $keep, true)));
    }

    public function markGeneration(string $collection, string $status): void
    {
        $this->connection->executeStatement(
            'UPDATE dre_search_generation SET status = :status WHERE collection_name = :collection',
            compact('status', 'collection'),
        );
    }

    public function forgetGeneration(string $collection): void
    {
        $this->connection->executeStatement(
            'DELETE FROM dre_search_generation WHERE collection_name = :collection',
            compact('collection'),
        );
    }
}
