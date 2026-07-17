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
        $bag = new ValueBag($values);
        $doc = [
            'id'        => (string) $item['id'],
            'is_public' => $item['is_public'],
            'title'     => $item['title'] !== '' ? $item['title'] : sprintf('[Untitled #%d]', $item['id']),
        ];

        if (($abstract = $bag->firstLiteral('dcterms:abstract')) !== null) {
            $doc['abstract'] = $abstract;
        }

        // Facet fields — linked-resource titles (or literal fallback).
        foreach ($this->profile->all() as $field => $def) {
            if (!empty($def['property'])) {
                $titles = $bag->labels($def['property']);
                if ($titles !== []) {
                    $doc[$field] = $titles;
                }
            }
        }

        // PIs (dcterms:creator) and members (foaf:member). PIs keep their person
        // item ids alongside the names so the card can link each one.
        $df = $this->profile->displayFields();
        [$piNames, $piIds] = $bag->people($df['pi_ss']['property'] ?? null);
        if ($piNames) {
            $doc['pi_ss'] = $piNames;
            $doc['pi_ids'] = $piIds;
        }
        [$memberNames] = $bag->people($df['member_ss']['property'] ?? null);
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
        [$start, $end] = $bag->firstYearRange($this->profile->dateProperty());
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

}
