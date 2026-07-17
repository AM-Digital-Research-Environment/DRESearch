<?php
declare(strict_types=1);

namespace DRESearch\Service\BlockLayout;

use DRESearch\Search\SearchProxy;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Site\BlockLayout\AbstractSearchBlock;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/** One factory for all profile-bound block layout subclasses. */
final class SearchBlockFactory implements FactoryInterface
{
    private const CLASSES = [
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

    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): AbstractSearchBlock
    {
        $class = self::CLASSES[(string) $requestedName] ?? null;
        if ($class === null) {
            throw new \InvalidArgumentException(sprintf('Unknown DRE Search block layout "%s".', (string) $requestedName));
        }
        return new $class(
            $container->get(SearchProxy::class),
            $container->get(ProfileRegistry::class),
        );
    }
}
