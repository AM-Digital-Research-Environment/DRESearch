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
        private readonly int $retentionDays = 30,
    ) {
    }

    /** @param Closure():bool $cancel @return array<string,mixed> */
    public function runOne(string $profileName, string $jobId, Closure $cancel): array
    {
        $client = $this->provider->getClient();
        if ($client === null) {
            $this->logger->warn('DRESearch: Typesense is not configured — reindex skipped.');
            return ['skipped' => true];
        }
        $profile = $this->registry->get($profileName);
        if ($profile === null) {
            throw new \InvalidArgumentException(sprintf('Unknown search profile "%s".', $profileName));
        }
        $this->syncStopwords($client);
        $stats = $this->runProfile($client, $profile, $jobId, $cancel);
        $stats['analytics'] = (new AnalyticsSync($client, $this->registry, $this->logger))->sync();
        return $stats;
    }

    /** @param Closure():bool $cancel @return array<string,mixed> */
    public function runAll(string $jobId, Closure $cancel): array
    {
        $client = $this->provider->getClient();
        if ($client === null) {
            $this->logger->warn('DRESearch: Typesense is not configured — reindex skipped.');
            return ['skipped' => true];
        }
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
                $results[$profile->name()] = $this->runProfile($client, $profile, $jobId, $cancel);
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
                $failed[] = $profile->label();
                $this->logger->err(sprintf(
                    'DRESearch: reindex of "%s" failed — %s',
                    $profile->label(),
                    $e->getMessage(),
                ));
            }
        }
        $analytics = (new AnalyticsSync($client, $this->registry, $this->logger))->sync();
        if ($failed !== []) {
            throw new \RuntimeException(sprintf(
                'Reindex-all finished with %d of %d corpora failing: %s',
                count($failed),
                $total,
                implode(', ', $failed),
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
        ))->run();
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
