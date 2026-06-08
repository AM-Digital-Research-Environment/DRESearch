<?php
declare(strict_types=1);

namespace DRESearch\Service\BlockLayout;

use DRESearch\Search\SearchProxy;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Site\BlockLayout\ResearchVideosSearchBlock;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class ResearchVideosSearchBlockFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ResearchVideosSearchBlock
    {
        return new ResearchVideosSearchBlock(
            $container->get(SearchProxy::class),
            $container->get(ProfileRegistry::class),
        );
    }
}
