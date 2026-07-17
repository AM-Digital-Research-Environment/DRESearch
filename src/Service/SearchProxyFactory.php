<?php
declare(strict_types=1);

namespace DRESearch\Service;

use DRESearch\Search\SearchProxy;
use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class SearchProxyFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SearchProxy
    {
        return new SearchProxy(
            $container->get(TypesenseClientProvider::class),
            $container->get(ProfileRegistry::class),
            $container->get(BlockScopeResolver::class),
            $container->get('Omeka\Logger'),
        );
    }
}
