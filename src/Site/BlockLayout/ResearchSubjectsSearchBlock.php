<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

/**
 * Faceted search over the DRE subjects & tags corpus — the subject authority
 * terms applied to research items and publications. One corpus holds both LCSH
 * headings and tags, split by a Type facet. Each card shows the term, a Type chip,
 * and how many public research items and publications use it. Registered as the
 * `dreSearchSubjects` block layout.
 */
final class ResearchSubjectsSearchBlock extends AbstractSearchBlock
{
    public function getLabel()
    {
        return 'Subjects & tags search'; // @translate
    }

    protected function profileName(): string
    {
        return 'research_subjects';
    }
}
