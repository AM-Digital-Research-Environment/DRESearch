<?php

declare(strict_types=1);

namespace DRESearch\Settings;

/** Persisted block layout identities shared by factories and scope validation. */
final class BlockProfiles
{
    public const CLASSES = [
        'dreSearch' => \DRESearch\Site\BlockLayout\ResearchItemsSearchBlock::class,
        'dreSearchProjects' => \DRESearch\Site\BlockLayout\ResearchProjectsSearchBlock::class,
        'dreSearchPublications' => \DRESearch\Site\BlockLayout\ResearchPublicationsSearchBlock::class,
        'dreSearchPodcasts' => \DRESearch\Site\BlockLayout\ResearchPodcastsSearchBlock::class,
        'dreSearchVideos' => \DRESearch\Site\BlockLayout\ResearchVideosSearchBlock::class,
        'dreSearchPeople' => \DRESearch\Site\BlockLayout\ResearchPeopleSearchBlock::class,
        'dreSearchSections' => \DRESearch\Site\BlockLayout\ResearchSectionsSearchBlock::class,
        'dreSearchOrganisations' => \DRESearch\Site\BlockLayout\ResearchOrganisationsSearchBlock::class,
        'dreSearchGenres' => \DRESearch\Site\BlockLayout\ResearchGenresSearchBlock::class,
        'dreSearchLanguages' => \DRESearch\Site\BlockLayout\ResearchLanguagesSearchBlock::class,
        'dreSearchLocations' => \DRESearch\Site\BlockLayout\ResearchLocationsSearchBlock::class,
        'dreSearchSubjects' => \DRESearch\Site\BlockLayout\ResearchSubjectsSearchBlock::class,
    ];

    public const PROFILES = [
        'dreSearch' => 'research_items',
        'dreSearchProjects' => 'research_projects',
        'dreSearchPublications' => 'research_publications',
        'dreSearchPodcasts' => 'research_podcasts',
        'dreSearchVideos' => 'research_videos',
        'dreSearchPeople' => 'research_people',
        'dreSearchSections' => 'research_sections',
        'dreSearchOrganisations' => 'research_organisations',
        'dreSearchGenres' => 'research_genres',
        'dreSearchLanguages' => 'research_languages',
        'dreSearchLocations' => 'research_locations',
        'dreSearchSubjects' => 'research_subjects',
    ];
}
