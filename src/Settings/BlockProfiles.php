<?php

declare(strict_types=1);

namespace DRESearch\Settings;

/**
 * The page-block layouts this module registers: each searches one profile
 * ({@see \DRESearch\Site\BlockLayout\SearchBlock}). The single source for the
 * block factory, the block picker's labels and the scope resolver, which
 * checks that a saved block's layout belongs to the profile a request names.
 *
 * The names are stored in `site_page_block.layout` by every block an editor
 * placed, so they never change; `dreSearch` predates the other corpora. A new
 * corpus adds a row here and the same name under `block_layouts.factories` in
 * config/module.config.php (Omeka lists only configured names in the picker;
 * a test keeps the two in step).
 */
final class BlockProfiles
{
    /** @var array<string,array{profile:string,label:string}> */
    public const LAYOUTS = [
        'dreSearch' => [
            'profile' => 'research_items',
            'label' => 'Research items search', // @translate
        ],
        'dreSearchProjects' => [
            'profile' => 'research_projects',
            'label' => 'Research projects search', // @translate
        ],
        'dreSearchPublications' => [
            'profile' => 'research_publications',
            'label' => 'Publications search', // @translate
        ],
        'dreSearchPodcasts' => [
            'profile' => 'research_podcasts',
            'label' => 'Podcasts search', // @translate
        ],
        'dreSearchVideos' => [
            'profile' => 'research_videos',
            'label' => 'YouTube videos search', // @translate
        ],
        'dreSearchPeople' => [
            'profile' => 'research_people',
            'label' => 'People search', // @translate
        ],
        'dreSearchSections' => [
            'profile' => 'research_sections',
            'label' => 'Research sections search', // @translate
        ],
        'dreSearchOrganisations' => [
            'profile' => 'research_organisations',
            'label' => 'Organisations search', // @translate
        ],
        'dreSearchGenres' => [
            'profile' => 'research_genres',
            'label' => 'Genres search', // @translate
        ],
        'dreSearchLanguages' => [
            'profile' => 'research_languages',
            'label' => 'Languages search', // @translate
        ],
        'dreSearchLocations' => [
            'profile' => 'research_locations',
            'label' => 'Locations search', // @translate
        ],
        'dreSearchSubjects' => [
            'profile' => 'research_subjects',
            'label' => 'Subjects & tags search', // @translate
        ],
    ];

    /** The profile a stored block layout searches, or null for a layout that is not ours. */
    public static function profileFor(string $layout): ?string
    {
        return self::LAYOUTS[$layout]['profile'] ?? null;
    }

    /** The block picker's (untranslated) label for a layout, or null for one that is not ours. */
    public static function labelFor(string $layout): ?string
    {
        return self::LAYOUTS[$layout]['label'] ?? null;
    }

    /** @return list<string> every registered layout name */
    public static function names(): array
    {
        return array_keys(self::LAYOUTS);
    }
}
