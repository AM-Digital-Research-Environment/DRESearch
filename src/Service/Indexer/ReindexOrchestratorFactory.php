<?php
declare(strict_types=1);

namespace DRESearch\Service\Indexer;

use DRESearch\Indexer\RebuildStateStore;
use DRESearch\Indexer\ReindexOrchestrator;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class ReindexOrchestratorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ReindexOrchestrator
    {
        return new ReindexOrchestrator(
            $container->get('Omeka\Connection'),
            $container->get(TypesenseClientProvider::class),
            $container->get(ProfileRegistry::class),
            $container->get(RebuildStateStore::class),
            $container->get('Omeka\Logger'),
            (int) ($container->get('Config')['dre_search']['operations']['retention_days'] ?? 30),
        );
    }
}
