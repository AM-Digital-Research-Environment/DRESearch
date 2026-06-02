<?php
declare(strict_types=1);

namespace DRESearch\Service\Controller;

use DRESearch\Controller\Admin\MaintenanceController;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class MaintenanceControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): MaintenanceController
    {
        return new MaintenanceController(
            $container->get(TypesenseClientProvider::class),
            $container->get(ProfileRegistry::class),
        );
    }
}
