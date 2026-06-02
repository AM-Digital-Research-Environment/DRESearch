<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

/**
 * Faceted search over the DRE publications corpus — the cluster bibliography
 * (author, journal / book, publisher, keyword, language facets, a year range,
 * and a bibliographic-reference card). Registered as the `dreSearchPublications`
 * block layout.
 */
final class ResearchPublicationsSearchBlock extends AbstractSearchBlock
{
    public function getLabel()
    {
        return 'Publications search'; // @translate
    }

    protected function profileName(): string
    {
        return 'research_publications';
    }
}
