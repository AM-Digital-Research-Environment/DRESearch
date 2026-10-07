<?php

declare(strict_types=1);

namespace DRESearch\Search;

use Closure;
use Doctrine\DBAL\Connection;

/**
 * Decides, per profile, whether public search may answer and which documents
 * it must hide meanwhile.
 *
 * A queued change means the indexed copy of that item (or of a document that
 * embeds it) may be stale — possibly still showing metadata that has just been
 * made private. Rather than pausing the whole corpus until the worker catches
 * up, the pending ids are excluded from every query (`id:!=[…]`); only when
 * more than {@see $exclusionLimit} are pending, or the profile is marked dirty
 * (unknown impact: a full rebuild is needed), does the profile pause.
 *
 * A queue whose oldest row has waited longer than {@see $healAfterSeconds} is
 * presumed stranded (a failed dispatch, a dead worker) and wakes a worker —
 * bounded by the worker lease, so traffic cannot start more than one.
 */
final class ReadinessGate
{
    /** @var array<string,array{ready:bool,exclude:list<int>}> answers for the current call */
    private array $memo = [];

    /**
     * @param Closure():mixed|null $wake asks for a drain worker
     */
    public function __construct(
        private readonly ?Connection $connection,
        private readonly int $exclusionLimit = 250,
        private readonly ?Closure $wake = null,
        private readonly int $healAfterSeconds = 120,
    ) {
    }

    /**
     * @param list<string> $profiles
     * @return array<string,array{ready:bool,exclude:list<int>}>
     */
    public function check(array $profiles): array
    {
        $profiles = array_values(array_unique($profiles));
        if ($this->connection === null || $profiles === []) {
            return array_fill_keys($profiles, ['ready' => true, 'exclude' => []]);
        }
        $missing = array_values(array_filter($profiles, fn(string $p): bool => !isset($this->memo[$p])));
        if ($missing !== []) {
            try {
                $this->memo = $this->load($missing) + $this->memo;
            } catch (\Throwable) {
                // Fail closed: without the queue we cannot know what is stale.
                foreach ($missing as $profile) {
                    $this->memo[$profile] = ['ready' => false, 'exclude' => []];
                }
            }
        }
        return array_intersect_key($this->memo, array_flip($profiles));
    }

    /**
     * Forget earlier answers. The proxy calls this at every public entry
     * point, so one call reuses its answers (a federated search asks per
     * corpus) but a later call never sees a stale one.
     */
    public function reset(): void
    {
        $this->memo = [];
    }

    public function ready(string ...$profiles): bool
    {
        foreach ($this->check(array_values($profiles)) as $state) {
            if (!$state['ready']) {
                return false;
            }
        }
        return true;
    }

    /** @return list<int> */
    public function exclusions(string $profile): array
    {
        return $this->check([$profile])[$profile]['exclude'] ?? [];
    }

    /**
     * @param list<string> $profiles
     * @return array<string,array{ready:bool,exclude:list<int>}>
     */
    private function load(array $profiles): array
    {
        $connection = $this->connection;
        if ($connection === null) {
            return array_fill_keys($profiles, ['ready' => true, 'exclude' => []]);
        }
        $in = implode(',', array_fill(0, count($profiles), '?'));
        $rows = $connection->executeQuery(
            "SELECT profile, 'dirty' AS kind, 1 AS n, NULL AS oldest FROM dre_search_profile_state"
            . " WHERE dirty = 1 AND profile IN ($in)"
            . " UNION ALL SELECT profile, 'pending' AS kind, COUNT(*) AS n, MIN(queued_at) AS oldest FROM dre_search_change"
            . " WHERE profile IN ($in) GROUP BY profile",
            array_merge($profiles, $profiles),
        )->fetchAllAssociative();

        /** @var array<string,array{ready:bool,exclude:list<int>}> $state */
        $state = array_fill_keys($profiles, ['ready' => true, 'exclude' => []]);
        $pending = [];
        $stranded = false;
        $healBefore = gmdate('Y-m-d H:i:s', time() - $this->healAfterSeconds);
        foreach ($rows as $row) {
            $profile = (string) $row['profile'];
            if (!isset($state[$profile])) {
                continue;
            }
            if ($row['kind'] === 'dirty') {
                $state[$profile] = ['ready' => false, 'exclude' => []];
                continue;
            }
            $count = (int) $row['n'];
            if ($count > $this->exclusionLimit) {
                $state[$profile] = ['ready' => false, 'exclude' => []];
            } elseif ($count > 0) {
                $pending[] = $profile;
            }
            if ($count > 0 && $row['oldest'] !== null && (string) $row['oldest'] < $healBefore) {
                $stranded = true;
            }
        }
        $pending = array_values(array_filter($pending, static fn(string $p): bool => $state[$p]['ready']));
        if ($pending !== []) {
            $in = implode(',', array_fill(0, count($pending), '?'));
            foreach (
                $connection->executeQuery(
                    "SELECT profile, item_id FROM dre_search_change WHERE profile IN ($in) ORDER BY item_id",
                    $pending,
                )->fetchAllNumeric() as [$profile, $id]
            ) {
                $profile = (string) $profile;
                if (isset($state[$profile])) {
                    $state[$profile] = ['ready' => $state[$profile]['ready'], 'exclude' => [...$state[$profile]['exclude'], (int) $id]];
                }
            }
        }
        if ($stranded && $this->wake !== null) {
            try {
                ($this->wake)();
            } catch (\Throwable) {
                // Healing is opportunistic; the admin retry remains.
            }
        }
        return $state;
    }
}
