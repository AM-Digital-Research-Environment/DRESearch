<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use RuntimeException;
use Typesense\Client as TypesenseClient;

/**
 * Idempotent uploader for the `dre_synonyms` synonym set (data/synonyms.json).
 *
 * Typesense 30 made synonyms top-level synonym SETS. Rather than linking the
 * set into every collection schema (which would need a rebuild to change), each
 * non-browse query names it in `synonym_sets` ({@see \DRESearch\Search\QueryBuilder}),
 * exactly like the stopword set, and SearchExecutor drops the parameter and
 * retries when the set is missing on a fresh server. PUT /synonym_sets/{name}
 * replaces the whole set, so syncing is safe on every reindex and on demand.
 */
final class SynonymsSync
{
    public const SET_NAME = 'dre_synonyms';

    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly string $synonymsJsonPath,
    ) {
    }

    public static function create(TypesenseClient $typesense): self
    {
        return new self($typesense, dirname(__DIR__, 2) . '/data/synonyms.json');
    }

    /** @return array{set:string,groups:int} */
    public function sync(): array
    {
        $items = self::items($this->synonymsJsonPath);
        $this->typesense->synonymSets->upsert(self::SET_NAME, ['items' => $items]);
        return ['set' => self::SET_NAME, 'groups' => count($items)];
    }

    /**
     * Validated synonym groups from the JSON file.
     *
     * @return list<array{id:string,synonyms:list<string>,root?:string}>
     */
    public static function items(string $path): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException("Synonyms file not readable: {$path}");
        }
        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload) || !is_array($payload['items'] ?? null)) {
            throw new RuntimeException("Synonyms file malformed (missing 'items' array): {$path}");
        }
        $items = [];
        $seen = [];
        foreach ($payload['items'] as $i => $item) {
            $synonyms = is_array($item) ? array_values(array_filter(
                array_map(static fn($s): string => trim((string) $s), (array) ($item['synonyms'] ?? [])),
                static fn(string $s): bool => $s !== '',
            )) : [];
            $id = is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : '';
            if ($id === '' || isset($seen[$id]) || count($synonyms) < 2) {
                throw new RuntimeException(
                    "Synonyms item #{$i} needs a unique 'id' and at least two 'synonyms': {$path}"
                );
            }
            $seen[$id] = true;
            $entry = ['id' => $id, 'synonyms' => $synonyms];
            if (is_string($item['root'] ?? null) && $item['root'] !== '') {
                $entry['root'] = $item['root'];
            }
            $items[] = $entry;
        }
        return $items;
    }
}
