<?php

declare(strict_types=1);

namespace DRESearch\Search;

use Doctrine\DBAL\Connection;
use DRESearch\Indexer\WorkerLease;

/**
 * The monitoring probe's answer (`GET /dre-search/api/health`): is Typesense
 * configured and reachable, which corpora are live, hiding pending records or
 * paused, is the incremental worker keeping up, and do public searches run with
 * the search-only key or have they fallen back to the admin key. No secrets,
 * no record ids.
 *
 * Every part degrades on its own: an unreachable Typesense, a missing queue
 * table or an unreadable worker lease leaves that part empty, never the whole
 * report. It reads Typesense with the admin client (the health endpoint is
 * not a collection search), while the {@see SearchProxy} that calls it keeps
 * the breaker.
 */
final class HealthReport
{
    public function __construct(private readonly ?Connection $connection)
    {
    }

    /**
     * @param object|null $client a Typesense\Client, or null when unconfigured
     * @param list<string> $profiles every configured profile name
     * @param array<string,array{ready:bool,exclude:list<int>}> $states the readiness gate's answer for them
     * @param callable(\Throwable):void $onFailure told about a failed reachability probe (the breaker)
     * @param bool|null $searchOnlyKey whether public searches use the search-only key; null when unconfigured
     * @return array<string,mixed>
     */
    public function build(
        bool $configured,
        ?object $client,
        array $profiles,
        array $states,
        callable $onFailure,
        ?bool $searchOnlyKey = null,
    ): array {
        $reachable = false;
        if ($client !== null) {
            try {
                // @phpstan-ignore property.notFound (a Typesense\Client, duck-typed so tests can fake it)
                $reachable = !empty($client->health->retrieve()['ok']);
            } catch (\Throwable $error) {
                $onFailure($error);
            }
        }
        [$pending, $oldest] = $this->pending();
        $corpora = [];
        foreach ($profiles as $name) {
            $count = $pending[$name] ?? 0;
            $corpora[$name] = [
                'public' => !($states[$name]['ready'] ?? false) ? 'paused' : ($count > 0 ? 'hiding' : 'live'),
                'pending' => $count,
            ];
        }
        return [
            'ok' => $configured && $reachable,
            'configured' => $configured,
            'reachable' => $reachable,
            'oldest_pending_seconds' => $oldest !== null ? max(0, time() - (int) strtotime($oldest . ' UTC')) : null,
            'worker' => $this->worker(),
            // `admin`: search works, but with a key that may also write. Not a
            // failure (ok and the status code are unchanged); a monitor should
            // still flag it, as the maintenance page does.
            'search_key' => $searchOnlyKey === null ? null : ($searchOnlyKey ? 'search_only' : 'admin'),
            'profiles' => $corpora,
        ];
    }

    /** @return array{0:array<string,int>,1:?string} queued changes per profile, and the oldest queue time */
    private function pending(): array
    {
        $pending = [];
        $oldest = null;
        try {
            if ($this->connection !== null) {
                foreach (
                    $this->connection->executeQuery(
                        'SELECT profile, COUNT(*) AS n, MIN(queued_at) AS oldest FROM dre_search_change GROUP BY profile',
                    )->fetchAllAssociative() as $row
                ) {
                    $pending[(string) $row['profile']] = (int) $row['n'];
                    $oldest = $oldest === null ? (string) $row['oldest'] : min($oldest, (string) $row['oldest']);
                }
            }
        } catch (\Throwable) {
            return [[], null];
        }
        return [$pending, $oldest];
    }

    /** @return array{alive:bool,requested:bool}|null */
    private function worker(): ?array
    {
        try {
            if ($this->connection === null) {
                return null;
            }
            $status = (new WorkerLease($this->connection))->status();
            return ['alive' => $status['alive'], 'requested' => $status['requested']];
        } catch (\Throwable) {
            return null;
        }
    }
}
