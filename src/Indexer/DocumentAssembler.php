<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/** Shared batch mapping for full builds and incremental replay. */
final class DocumentAssembler
{
    public function __construct(
        private readonly OmekaSourceRepository $source,
        private readonly MapperInterface $mapper,
        private readonly SearchProfile $profile,
    ) {
    }

    public function documents(array $rows): array
    {
        $ids = array_values(array_map(static fn(array $row): int => (int) $row['id'], $rows));
        if ($ids === []) {
            return [];
        }
        $values = $this->source->loadValues($ids);
        if ($this->mapper instanceof PreparesBatch) {
            $this->mapper->prepare($values);
        }
        $thumbnails = $this->source->loadThumbnails($ids);
        $itemLink = $this->profile->itemLink();
        $reverseLinks = $this->profile->reverseLinks();
        $counts = $itemLink !== null ? $this->source->loadItemCounts($ids, $itemLink) : [];
        [$reverseCounts, $roles] = $reverseLinks !== null
            ? $this->source->loadReverseLinks($ids, $reverseLinks) : [[], []];
        $docs = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $item = ['id' => $id, 'title' => (string) ($row['title'] ?? ''), 'is_public' => true];
            if ($itemLink !== null) {
                $item['item_count'] = $counts[$id] ?? 0;
            }
            if ($reverseLinks !== null) {
                $item['counts'] = [];
                foreach ($reverseCounts as $field => $map) {
                    $item['counts'][$field] = $map[$id] ?? 0;
                }
                $item['roles'] = $roles[$id] ?? [];
            }
            $doc = $this->mapper->map($item, $values[$id] ?? [], $thumbnails[$id] ?? null);
            // Reconciled identifiers (ORCID, Wikidata, GND, LCSH…) mean the same
            // on every kind of record, so they are laid in here rather than by
            // each mapper: any profile that declares same_as_ss gets the URIs
            // of its property, and the card names the register from the host.
            $sameAs = $this->profile->displayFields()['same_as_ss'] ?? null;
            if ($sameAs !== null && !isset($doc['same_as_ss'])) {
                $uris = (new ValueBag($values[$id] ?? []))->uris($sameAs['property'] ?? null);
                if ($uris !== []) {
                    $doc['same_as_ss'] = $uris;
                }
            }
            $doc['_profile'] = $this->profile->name();
            $doc['_kind'] = $this->profile->kind();
            $docs[] = $doc;
        }
        return $docs;
    }
}
