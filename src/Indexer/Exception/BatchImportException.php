<?php
declare(strict_types=1);

namespace DRESearch\Indexer\Exception;

use RuntimeException;

final class BatchImportException extends RuntimeException
{
    /**
     * @param list<string> $failedIds
     * @param list<string> $errors
     */
    public function __construct(
        private readonly string $collection,
        private readonly int $successful,
        private readonly array $failedIds,
        private readonly array $errors,
    ) {
        parent::__construct(sprintf(
            'Typesense rejected %d document(s) in collection "%s" (ids: %s; errors: %s).',
            count($failedIds),
            $collection,
            implode(', ', array_slice($failedIds, 0, 20)),
            implode(' | ', $errors),
        ));
    }

    public function successful(): int
    {
        return $this->successful;
    }

    /** @return list<string> */
    public function failedIds(): array
    {
        return $this->failedIds;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function collection(): string
    {
        return $this->collection;
    }
}
