<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;

/**
 * Omeka property ids by "prefix:local_name" term, looked up once per run.
 *
 * The source repository, the scope matcher and the authority resolver each
 * used to resolve terms on their own (the last by comparing
 * CONCAT(prefix, ':', local_name) on every property row, which no index can
 * serve). One resolver is now shared by everything a rebuild or a drain pass
 * builds: a lookup matches the vocabulary prefix and the property's local name
 * as two columns, so it uses their unique indexes, and every answer — an
 * unknown term included — is remembered. Terms reach it validated by
 * {@see \DRESearch\Settings\SearchProfile} or from module code.
 */
final class PropertyResolver
{
    /** @var array<string,?int> term => id, null when the instance has no such property */
    private array $ids = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function id(string $term): ?int
    {
        return $this->ids([$term])[$term] ?? null;
    }

    /**
     * The ids of the terms this instance has, in one query for any not yet
     * resolved; terms without a property are left out.
     *
     * @param list<string> $terms
     * @return array<string,int> term => property id
     */
    public function ids(array $terms): array
    {
        $missing = [];
        foreach ($terms as $term) {
            if (!array_key_exists($term, $this->ids)) {
                $missing[$term] = true;
            }
        }
        if ($missing !== []) {
            $conditions = [];
            $params = [];
            // Omeka's columns compare case-insensitively: map each row back to
            // the term as it was asked for.
            $asked = [];
            foreach (array_keys($missing) as $term) {
                $term = (string) $term;
                $this->ids[$term] = null;
                $asked[mb_strtolower($term)][] = $term;
                [$prefix, $local] = array_pad(explode(':', $term, 2), 2, '');
                $conditions[] = '(vo.prefix = ? AND p.local_name = ?)';
                array_push($params, $prefix, $local);
            }
            $rows = $this->connection->executeQuery(
                'SELECT vo.prefix, p.local_name, p.id FROM property p JOIN vocabulary vo ON vo.id = p.vocabulary_id'
                . ' WHERE ' . implode(' OR ', $conditions),
                $params,
            )->fetchAllNumeric();
            foreach ($rows as [$prefix, $local, $id]) {
                foreach ($asked[mb_strtolower($prefix . ':' . $local)] ?? [] as $term) {
                    $this->ids[$term] = (int) $id;
                }
            }
        }
        $out = [];
        foreach ($terms as $term) {
            if (($this->ids[$term] ?? null) !== null) {
                $out[$term] = (int) $this->ids[$term];
            }
        }
        return $out;
    }
}
