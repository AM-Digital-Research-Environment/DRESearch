<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

/** Verifies ambiguous alias writes and protects every aliased collection from cleanup. */
final class GenerationPublisher
{
    public function __construct(private readonly Client $client, private readonly string $alias)
    {
    }

    public function target(): ?string
    {
        try {
            return $this->client->aliases[$this->alias]->retrieve()['collection_name'] ?? null;
        } catch (ObjectNotFound) {
            return null;
        }
    }

    public function promote(string $collection): void
    {
        try {
            $this->client->aliases->upsert($this->alias, ['collection_name' => $collection]);
        } catch (\Throwable $error) {
            // A timeout can follow a successful PUT. Only a verified target resolves it.
            try {
                if ($this->target() === $collection) {
                    return;
                }
            } catch (\Throwable) {
                // Preserve the original error; cleanup will independently fail closed.
            }
            throw $error;
        }
    }

    public function deleteUnaliased(string $collection): bool
    {
        // Checking all aliases also protects collections shared with another alias.
        $aliases = $this->client->aliases->retrieve();
        if (!isset($aliases['aliases']) || !is_array($aliases['aliases'])) {
            throw new \RuntimeException('Cannot verify aliases; collection retained.');
        }
        foreach ($aliases['aliases'] as $alias) {
            if (($alias['collection_name'] ?? null) === $collection) {
                return false;
            }
        }
        try {
            $this->client->collections[$collection]->delete();
        } catch (ObjectNotFound) {
        }
        return true;
    }
}
