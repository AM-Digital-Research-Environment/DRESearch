<?php
declare(strict_types=1);

namespace DRESearch\Service\View\Helper;

use DRESearch\Search\SearchProxy;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\View\Helper\FederatedSearch;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class FederatedSearchFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): FederatedSearch
    {
        return new FederatedSearch(
            $container->get(SearchProxy::class),
            $container->get(ProfileRegistry::class),
        );
    }
}
