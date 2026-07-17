<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;
use DRESearch\Indexer\Exception\RebuildLockedException;

/** MySQL/MariaDB advisory lock; released automatically if the worker dies. */
final class RebuildLock
{
    private bool $held = false;
    private readonly string $name;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $profile,
        string $alias,
        private readonly ?RebuildStateStore $stateStore = null,
    ) {
        $this->name = 'dre_search:' . substr(hash('sha256', $alias), 0, 48);
    }

    public function acquire(): void
    {
        $acquired = $this->connection->executeQuery(
            'SELECT GET_LOCK(:lockName, 0)',
            ['lockName' => $this->name],
        )->fetchOne();
        if ((int) $acquired !== 1) {
            throw new RebuildLockedException($this->profile, $this->stateStore?->activeJobId($this->profile));
        }
        $this->held = true;
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }
        try {
            $this->connection->executeQuery(
                'SELECT RELEASE_LOCK(:lockName)',
                ['lockName' => $this->name],
            );
        } finally {
            $this->held = false;
        }
    }

    public function __destruct()
    {
        try {
            $this->release();
        } catch (\Throwable) {
        }
    }
}
