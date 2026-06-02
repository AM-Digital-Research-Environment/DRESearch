<?php
declare(strict_types=1);

namespace DRESearch\Service;

use DRESearch\Settings\FacetConfig;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds the FacetConfig from the merged `dre_search` config, so the entire
 * facet / index mapping is overridable via config/local.config.php.
 */
final class FacetConfigFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): FacetConfig
    {
        $config = $container->get('Config')['dre_search'] ?? [];
        return FacetConfig::fromArray($config);
    }
}
