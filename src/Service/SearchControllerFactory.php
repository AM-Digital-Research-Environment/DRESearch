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
        $configured = $container->get('Config')['dre_search']['rate_limits'] ?? [];
        $limits = SearchController::DEFAULT_LIMITS;
        foreach (is_array($configured) ? $configured : [] as $scope => $limit) {
            if (isset($limits[$scope]) && is_numeric($limit) && (int) $limit > 0) {
                $limits[$scope] = (int) $limit;
            }
        }
        return new SearchController(
            $container->get(SearchProxy::class),
            $container->get(RateLimiter::class),
            $container->get('Omeka\Logger'),
            $limits,
        );
    }
}
