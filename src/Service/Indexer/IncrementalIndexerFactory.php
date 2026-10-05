<?php

declare(strict_types=1);

namespace DRESearch\Service\Indexer;

use DRESearch\Indexer\IncrementalIndexer;
use DRESearch\Indexer\RebuildStateStore;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds the IncrementalIndexer with the shared DBAL connection, the (optional)
 * Typesense client provider, the profile registry, and Omeka's logger. The
 * provider stays null-safe when unconfigured. An unavailable Typesense server
 * leaves durable pending changes for retry without blocking an Omeka save.
 */
final class IncrementalIndexerFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): IncrementalIndexer {
        return new IncrementalIndexer(
            connection: $container->get('Omeka\Connection'),
            provider:   $container->get(TypesenseClientProvider::class),
            registry:   $container->get(ProfileRegistry::class),
            logger:     $container->get('Omeka\Logger'),
            stateStore: $container->get(RebuildStateStore::class),
            schedule: static function () use ($container): void {
                $container->get('Omeka\Job\Dispatcher')->dispatch(\DRESearch\Job\DrainSearchChanges::class, []);
            },
        );
    }
}
