<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/**
 * Maps one research project (resource template 5, item set 20) into a Typesense
 * document.
 *
 * Unlike research items, a project's links are unambiguous — frapo:isFundedBy is
 * always an institution, dcterms:isPartOf always a research section, dcterms:creator
 * always a PI — so the facets resolve straight from the linked resource title
 * (or a literal fallback for unreconciled names). No AuthorityResolver needed.
 *
 * Which property feeds which field comes from {@see SearchProfile} (config-driven);
 * the stable Typesense field names (institution_ss, section_ss, year_start, …) are
 * the interface.
 *
 * Two project-specific shapes:
 *   - date range : dcterms:temporal is a numeric:interval whose @value is
 *                  "2020-01-01/2023-12-31" (or just a start) → year_start / year_end.
 *   - item_count : the number of research items linking back to this project,
 *                  computed by the reindexer and passed in via $item['item_count'];
 *                  surfaced as the has_items facet ("Yes"/"No") and a card figure.
 */
final class ProjectMapper implements MapperInterface
{
    public function __construct(private readonly SearchProfile $profile)
    {
    }

    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        $doc = [
            'id'        => (string) $item['id'],
            'is_public' => $item['is_public'],
            'title'     => $item['title'] !== '' ? $item['title'] : sprintf('[Untitled #%d]', $item['id']),
        ];

        if (($abstract = $this->firstLiteral($values, 'dcterms:abstract')) !== null) {
            $doc['abstract'] = $abstract;
        }

        // Facet fields — linked-resource titles (or literal fallback).
        foreach ($this->profile->all() as $field => $def) {
            if (!empty($def['property'])) {
                $this->addLinkedTitles($doc, $values, $def['property'], $field);
            }
        }

        // PIs (dcterms:creator) and members (foaf:member). PIs keep their person
        // item ids alongside the names so the card can link each one.
        $df = $this->profile->displayFields();
        [$piNames, $piIds] = $this->collectPeople($values, $df['pi_ss']['property'] ?? null);
        if ($piNames) {
            $doc['pi_ss'] = $piNames;
            $doc['pi_ids'] = $piIds;
        }
        [$memberNames] = $this->collectPeople($values, $df['member_ss']['property'] ?? null);
        if ($memberNames) {
            $doc['member_ss'] = $memberNames;
        }

        // Associated-people facet — union of PIs and members.
        if ($this->profile->hasFacet('people_ss')) {
            $people = array_values(array_unique(array_merge($piNames, $memberNames)));
            if ($people) {
                $doc['people_ss'] = $people;
            }
        }

        // Year range from dcterms:temporal.
        [$start, $end] = $this->resolveRange($values, $this->profile->dateProperty());
        if ($start !== null) {
            $doc['year_start'] = $start;
        }
        if ($end !== null) {
            $doc['year_end'] = $end;
        }

        // Associated research-item count + the derived has_items facet.
        $count = (int) ($item['item_count'] ?? 0);
        $doc['item_count'] = $count;
        if ($this->profile->hasFacet('has_items')) {
            $doc['has_items'] = $count > 0 ? 'Yes' : 'No';
        }

        if ($thumbnailUrl !== null) {
            $doc['thumbnail_url'] = $thumbnailUrl;
        }

        return $doc;
    }

    /**
     * Collect a property's linked-resource titles (falling back to the literal
     * value for unreconciled entries) into a string[] field.
     *
     * @param array<string, list<array{vrid:?int, value:?string, title:?string}>> $values
     */
    private function addLinkedTitles(array &$doc, array $values, string $term, string $field): void
    {
        $out = [];
        foreach ($values[$term] ?? [] as $v) {
            $label = ($v['title'] ?? '') !== '' ? $v['title'] : ($v['value'] ?? '');
            if ($label !== null && $label !== '') {
                $out[] = $label;
            }
        }
        if ($out) {
            $doc[$field] = array_values(array_unique($out));
        }
    }

    /**
     * Collect a person property's display names and their matching resource ids
     * (empty string where a value is a literal rather than a link), deduped by
     * name and kept parallel so pi_ss[i] ↔ pi_ids[i].
     *
     * @param array<string, list<array{vrid:?int, value:?string, title:?string}>> $values
     * @return array{0:list<string>, 1:list<string>}
     */
    private function collectPeople(array $values, ?string $term): array
    {
        $names = [];
        $ids = [];
        $seen = [];
        if ($term === null) {
            return [$names, $ids];
        }
        foreach ($values[$term] ?? [] as $v) {
            $name = ($v['title'] ?? '') !== '' ? $v['title'] : ($v['value'] ?? '');
            if ($name === null || $name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $names[] = $name;
            $ids[] = $v['vrid'] !== null ? (string) $v['vrid'] : '';
        }
        return [$names, $ids];
    }

    /** @param array<string, list<array{vrid:?int, value:?string, title:?string}>> $values */
    private function firstLiteral(array $values, string $term): ?string
    {
        foreach ($values[$term] ?? [] as $v) {
            if (($v['value'] ?? '') !== '') {
                return $v['value'];
            }
        }
        return null;
    }

    /**
     * Parse a dcterms:temporal interval into [startYear, endYear]. The stored
     * value is "YYYY-MM-DD/YYYY-MM-DD" (or a single date / bare year); the first
     * four-digit run is the start, the last is the end. End defaults to start.
     *
     * @param array<string, list<array{vrid:?int, value:?string, title:?string}>> $values
     * @return array{0:?int, 1:?int}
     */
    private function resolveRange(array $values, ?string $term): array
    {
        if ($term === null) {
            return [null, null];
        }
        foreach ($values[$term] ?? [] as $v) {
            $raw = (string) ($v['value'] ?? '');
            if ($raw === '' || !preg_match_all('/\d{4}/', $raw, $m) || $m[0] === []) {
                continue;
            }
            $years = array_map('intval', $m[0]);
            $start = $years[0];
            $end = (int) end($years);
            if ($end < $start) {
                $end = $start;
            }
            return [$start, $end];
        }
        return [null, null];
    }
}
