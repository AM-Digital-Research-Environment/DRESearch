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
        return new RateLimiter($container->get('Omeka\Connection'));
    }
}
