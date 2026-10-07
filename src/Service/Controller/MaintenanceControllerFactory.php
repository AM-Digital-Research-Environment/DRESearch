<?php

declare(strict_types=1);

namespace DRESearch\Service\Controller;

use DRESearch\Controller\Admin\MaintenanceController;
use DRESearch\Indexer\ChangeQueue;
use DRESearch\Indexer\RebuildStateStore;
use DRESearch\Indexer\WorkerLease;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class MaintenanceControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): MaintenanceController
    {
        $connection = $container->get('Omeka\Connection');
        return new MaintenanceController(
            $container->get(TypesenseClientProvider::class),
            $container->get(ProfileRegistry::class),
            $container->get(RebuildStateStore::class),
            new ChangeQueue($connection),
            new WorkerLease($connection),
            max(0, (int) ($container->get('Config')['dre_search']['operations']['pending_exclusion_limit'] ?? 250)),
        );
    }
}
