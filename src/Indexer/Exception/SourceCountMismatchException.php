<?php

declare(strict_types=1);

namespace DRESearch\Indexer\Exception;

use RuntimeException;

/**
 * The staging collection does not hold one document per record of the
 * profile's source scope (records with a change still queued aside), so the
 * generation is not promoted. Recorded as `source_count_mismatch`.
 */
final class SourceCountMismatchException extends RuntimeException
{
    public function __construct(
        private readonly int $expected,
        private readonly int $stored,
        private readonly int $pending,
    ) {
        parent::__construct(sprintf(
            'Refusing to promote: the source scope holds %d public record(s) outside the %d with a queued change,'
            . ' the staging collection %d. A record was written without a queued change (an import that bypassed'
            . ' Omeka\'s API, or a lost event); rebuild again, and check the profile scope if it persists.',
            $expected,
            $pending,
            $stored,
        ));
    }

    public function expected(): int
    {
        return $this->expected;
    }

    public function stored(): int
    {
        return $this->stored;
    }

    public function pending(): int
    {
        return $this->pending;
    }
}
