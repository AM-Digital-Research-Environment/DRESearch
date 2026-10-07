<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;

/**
 * One coalesced background drain worker, coordinated through a single
 * `dre_search_worker` row instead of one Omeka job per write request.
 *
 * Protocol (every step is a single-row UPDATE, so it is atomic):
 *  - A producer enqueues its changes, then {@see request()}s work and tries to
 *    {@see claim()} the lease. Only the caller that flips a NULL or stale
 *    heartbeat to "now" dispatches a job; everybody else relies on the worker
 *    that is already alive.
 *  - The worker {@see begin()}s each pass (clears `requested`), drains, and
 *    exits only through {@see finish()}, which succeeds only while `requested`
 *    is still clear. A producer that requested work after the pass began makes
 *    finish() fail, so the worker loops instead of stranding the new rows.
 *  - A failed pass {@see backoff()}s: the heartbeat stays fresh for one stale
 *    window, so a Typesense outage costs one retry per window, not one job per
 *    save. Search requests heal a stranded queue through the same claim.
 */
final class WorkerLease
{
    public const NAME = 'drain';
    /** Seconds after which a silent worker is presumed dead and may be replaced. */
    public const STALE_SECONDS = 120;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $staleSeconds = self::STALE_SECONDS,
    ) {
    }

    /** Record that queued work exists. */
    public function request(): void
    {
        $affected = $this->connection->executeStatement(
            'UPDATE dre_search_worker SET requested = 1, updated_at = :now WHERE name = :name',
            ['now' => $this->now(), 'name' => self::NAME],
        );
        if ($affected === 0) {
            $this->ensureRow(1);
        }
    }

    /**
     * Claim the right to start a worker. True for exactly one caller while no
     * live worker holds the lease.
     */
    public function claim(): bool
    {
        $this->ensureRow(0);
        return $this->connection->executeStatement(
            'UPDATE dre_search_worker SET heartbeat = :now, updated_at = :now'
            . ' WHERE name = :name AND (heartbeat IS NULL OR heartbeat < :stale)',
            ['now' => $this->now(), 'stale' => $this->staleBefore(), 'name' => self::NAME],
        ) === 1;
    }

    /** Give the lease back after a failed dispatch so the next caller retries. */
    public function release(): void
    {
        $this->connection->executeStatement(
            'UPDATE dre_search_worker SET heartbeat = NULL, updated_at = :now WHERE name = :name',
            ['now' => $this->now(), 'name' => self::NAME],
        );
    }

    /** Start a pass: the worker owns the lease and has seen every earlier request. */
    public function begin(): void
    {
        $this->ensureRow(0);
        $this->connection->executeStatement(
            'UPDATE dre_search_worker SET requested = 0, heartbeat = :now, updated_at = :now WHERE name = :name',
            ['now' => $this->now(), 'name' => self::NAME],
        );
    }

    public function beat(): void
    {
        $this->connection->executeStatement(
            'UPDATE dre_search_worker SET heartbeat = :now, updated_at = :now WHERE name = :name',
            ['now' => $this->now(), 'name' => self::NAME],
        );
    }

    /** True when the worker may exit: nobody requested work since begin(). */
    public function finish(): bool
    {
        return $this->connection->executeStatement(
            'UPDATE dre_search_worker SET heartbeat = NULL, updated_at = :now WHERE name = :name AND requested = 0',
            ['now' => $this->now(), 'name' => self::NAME],
        ) === 1;
    }

    /** Failed pass: keep work requested and hold the lease for one stale window. */
    public function backoff(): void
    {
        $this->connection->executeStatement(
            'UPDATE dre_search_worker SET requested = 1, heartbeat = :now, updated_at = :now WHERE name = :name',
            ['now' => $this->now(), 'name' => self::NAME],
        );
    }

    /** @return array{requested:bool,heartbeat:?string,alive:bool} */
    public function status(): array
    {
        $row = $this->connection->executeQuery(
            'SELECT requested, heartbeat FROM dre_search_worker WHERE name = :name',
            ['name' => self::NAME],
        )->fetchAssociative();
        $heartbeat = is_array($row) && $row['heartbeat'] !== null ? (string) $row['heartbeat'] : null;
        return [
            'requested' => is_array($row) && (int) $row['requested'] === 1,
            'heartbeat' => $heartbeat,
            'alive' => $heartbeat !== null && $heartbeat >= $this->staleBefore(),
        ];
    }

    private function ensureRow(int $requested): void
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO dre_search_worker (name, requested, heartbeat, updated_at) VALUES (:name, :requested, NULL, :now)',
                ['name' => self::NAME, 'requested' => $requested, 'now' => $this->now()],
            );
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Row already present.
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function staleBefore(): string
    {
        return gmdate('Y-m-d H:i:s', time() - max(1, $this->staleSeconds));
    }
}
