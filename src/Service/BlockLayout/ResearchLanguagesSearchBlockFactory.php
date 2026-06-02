<?php
declare(strict_types=1);

namespace DRESearch\Service\BlockLayout;

use DRESearch\Search\SearchProxy;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Site\BlockLayout\ResearchLanguagesSearchBlock;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class ResearchLanguagesSearchBlockFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ResearchLanguagesSearchBlock
    {
        return new ResearchLanguagesSearchBlock(
            $container->get(SearchProxy::class),
            $container->get(ProfileRegistry::class),
        );
    }
}
