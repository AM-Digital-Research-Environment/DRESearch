<?php

declare(strict_types=1);

namespace DRESearch\Service\BlockLayout;

use DRESearch\Search\SearchProxy;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Site\BlockLayout\AbstractSearchBlock;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/** One factory for all profile-bound block layout subclasses. */
final class SearchBlockFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): AbstractSearchBlock
    {
        $class = \DRESearch\Settings\BlockProfiles::CLASSES[(string) $requestedName] ?? null;
        if ($class === null) {
            throw new \InvalidArgumentException(sprintf('Unknown DRE Search block layout "%s".', (string) $requestedName));
        }
        return new $class(
            $container->get(SearchProxy::class),
            $container->get(ProfileRegistry::class),
        );
    }
}
