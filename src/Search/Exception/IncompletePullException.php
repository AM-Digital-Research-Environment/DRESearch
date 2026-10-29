<?php

declare(strict_types=1);

namespace DRESearch\Search\Exception;

/**
 * A bulk pull ({@see \DRESearch\Search\BulkPager}) whose pages held fewer
 * documents than Typesense said matched. The caller refuses the result — a
 * citation file or a map missing records looks complete and is not — and logs
 * it as its own failure, apart from a request that could not be sent.
 */
final class IncompletePullException extends \RuntimeException
{
    public function __construct(public readonly int $received, public readonly int $expected)
    {
        parent::__construct(sprintf('Typesense returned %d of %d expected documents.', $received, $expected));
    }
}
