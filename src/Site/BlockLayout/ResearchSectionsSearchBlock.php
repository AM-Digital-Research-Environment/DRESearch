<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

/**
 * Faceted search over the DRE research-section corpus — the cluster's thematic
 * sections, filterable by phase and associated person, each card showing the
 * project count, the leaders (PIs or spokesperson), the member count, and the
 * abstract. Registered as the `dreSearchSections` block layout.
 */
final class ResearchSectionsSearchBlock extends AbstractSearchBlock
{
    public function getLabel()
    {
        return 'Research sections search'; // @translate
    }

    protected function profileName(): string
    {
        return 'research_sections';
    }
}
