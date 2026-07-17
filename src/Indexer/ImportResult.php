<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

/**
 * Normalized result of a Typesense document import.
 *
 * Typesense has returned both decoded arrays and JSONL strings across client
 * versions. This adapter deliberately treats anything except an explicit
 * per-document `success: true` as a rejection: promotion safety must not depend
 * on a batch-level HTTP status or a permissive SDK default.
 */
final class ImportResult
{
    /**
     * @param list<string> $failedIds
     * @param list<string> $errors
     */
    private function __construct(
        private readonly int $successful,
        private readonly array $failedIds,
        private readonly array $errors,
    ) {
    }

    /** @param list<array<string,mixed>> $documents */
    public static function fromResponse(mixed $response, array $documents): self
    {
        $rows = self::rows($response);
        $successful = 0;
        $failedIds = [];
        $errors = [];

        foreach ($documents as $index => $document) {
            $row = $rows[$index] ?? null;
            if (is_array($row) && ($row['success'] ?? null) === true) {
                $successful++;
                continue;
            }

            $failedIds[] = (string) ($document['id'] ?? ('batch-index-' . $index));
            $message = is_array($row) ? (string) ($row['error'] ?? 'missing success response') : 'missing success response';
            $message = trim((string) preg_replace('/\s+/', ' ', $message));
            if ($message === '') {
                $message = 'unknown import error';
            }
            if (!in_array($message, $errors, true) && count($errors) < 5) {
                $errors[] = mb_substr($message, 0, 500);
            }
        }

        return new self($successful, $failedIds, $errors);
    }

    public function successful(): int
    {
        return $this->successful;
    }

    public function failed(): int
    {
        return count($this->failedIds);
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

    public function isComplete(): bool
    {
        return $this->failedIds === [];
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $response): array
    {
        if ($response instanceof \Traversable) {
            $response = iterator_to_array($response, false);
        }
        if (is_string($response)) {
            $rows = [];
            foreach (preg_split('/\R/', trim($response)) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
            return $rows;
        }
        if (!is_array($response)) {
            return [];
        }
        if (array_key_exists('success', $response)) {
            return [$response];
        }
        return array_values(array_filter($response, 'is_array'));
    }
}
