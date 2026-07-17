<?php
declare(strict_types=1);

namespace DRESearch\Service\Indexer;

use DRESearch\Indexer\RebuildStateStore;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class RebuildStateStoreFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): RebuildStateStore
    {
        return new RebuildStateStore($container->get('Omeka\Connection'));
    }
}
