<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\SearchProfile;

/**
 * Maps one research section (resource template 7, item set 17) into a Typesense
 * document.
 *
 * A section names its leaders, its members, and an abstract; the number of
 * projects in the section is the reverse count of projects (template 5) whose
 * dcterms:isPartOf points at it (computed by the {@see Reindexer} via the
 * profile's `item_link` and handed in as $item['item_count']).
 *
 * **Phase is not stored** — it's implicit in which leadership property is present:
 * a Phase 1 section lists PIs (dcterms:creator), a Phase 2 section a single
 * spokesperson (marcrel:spk). The "External" section has neither (no phase). The
 * PI / spokesperson properties come from the profile's display fields, so the
 * Omeka backing stays config-driven; only the phase labels are conventional.
 *
 * Which property feeds which field comes from {@see SearchProfile}; the stable
 * field names (phase_s, people_ss, member_count, project_count, …) are the interface.
 */
final class SectionMapper extends AbstractMapper
{
    /** Section members live on foaf:member (linked Person items). */
    private const MEMBER_TERM = 'foaf:member';

    private const PHASE_1 = 'Phase 1';
    private const PHASE_2 = 'Phase 2';

    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        $bag = new ValueBag($values);
        $doc = $this->skeleton($item, $bag, 'dcterms:abstract');

        $piTerm = $this->displayProperty('pi_ss');            // dcterms:creator (Phase 1)
        $spkTerm = $this->displayProperty('spokesperson_ss'); // marcrel:spk (Phase 2)

        $piNames = $bag->labels($piTerm);
        $spkNames = $bag->labels($spkTerm);
        $memberNames = $bag->labels(self::MEMBER_TERM);

        if ($piNames) {
            $doc['pi_ss'] = $piNames;
        }
        if ($spkNames) {
            $doc['spokesperson_ss'] = $spkNames;
        }
        $doc['member_count'] = count($memberNames);
        $doc['project_count'] = (int) ($item['item_count'] ?? 0);

        // Associated persons facet — union of leaders and members.
        $this->addPeopleFacet($doc, $piNames, $spkNames, $memberNames);

        // Phase facet — implicit in which leadership property the section carries.
        if ($this->profile->hasFacet('phase_s')) {
            if ($piTerm !== null && !empty($values[$piTerm])) {
                $doc['phase_s'] = self::PHASE_1;
            } elseif ($spkTerm !== null && !empty($values[$spkTerm])) {
                $doc['phase_s'] = self::PHASE_2;
            }
            // else: no phase (e.g. the External section) — left unset.
        }

        $this->addThumbnail($doc, $thumbnailUrl);
        return $doc;
    }
}
