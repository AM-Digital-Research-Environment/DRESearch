<?php
declare(strict_types=1);

namespace DRESearch\Job;

use DRESearch\Indexer\Reindexer;
use DRESearch\Search\TypesenseClientProvider;
use Omeka\Job\AbstractJob;

/**
 * Background job that rebuilds the Typesense index from the Omeka database.
 * Dispatched from the admin Maintenance page. Progress is written to the Omeka
 * job log (Admin → Jobs), so a long reindex is observable without shell access.
 */
class IndexResearchItems extends AbstractJob
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

        $config = $services->get('Config')['dre_search'] ?? [];
        $templateId = (int) ($config['research_template_id'] ?? 10);

        $log = static function (string $message) use ($logger): void {
            $logger->info('DRESearch: ' . $message);
        };

        try {
            $reindexer = new Reindexer($connection, $client, $provider->collection(), $templateId, $log);
            $stats = $reindexer->run();
            $logger->info('DRESearch: reindex complete', $stats);
        } catch (\Throwable $e) {
            $logger->err('DRESearch: reindex failed — ' . $e->getMessage());
            throw $e; // mark the job ERROR in the admin Jobs list
        }
    }
}
