<?php
declare(strict_types=1);

namespace DRESearch\Service\BlockLayout;

use DRESearch\Search\SearchProxy;
use DRESearch\Settings\FacetConfig;
use DRESearch\Site\BlockLayout\DreSearchBlock;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class DreSearchBlockFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): DreSearchBlock
    {
        return new DreSearchBlock(
            $container->get(SearchProxy::class),
            $container->get(FacetConfig::class),
        );
    }
}
