<?php
declare(strict_types=1);

namespace DRESearch\Site\BlockLayout;

/**
 * Faceted search over the DRE languages corpus — the language authority terms
 * applied to research items and publications. Each card shows the language name
 * and how many public research items and publications are in it. Registered as
 * the `dreSearchLanguages` block layout.
 */
final class ResearchLanguagesSearchBlock extends AbstractSearchBlock
{
    public function getLabel()
    {
        return 'Languages search'; // @translate
    }

    protected function profileName(): string
    {
        return 'research_languages';
    }
}
