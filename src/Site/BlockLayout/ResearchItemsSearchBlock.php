<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

/**
 * Faceted search over the DRE research-item corpus. Registered as the
 * `dreSearch` block layout (the original identifier — so blocks already placed
 * on site pages keep working).
 */
final class ResearchItemsSearchBlock extends AbstractSearchBlock
{
    public function getLabel()
    {
        return 'Research items search'; // @translate
    }

    protected function profileName(): string
    {
        return 'research_items';
    }
}
