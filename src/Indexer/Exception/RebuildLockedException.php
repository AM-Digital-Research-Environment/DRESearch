<?php
declare(strict_types=1);

namespace DRESearch\Indexer\Exception;

use RuntimeException;

final class RebuildLockedException extends RuntimeException
{
    public function __construct(string $profile, ?string $activeJobId = null)
    {
        parent::__construct($activeJobId
            ? sprintf('A rebuild of "%s" is already active in job %s.', $profile, $activeJobId)
            : sprintf('A rebuild of "%s" is already active.', $profile));
    }
}
