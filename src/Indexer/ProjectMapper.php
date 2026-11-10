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
final class ProjectMapper extends AbstractMapper
{
    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        $bag = new ValueBag($values);
        $doc = $this->skeleton($item, $bag, 'dcterms:abstract');

        // Facet fields — linked-resource titles (or literal fallback).
        $this->addFacetLabels($doc, $bag);

        // PIs (dcterms:creator) keep their person item ids so the card can link
        // each one; members (foaf:member) are names only. The associated-people
        // facet is their union.
        $pis = $this->addPeople($doc, $bag, $this->displayProperty('pi_ss'), 'pi_ss', 'pi_ids');
        $members = $this->addPeople($doc, $bag, $this->displayProperty('member_ss'), 'member_ss');
        $this->addPeopleFacet($doc, $pis, $members);

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

        $this->addThumbnail($doc, $thumbnailUrl);
        return $doc;
    }
}
