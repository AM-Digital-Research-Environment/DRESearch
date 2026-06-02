<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

/**
 * Faceted search over the DRE genres corpus — the genre/form authority terms
 * applied to research items. Each card shows the genre name and how many public
 * research items carry it. Registered as the `dreSearchGenres` block layout.
 */
final class ResearchGenresSearchBlock extends AbstractSearchBlock
{
    public function getLabel()
    {
        return 'Genres search'; // @translate
    }

    protected function profileName(): string
    {
        return 'research_genres';
    }
}
