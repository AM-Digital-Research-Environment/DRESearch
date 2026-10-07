<?php

declare(strict_types=1);

namespace DRESearch\Service;

use DRESearch\Indexer\IncrementalIndexer;
use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\ReadinessGate;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class SearchProxyFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SearchProxy
    {
        $config = $container->get('Config')['dre_search'] ?? [];
        $connection = $container->get('Omeka\Connection');
        $gate = new ReadinessGate(
            $connection,
            max(0, (int) ($config['operations']['pending_exclusion_limit'] ?? 250)),
            // Resolved only when a stranded queue is seen, never per request.
            static function () use ($container): void {
                $container->get(IncrementalIndexer::class)->wake();
            },
        );
        return new SearchProxy(
            $container->get(TypesenseClientProvider::class),
            $container->get(ProfileRegistry::class),
            $container->get(BlockScopeResolver::class),
            $container->get('Omeka\Logger'),
            array_values(array_map('strval', (array) ($config['federated']['union_profiles'] ?? []))),
            $connection,
            $gate,
            (array) ($config['popular_searches'] ?? []),
        );
    }
}
