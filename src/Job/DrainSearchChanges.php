<?php

declare(strict_types=1);

namespace DRESearch\Job;

use DRESearch\Indexer\IncrementalIndexer;
use Omeka\Job\AbstractJob;

/**
 * The single coalesced incremental worker: drains every profile's queue and
 * keeps looping while writes keep requesting work (see Indexer\WorkerLease).
 */
final class DrainSearchChanges extends AbstractJob
{
    public function perform(): void
    {
        $this->getServiceLocator()->get(IncrementalIndexer::class)->work(fn(): bool => $this->shouldStop());
    }
}
