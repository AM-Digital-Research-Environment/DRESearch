<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

/**
 * Maps one source resource — its denormalised columns plus its grouped property
 * values — into a Typesense document for a given search profile. One
 * implementation per profile `kind` (research items, research projects, …).
 *
 * The $values shape is term => list of:
 *   ['vrid' => ?int, 'value' => ?string, 'uri' => ?string, 'title' => ?string]
 * where vrid/title come from a value_resource link, value is the literal (or a
 * URI value's label), and uri is the @id of a URI value (e.g. a DOI link).
 */
interface MapperInterface
{
    /**
     * @param array{id:int, title:string, is_public:bool, item_count?:int} $item
     * @param array<string, list<array{vrid:?int, value:?string, uri:?string, title:?string}>> $values
     * @return array<string,mixed>
     */
    public function map(array $item, array $values, ?string $thumbnailUrl): array;
}
