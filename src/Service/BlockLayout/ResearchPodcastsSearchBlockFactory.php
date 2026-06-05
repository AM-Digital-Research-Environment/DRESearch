<?php
declare(strict_types=1);

namespace DRESearch\Service\BlockLayout;

use DRESearch\Search\SearchProxy;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Site\BlockLayout\ResearchPodcastsSearchBlock;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class ResearchPodcastsSearchBlockFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ResearchPodcastsSearchBlock
    {
        return new ResearchPodcastsSearchBlock(
            $container->get(SearchProxy::class),
            $container->get(ProfileRegistry::class),
        );
    }
}
