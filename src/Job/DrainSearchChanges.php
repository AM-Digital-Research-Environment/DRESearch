<?php

declare(strict_types=1);

namespace DRESearch\Job;

use DRESearch\Indexer\IncrementalIndexer;
use Omeka\Job\AbstractJob;

final class DrainSearchChanges extends AbstractJob
{
    public function perform(): void
    {
        $this->getServiceLocator()->get(IncrementalIndexer::class)->drain(fn(): bool => $this->shouldStop());
    }
}
