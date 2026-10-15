<?php

declare(strict_types=1);

namespace DRESearch\Service\BlockLayout;

use DRESearch\Search\SearchProxy;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Site\ResourcePageBlockLayout\ProjectRecords;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class ProjectRecordsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ProjectRecords
    {
        return new ProjectRecords(
            $container->get(SearchProxy::class),
            $container->get(ProfileRegistry::class),
            $container->get('Omeka\ApiManager'),
        );
    }
}
