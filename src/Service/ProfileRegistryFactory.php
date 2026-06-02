<?php
declare(strict_types=1);

namespace DRESearch\Service;

use DRESearch\Settings\ProfileRegistry;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds the ProfileRegistry from `dre_search.profiles`, so every search corpus
 * (and its facet / index mapping) is overridable via config/local.config.php.
 */
final class ProfileRegistryFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ProfileRegistry
    {
        $profiles = $container->get('Config')['dre_search']['profiles'] ?? [];
        return ProfileRegistry::fromArray($profiles);
    }
}
