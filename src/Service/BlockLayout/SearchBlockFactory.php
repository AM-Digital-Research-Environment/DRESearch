<?php

declare(strict_types=1);

namespace DRESearch\Service\BlockLayout;

use DRESearch\Search\SearchProxy;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Site\BlockLayout\SearchBlock;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds the search block for every registered layout name
 * ({@see \DRESearch\Settings\BlockProfiles}): one class, bound to the profile
 * the name stands for.
 */
final class SearchBlockFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SearchBlock
    {
        return new SearchBlock(
            $container->get(SearchProxy::class),
            $container->get(ProfileRegistry::class),
            (string) $requestedName,
        );
    }
}
