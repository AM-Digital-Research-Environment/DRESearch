<?php
declare(strict_types=1);

namespace DRESearch\Service\Search;

use DRESearch\Search\BlockScopeResolver;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class BlockScopeResolverFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): BlockScopeResolver
    {
        return new BlockScopeResolver($container->get('Omeka\Connection'));
    }
}
