<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Closure;
use Doctrine\DBAL\Connection;
use DRESearch\Indexer\Exception\ReindexCancelledException;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\SearchProfile;
use DRESearch\Settings\ProfileRegistry;
use Laminas\Log\LoggerInterface;
use Typesense\Client;

/** Shared dependency graph for the one-profile and all-profile reindex jobs. */
final class ReindexOrchestrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TypesenseClientProvider $provider,
        private readonly ProfileRegistry $registry,
        private readonly RebuildStateStore $stateStore,
        private readonly LoggerInterface $logger,
        private readonly int $retentionDays = 0,
        private readonly float $minRetainedRatio = 0.5,
        /** Seconds to wait for a starting Typesense (it answers 503 while loading). */
        private readonly int $readyWaitSeconds = 120,
    ) {
    }

    /** @param Closure():bool $cancel @return array<string,mixed> */
    public function runOne(string $profileName, string $jobId, Closure $cancel, bool $allowShrink = false): array
    {
        $client = $this->provider->getIndexClient();
        if ($client === null) {
            $this->logger->warn('DRESearch: Typesense is not configured — reindex skipped.');
            return ['skipped' => true];
        }
        $this->awaitReady($client, $cancel);
        $profile = $this->registry->get($profileName);
        if ($profile === null) {
            throw new \InvalidArgumentException(sprintf('Unknown search profile "%s".', $profileName));
        }
        $this->syncStopwords($client);
        $stats = $this->runProfile($client, $profile, $jobId, $cancel, $allowShrink);
        $stats['analytics'] = (new AnalyticsSync($client, $this->registry, $this->logger))->sync();
        return $stats;
    }

    /** @param Closure():bool $cancel @return array<string,mixed> */
    public function runAll(string $jobId, Closure $cancel, bool $allowShrink = false): array
    {
        $client = $this->provider->getIndexClient();
        if ($client === null) {
            $this->logger->warn('DRESearch: Typesense is not configured — reindex skipped.');
            return ['skipped' => true];
        }
        $this->awaitReady($client, $cancel);
        $this->syncStopwords($client);
        $profiles = $this->registry->all();
        $total = count($profiles);
        $done = 0;
        $failed = [];
        $results = [];
        foreach ($profiles as $profile) {
            if ($cancel()) {
                throw new ReindexCancelledException(sprintf(
                    'Reindex-all stopped after %d of %d corpora; live aliases were preserved.',
                    $done,
                    $total,
                ));
            }
            try {
                $results[$profile->name()] = $this->runProfile($client, $profile, $jobId, $cancel, $allowShrink);
                $done++;
                $this->logger->info(sprintf(
                    'DRESearch: [%d/%d] "%s" complete',
                    $done,
                    $total,
                    $profile->label(),
                ), $results[$profile->name()]);
            } catch (ReindexCancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $failed[] = sprintf('%s (%s)', $profile->label(), $e->getMessage());
                $this->logger->err(sprintf(
                    'DRESearch: reindex of "%s" failed — %s',
                    $profile->label(),
                    $e->getMessage(),
                ));
            }
        }
        $analytics = (new AnalyticsSync($client, $this->registry, $this->logger))->sync();
        if ($failed !== []) {
            // Carry each corpus' reason into the summary: this exception is what
            // the admin UI surfaces, and hunting the per-corpus log line for it
            // is the difference between a readable failure and a log dig.
            throw new \RuntimeException(sprintf(
                'Reindex-all finished with %d of %d corpora failing: %s',
                count($failed),
                $total,
                implode('; ', $failed),
            ));
        }
        return ['profiles' => $results, 'analytics' => $analytics, 'completed' => $done];
    }

    /** @param Closure():bool $cancel @return array<string,mixed> */
    private function runProfile(
        Client $client,
        SearchProfile $profile,
        string $jobId,
        Closure $cancel,
        bool $allowShrink = false,
    ): array {
        $log = function (string $message): void {
            $this->logger->info('DRESearch: ' . $message);
        };
        return (new Reindexer(
            $this->connection,
            $client,
            $profile,
            $log,
            $this->stateStore,
            $this->retentionDays,
            $jobId,
            $cancel,
            $this->minRetainedRatio,
            $allowShrink,
        ))->run();
    }

    /**
     * A Typesense that has just (re)started answers "Not Ready or Lagging"
     * while it loads its collections; starting a rebuild then fails every
     * corpus. Wait for /health instead (bounded, cancellable), then proceed
     * either way so a real outage still surfaces as a rebuild failure.
     *
     * @param Closure():bool $cancel
     */
    private function awaitReady(Client $client, Closure $cancel): void
    {
        $deadline = time() + max(0, $this->readyWaitSeconds);
        $warned = false;
        while (true) {
            try {
                if (!empty($client->health->retrieve()['ok'])) {
                    return;
                }
            } catch (\Throwable) {
            }
            if (time() >= $deadline || $cancel()) {
                return;
            }
            if (!$warned) {
                $this->logger->info('DRESearch: waiting for Typesense to finish loading before the rebuild.');
                $warned = true;
            }
            sleep(3);
        }
    }

    private function syncStopwords(Client $client): void
    {
        try {
            $stats = StopwordsSync::create($client)->sync();
            $this->logger->info('DRESearch: stopwords synced', $stats);
        } catch (\Throwable $e) {
            $this->logger->warn('DRESearch: stopwords sync failed; search will retry without them — ' . $e->getMessage());
        }
    }
}
