<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

/**
 * Faceted search over the DRE podcasts corpus — the cluster's podcast episodes
 * (a Series facet, a People facet unioning hosts + guests, a Language facet, sort
 * by episode number or date, and a card with the series logo, the abstract, the
 * people in the episode, and a "Listen" link). Registered as the
 * `dreSearchPodcasts` block layout.
 */
final class ResearchPodcastsSearchBlock extends AbstractSearchBlock
{
    public function getLabel()
    {
        return 'Podcasts search'; // @translate
    }

    protected function profileName(): string
    {
        return 'research_podcasts';
    }
}
