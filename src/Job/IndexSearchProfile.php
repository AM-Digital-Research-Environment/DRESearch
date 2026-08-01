<?php
declare(strict_types=1);

namespace DRESearch\Job;

use DRESearch\Indexer\Exception\ReindexCancelledException;
use DRESearch\Indexer\ReindexOrchestrator;
use Omeka\Job\AbstractJob;

/** Rebuild one configured search profile through the shared orchestrator. */
class IndexSearchProfile extends AbstractJob
{
    public function perform(): void
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        /** @var ReindexOrchestrator $orchestrator */
        $orchestrator = $this->getServiceLocator()->get(ReindexOrchestrator::class);
        try {
            $stats = $orchestrator->runOne(
                (string) $this->getArg('profile', ''),
                (string) $this->job->getId(),
                fn(): bool => $this->shouldStop(),
            );
            $logger->info('DRESearch: reindex complete', $stats);
        } catch (ReindexCancelledException $e) {
            $logger->warn('DRESearch: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $logger->err('DRESearch: reindex failed — ' . $e->getMessage());
            throw $e;
        }
    }
}
