<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SourcePredicate;
use Omeka\Entity\Item;

/**
 * Which profiles' source scope (template, item set, extra sources) contains
 * each item — the same {@see SourcePredicate} the indexer and the public
 * counts use, evaluated for a bounded id list in ONE `UNION ALL` round trip.
 *
 * Visibility is deliberately ignored: a private item still belongs to the
 * profile whose index may hold a stale public copy of it, so the change must
 * reach that profile's queue to delete it. Callers capture membership before a
 * write as well as after it, so an item leaving a profile's scope (template,
 * item set or required property changed) is still reconciled there.
 */
final class ScopeMatcher
{
    /** @var array<string,?int> */
    private array $propertyIds = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ProfileRegistry $registry,
    ) {
    }

    /**
     * @param list<int> $ids
     * @return array<string,list<int>> profile name => ids in its scope
     */
    public function match(array $ids): array
    {
        $ids = self::normalize($ids);
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $idList = implode(',', $chunk);
            $parts = [];
            $params = [];
            foreach ($this->registry->all() as $profile) {
                $scope = SourcePredicate::compile($profile, fn(string $term): ?int => $this->propertyId($term));
                $parts[] = 'SELECT ? AS profile, id FROM resource WHERE resource_type = ? AND id IN (' . $idList . ')'
                    . ($scope !== '' ? ' AND ' . $scope : '');
                $params[] = $profile->name();
                $params[] = Item::class;
            }
            foreach ($this->connection->executeQuery(implode(' UNION ALL ', $parts), $params)->fetchAllNumeric() as [$profile, $id]) {
                $out[(string) $profile][] = (int) $id;
            }
        }
        foreach ($out as $profile => $profileIds) {
            $out[$profile] = self::normalize($profileIds);
        }
        return $out;
    }

    /**
     * Union of two profile => ids maps.
     *
     * @param array<string,list<int>> $a
     * @param array<string,list<int>> $b
     * @return array<string,list<int>>
     */
    public static function merge(array $a, array $b): array
    {
        foreach ($b as $profile => $ids) {
            $a[$profile] = self::normalize(array_merge($a[$profile] ?? [], $ids));
        }
        return $a;
    }

    /**
     * Sorted, unique, positive ids. Sorting keeps concurrent multi-row queue
     * writes acquiring row locks in the same order (no lock-order deadlocks).
     *
     * @param array<mixed> $ids
     * @return list<int>
     */
    public static function normalize(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        sort($ids);
        return $ids;
    }

    private function propertyId(string $term): ?int
    {
        if (array_key_exists($term, $this->propertyIds)) {
            return $this->propertyIds[$term];
        }
        [$prefix, $local] = array_pad(explode(':', $term, 2), 2, '');
        $id = $this->connection->executeQuery(
            'SELECT p.id FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id'
            . ' WHERE v.prefix = :prefix AND p.local_name = :local',
            ['prefix' => $prefix, 'local' => $local],
        )->fetchOne();
        return $this->propertyIds[$term] = $id !== false ? (int) $id : null;
    }
}
