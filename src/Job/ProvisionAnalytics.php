<?php
declare(strict_types=1);

namespace DRESearch\Job;

use DRESearch\Indexer\AnalyticsSync;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Omeka\Job\AbstractJob;

/** Explicit operator job for optional Typesense search analytics. */
class ProvisionAnalytics extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        /** @var TypesenseClientProvider $provider */
        $provider = $services->get(TypesenseClientProvider::class);
        $client = $provider->getClient();
        /** @var ProfileRegistry $registry */
        $registry = $services->get(ProfileRegistry::class);
        if ($client === null) {
            throw new \RuntimeException('Typesense is not configured.');
        }
        $result = (new AnalyticsSync(
            $client,
            $registry,
            $logger,
        ))->sync();
        if (!$result['enabled']) {
            throw new \RuntimeException(
                'Analytics provisioning failed. Enable search analytics and configure a persistent analytics directory. '
                . ($result['errors'][0] ?? ''),
            );
        }
        $logger->info('DRESearch: analytics provisioning complete', $result);
    }
}
