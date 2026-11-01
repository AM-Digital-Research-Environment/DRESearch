<?php

declare(strict_types=1);

namespace DRESearch\Service\Search;

use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Search\TypesenseSearchKeyProvider;
use DRESearch\Settings\ProfileRegistry;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * The search-only key for public search, scoped to every profile's collection
 * alias. An operator's key comes from the module's config form, else the
 * TYPESENSE_SEARCH_API_KEY environment variable (as the admin key does from
 * TYPESENSE_API_KEY); without one the provider mints a key with the admin
 * client.
 */
final class TypesenseSearchKeyProviderFactory implements FactoryInterface
{
    public const ENV = 'TYPESENSE_SEARCH_API_KEY';

    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): TypesenseSearchKeyProvider
    {
        $settings = $container->get('Omeka\Settings');
        $configured = (string) $settings->get(TypesenseSearchKeyProvider::SETTING, '');
        if ($configured === '') {
            $env = getenv(self::ENV);
            $configured = $env !== false ? trim((string) $env) : '';
        }
        $aliases = array_values(array_map(
            static fn($profile): string => $profile->collection(),
            $container->get(ProfileRegistry::class)->all(),
        ));
        return new TypesenseSearchKeyProvider(
            static fn(): ?object => $container->get(TypesenseClientProvider::class)->getClient(),
            $settings,
            $container->get('Omeka\Logger'),
            $aliases,
            $configured,
            $container->get('Omeka\Connection'),
        );
    }
}
