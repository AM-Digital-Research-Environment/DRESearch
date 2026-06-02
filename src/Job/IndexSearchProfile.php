<?php
declare(strict_types=1);

namespace DRESearch\Job;

use DRESearch\Indexer\Reindexer;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Omeka\Job\AbstractJob;

/**
 * Background job that rebuilds the Typesense index for one search profile from
 * the Omeka database. Dispatched from the admin Maintenance page with a
 * `profile` argument. Progress is written to the Omeka job log (Admin → Jobs),
 * so a long reindex is observable without shell access.
 */
class IndexSearchProfile extends AbstractJob
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

        /** @var ProfileRegistry $registry */
        $registry = $services->get(ProfileRegistry::class);
        $profileName = (string) $this->getArg('profile', '');
        $profile = $registry->get($profileName);
        if ($profile === null) {
            $logger->warn(sprintf('DRESearch: unknown search profile "%s" — reindex skipped.', $profileName));
            return;
        }

        $log = static function (string $message) use ($logger): void {
            $logger->info('DRESearch: ' . $message);
        };

        try {
            $reindexer = new Reindexer($connection, $client, $profile, $log);
            $stats = $reindexer->run();
            $logger->info('DRESearch: reindex complete', $stats);
        } catch (\Throwable $e) {
            $logger->err('DRESearch: reindex failed — ' . $e->getMessage());
            throw $e; // mark the job ERROR in the admin Jobs list
        }
    }
}
