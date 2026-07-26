<?php
declare(strict_types=1);

namespace DRESearch\Job;

use DRESearch\Indexer\Reindexer;
use DRESearch\Indexer\RebuildStateStore;
use DRESearch\Indexer\Exception\ReindexCancelledException;
use DRESearch\Indexer\StopwordsSync;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Omeka\Job\AbstractJob;

/**
 * Background job that rebuilds the Typesense index for EVERY search profile, one
 * after another, from the Omeka database. Dispatched from the admin Maintenance
 * page's "Reindex all" button.
 *
 * Running the corpora sequentially in a single job (rather than dispatching one
 * job each) keeps the load gentle on a modest host — only one reindex touches
 * MySQL/Typesense at a time — and gives a single entry in Admin → Jobs to track.
 * Progress is written to the Omeka job log per corpus. A corpus that fails is
 * logged and skipped so the rest still run; the job is marked ERROR at the end if
 * any failed. The admin can stop the job between corpora (shouldStop()).
 */
class IndexAllSearchProfiles extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $connection = $services->get('Omeka\Connection');

        /** @var TypesenseClientProvider $provider */
        $provider = $services->get(TypesenseClientProvider::class);
        $client = $provider->getClient();
        if ($client === null) {
            $logger->warn('DRESearch: Typesense is not configured — reindex skipped. Set the connection under Modules → DRE Search.');
            return;
        }

        // Provision the English stopword set the search queries reference. A
        // server-wide concept, so it runs once here (not per corpus); non-fatal so
        // a stopwords hiccup never aborts the reindex.
        try {
            $stats = StopwordsSync::create($client)->sync();
            $logger->info(sprintf('DRESearch: stopword set "%s" synced (%d words).', $stats['set'], $stats['count']));
        } catch (\Throwable $e) {
            $logger->warn('DRESearch: stopwords sync failed (search still works, unfiltered) — ' . $e->getMessage());
        }

        /** @var ProfileRegistry $registry */
        $registry = $services->get(ProfileRegistry::class);
        $profiles = $registry->all();
        $config = $services->get('Config')['dre_search']['operations'] ?? [];
        $stateStore = $services->get(RebuildStateStore::class);
        // AbstractJob exposes the Job entity as a protected property; there is no
        // getJob() accessor in Omeka core.
        $jobId = (string) $this->job->getId();

        $log = static function (string $message) use ($logger): void {
            $logger->info('DRESearch: ' . $message);
        };

        $total = count($profiles);
        $done = 0;
        $failed = [];
        $logger->info(sprintf('DRESearch: reindex-all starting — %d corpora.', $total));

        foreach ($profiles as $profile) {
            // The admin can stop the job from the Jobs page. The reindexer also
            // checks within a corpus and removes its unpublished staging build.
            if ($this->shouldStop()) {
                $logger->warn(sprintf('DRESearch: reindex-all stopped after %d of %d corpora (by request).', $done, $total));
                return;
            }
            try {
                $reindexer = new Reindexer(
                    $connection,
                    $client,
                    $profile,
                    $log,
                    $stateStore,
                    (int) ($config['retention_days'] ?? 30),
                    $jobId,
                    fn(): bool => $this->shouldStop(),
                );
                $stats = $reindexer->run();
                $done++;
                $logger->info(sprintf('DRESearch: [%d/%d] "%s" complete', $done, $total, $profile->label()), $stats);
            } catch (ReindexCancelledException $e) {
                $logger->warn(sprintf('DRESearch: reindex-all cancelled during "%s"; live aliases were preserved.', $profile->label()));
                return;
            } catch (\Throwable $e) {
                $failed[] = $profile->label();
                $logger->err(sprintf('DRESearch: reindex of "%s" failed — %s', $profile->label(), $e->getMessage()));
            }
        }

        if ($failed !== []) {
            // Surface a single ERROR in the Jobs list — but only after attempting
            // every corpus, so one bad corpus never blocks the others.
            throw new \RuntimeException(sprintf(
                'DRESearch: reindex-all finished with %d of %d corpora failing: %s',
                count($failed),
                $total,
                implode(', ', $failed),
            ));
        }

        $logger->info(sprintf('DRESearch: reindex-all complete — %d corpora reindexed.', $done));
    }
}
