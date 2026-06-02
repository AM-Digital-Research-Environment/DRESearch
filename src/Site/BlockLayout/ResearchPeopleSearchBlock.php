<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

/**
 * Faceted search over the DRE people corpus — researchers and contributors,
 * filterable by affiliation and role, with each card showing how many research
 * items and publications the person is associated with. Registered as the
 * `dreSearchPeople` block layout.
 */
final class ResearchPeopleSearchBlock extends AbstractSearchBlock
{
    public function getLabel()
    {
        return 'People search'; // @translate
    }

    protected function profileName(): string
    {
        return 'research_people';
    }
}
