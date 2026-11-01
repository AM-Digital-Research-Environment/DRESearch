<?php

declare(strict_types=1);

namespace DRESearch\Service\View\Helper;

use DRESearch\Search\TypesenseClientProvider;
use DRESearch\View\Helper\SearchBar;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * The header bar renders on every page, and needs only whether search is
 * configured: the connection provider answers that from settings alone,
 * without the search proxy, its readiness gate and cache, or the profile
 * registry (twelve validated profiles).
 */
final class SearchBarFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SearchBar
    {
        return new SearchBar($container->get(TypesenseClientProvider::class)->isConfigured());
    }
}
