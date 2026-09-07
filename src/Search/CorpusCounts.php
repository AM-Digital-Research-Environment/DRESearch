<?php

declare(strict_types=1);

namespace DRESearch\Search;

use Closure;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SourcePredicate;

/** Counts public source records using the same membership rules as indexing. */
final class CorpusCounts
{
    private const METRICS = [
        'researchItems' => 'research_items',
        'projects' => 'research_projects',
        'people' => 'research_people',
        'organisations' => 'research_organisations',
        'locations' => 'research_locations',
        'languages' => 'research_languages',
        'subjectsTags' => 'research_subjects',
        'publications' => 'research_publications',
        'podcasts' => 'research_podcasts',
        'youtube' => 'research_videos',
    ];

    private readonly Closure $fetchOne;

    public function __construct(
        callable $fetchOne,
        private readonly ProfileRegistry $profiles,
    ) {
        $this->fetchOne = Closure::fromCallable($fetchOne);
    }

    /** @return list<array{k:string,l:string,n:int,s:string}> */
    public function forSite(?int $siteId = null): array
    {
        if ($siteId !== null && $siteId < 1) {
            throw new \InvalidArgumentException('A positive site id is required.');
        }
        $properties = [];
        $propertyId = function (string $term) use (&$properties): ?int {
            if (!array_key_exists($term, $properties)) {
                [$prefix, $local] = array_pad(explode(':', $term, 2), 2, '');
                $id = ($this->fetchOne)(
                    'SELECT p.id FROM property p JOIN vocabulary vo ON p.vocabulary_id = vo.id'
                    . ' WHERE vo.prefix = :prefix AND p.local_name = :local',
                    ['prefix' => $prefix, 'local' => $local],
                );
                $properties[$term] = $id === false ? null : (int) $id;
            }
            return $properties[$term];
        };
        $stats = [];
        foreach (self::METRICS as $key => $name) {
            $profile = $this->profiles->get($name);
            if ($profile === null) {
                continue;
            }
            $predicate = SourcePredicate::compile($profile, $propertyId);
            $sql = 'SELECT COUNT(*) FROM resource WHERE resource_type = :type AND is_public = 1';
            $params = ['type' => 'Omeka\Entity\Item'];
            if ($predicate !== '') {
                $sql .= ' AND ' . $predicate;
            }
            if ($siteId !== null) {
                $sql .= ' AND id IN (SELECT isi.item_id FROM item_site isi'
                    . ' JOIN site s ON s.id = isi.site_id WHERE isi.site_id = :site AND s.is_public = 1)';
                $params['site'] = $siteId;
            }
            $stats[] = [
                'k' => $key,
                'l' => $profile->label(),
                'n' => (int) ($this->fetchOne)($sql, $params),
                's' => '',
            ];
        }
        return $stats;
    }
}
