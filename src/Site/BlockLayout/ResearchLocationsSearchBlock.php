<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

/**
 * Faceted search over the DRE locations corpus — the place authority terms
 * (countries, cities, regions) applied to research items, split by a Type facet.
 * Each card shows the place name, a Type chip, and how many public research items
 * mention it. Registered as the `dreSearchLocations` block layout.
 */
final class ResearchLocationsSearchBlock extends AbstractSearchBlock
{
    public function getLabel()
    {
        return 'Locations search'; // @translate
    }

    protected function profileName(): string
    {
        return 'research_locations';
    }
}
