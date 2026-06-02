<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

/**
 * Faceted search over the DRE organisations corpus — institutions and groups
 * (bands, choirs, archives, …) in one place, split by a Type facet and filterable
 * by the role each plays (funder, contributor, host institution). Each card shows
 * how many projects, research items, and people the organisation is associated
 * with. Registered as the `dreSearchOrganisations` block layout.
 */
final class ResearchOrganisationsSearchBlock extends AbstractSearchBlock
{
    public function getLabel()
    {
        return 'Organisations search'; // @translate
    }

    protected function profileName(): string
    {
        return 'research_organisations';
    }
}
