<?php
declare(strict_types=1);

namespace DRESearch\Service;

use DRESearch\Controller\SearchController;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\RateLimiter;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class SearchControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SearchController
    {
        return new SearchController(
            $container->get(SearchProxy::class),
            $container->get(RateLimiter::class),
            $container->get('Omeka\Logger'),
        );
    }
}
