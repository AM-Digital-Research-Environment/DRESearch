<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/**
 * The document skeleton every mapper shares, and the steps two or more of them
 * repeat.
 *
 * Every Typesense document starts the same way (id, is_public, a title with an
 * "untitled" placeholder, an optional abstract) and ends the same way (the
 * thumbnail); most copy their property-backed facets as linked titles, and
 * several collect people with their item ids or fold them into a derived
 * people_ss facet. These used to be written out in each mapper, Podcast and
 * Video line for line, so a fix to one could miss the others.
 *
 * Which property feeds a field is read from the profile
 * ({@see displayProperty()}), never repeated here as a fallback vocabulary
 * term: a field the profile does not declare is not in its schema, so it is
 * not emitted. {@see \DRESearch\Test\MapperSnapshotTest} pins the documents of
 * every shipped profile.
 */
abstract class AbstractMapper implements MapperInterface
{
    public function __construct(protected readonly SearchProfile $profile)
    {
    }

    /**
     * id, is_public and the title, then the abstract when `$abstractTerm` has one.
     *
     * @param array{id:int, title:string, is_public:bool} $item
     * @param string $untitled sprintf() format for a record without a public title
     * @return array<string,mixed>
     */
    protected function skeleton(array $item, ValueBag $bag, ?string $abstractTerm, string $untitled = '[Untitled #%d]'): array
    {
        $doc = [
            'id'        => (string) $item['id'],
            'is_public' => $item['is_public'],
            'title'     => $item['title'] !== '' ? $item['title'] : sprintf($untitled, $item['id']),
        ];
        if (($abstract = $bag->firstLiteral($abstractTerm)) !== null) {
            $doc['abstract'] = $abstract;
        }
        return $doc;
    }

    /** The property a display field reads, or null when the profile does not declare it. */
    protected function displayProperty(string $field): ?string
    {
        return $this->profile->displayFields()[$field]['property'] ?? null;
    }

    /**
     * Every property-backed facet as its linked titles (literal fallback): the
     * first title for a single-valued facet, the deduplicated list otherwise.
     * Derived facets (no property) are left to the mapper.
     *
     * @param array<string,mixed> $doc
     * @param list<string> $skip facets the mapper builds itself
     */
    protected function addFacetLabels(array &$doc, ValueBag $bag, array $skip = []): void
    {
        foreach ($this->profile->all() as $field => $def) {
            if (empty($def['property']) || in_array($field, $skip, true)) {
                continue;
            }
            $labels = $bag->labels($def['property']);
            if ($labels === []) {
                continue;
            }
            $doc[$field] = $this->profile->isMultivalued($field) ? $labels : $labels[0];
        }
    }

    /**
     * People linked on a property: the names in `$field` and, when `$idsField`
     * is given, their item ids in parallel ("" for an unlinked literal), so the
     * card links each one.
     *
     * @param array<string,mixed> $doc
     * @return list<string> the names, for a derived people_ss
     */
    protected function addPeople(array &$doc, ValueBag $bag, ?string $term, string $field, ?string $idsField = null): array
    {
        [$names, $ids] = $bag->people($term);
        if ($names) {
            $doc[$field] = $names;
            if ($idsField !== null) {
                $doc[$idsField] = $ids;
            }
        }
        return $names;
    }

    /**
     * The derived people_ss facet, when the profile has it: the union of the
     * given name lists, first occurrence kept.
     *
     * @param array<string,mixed> $doc
     * @param list<string> ...$names
     */
    protected function addPeopleFacet(array &$doc, array ...$names): void
    {
        if (!$this->profile->hasFacet('people_ss')) {
            return;
        }
        $people = array_values(array_unique(array_merge(...$names)));
        if ($people) {
            $doc['people_ss'] = $people;
        }
    }

    /**
     * The item id of a single-valued linked facet (series, playlist), in
     * `$idField` when the profile declares it, so the card links the chip.
     *
     * @param array<string,mixed> $doc
     */
    protected function addLinkedId(array &$doc, ValueBag $bag, string $facet, string $idField): void
    {
        if (!isset($this->profile->displayFields()[$idField])) {
            return;
        }
        if (($id = $bag->firstResourceId($this->profile->property($facet))) !== null) {
            $doc[$idField] = (string) $id;
        }
    }

    /** @param array<string,mixed> $doc */
    protected function addThumbnail(array &$doc, ?string $thumbnailUrl): void
    {
        if ($thumbnailUrl !== null) {
            $doc['thumbnail_url'] = $thumbnailUrl;
        }
    }
}
