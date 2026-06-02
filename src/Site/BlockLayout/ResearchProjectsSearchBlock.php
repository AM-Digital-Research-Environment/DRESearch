<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

/**
 * Faceted search over the DRE research-project corpus (institutions, a year
 * range, research sections, and associated-item counts). Registered as the
 * `dreSearchProjects` block layout.
 */
final class ResearchProjectsSearchBlock extends AbstractSearchBlock
{
    public function getLabel()
    {
        return 'Research projects search'; // @translate
    }

    protected function profileName(): string
    {
        return 'research_projects';
    }
}
