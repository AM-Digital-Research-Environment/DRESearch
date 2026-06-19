<?php
declare(strict_types=1);

namespace DRESearch\Job;

use DRESearch\Indexer\StopwordsSync;
use DRESearch\Search\TypesenseClientProvider;
use Omeka\Job\AbstractJob;

/**
 * Background job: sync the `dre_default` stopword set to Typesense.
 *
 * PUTs data/stopwords.json as the English stopword set. Typically <1s and
 * idempotent — runs as a job (visible in Admin → Jobs) so the admin UI doesn't
 * block on the Typesense round-trip and for consistency with the reindex jobs.
 *
 * Useful when:
 *   - The set is missing on a fresh Typesense volume (search degrades to
 *     unfiltered until it's provisioned — see SearchProxy's retry path).
 *   - The word list (data/stopwords.json) was edited and should go live without
 *     rebuilding every collection.
 *
 * The full "Reindex all corpora" job ({@see IndexAllSearchProfiles}) also syncs
 * the set, so a full reindex provisions it too.
 */
class SyncStopwords extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        /** @var TypesenseClientProvider $provider */
        $provider = $services->get(TypesenseClientProvider::class);
        $client = $provider->getClient();
        if ($client === null) {
            $logger->warn('DRESearch: Typesense is not configured — stopwords sync skipped. Set the connection under Modules → DRE Search.');
            return;
        }

        try {
            $stats = StopwordsSync::create($client)->sync();
        } catch (\Throwable $e) {
            $logger->err('DRESearch: stopwords sync failed — ' . $e->getMessage());
            throw $e; // mark the job ERROR in the admin Jobs list
        }

        $logger->info('DRESearch: stopwords synced', $stats);
    }
}
