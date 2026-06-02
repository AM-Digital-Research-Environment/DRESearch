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

        // Display fields with a backing property (pi_ss → dcterms:creator,
        // member_ss → foaf:member).
        foreach ($this->profile->displayFields() as $field => $def) {
            if (!empty($def['property'])) {
                $this->addLinkedTitles($doc, $values, $def['property'], $field);
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
