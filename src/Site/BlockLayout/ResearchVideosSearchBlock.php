<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

/**
 * Faceted search over the DRE YouTube-videos corpus — the cluster's recorded talks,
 * interviews, panels and screenings published on YouTube (a Playlist facet, a
 * Speaker facet, a Language facet, a Year slider, sort by date, and a card with the
 * video thumbnail, the abstract, the speakers, a Transcript badge, and a "Watch"
 * link). Registered as the `dreSearchVideos` block layout.
 */
final class ResearchVideosSearchBlock extends AbstractSearchBlock
{
    public function getLabel()
    {
        return 'YouTube videos search'; // @translate
    }

    protected function profileName(): string
    {
        return 'research_videos';
    }
}
