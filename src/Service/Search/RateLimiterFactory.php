<?php

declare(strict_types=1);

namespace DRESearch\Service\Search;

use DRESearch\Search\RateLimiter;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class RateLimiterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): RateLimiter
    {
        $proxies = $container->get('Config')['dre_search']['rate_limits']['trusted_proxies'] ?? [];
        return new RateLimiter(
            $container->get('Omeka\Connection'),
            array_values(array_map('strval', is_array($proxies) ? $proxies : [])),
        );
    }
}
