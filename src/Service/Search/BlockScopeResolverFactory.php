<?php

declare(strict_types=1);

namespace DRESearch\Service\Search;

use DRESearch\Indexer\OmekaSourceRepository;
use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\ResourceScopeResolver;
use DRESearch\Settings\ProfileRegistry;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class BlockScopeResolverFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): BlockScopeResolver
    {
        $connection = $container->get('Omeka\Connection');
        $profile = $container->get(ProfileRegistry::class)->get(ResourceScopeResolver::PROFILE);

        $resources = new ResourceScopeResolver(
            // Through the API, so the current user's visibility applies: a
            // private project is "not found" to a visitor.
            static function (int $id) use ($container): ?array {
                try {
                    $item = $container->get('Omeka\ApiManager')->read('items', $id)->getContent();
                } catch (\Omeka\Api\Exception\NotFoundException | \Omeka\Api\Exception\PermissionDeniedException) {
                    return null;
                }
                if (!$item instanceof \Omeka\Api\Representation\ItemRepresentation) {
                    return null;
                }
                return [
                    'class' => $item->resourceClass()?->term(),
                    'item_sets' => array_map('intval', array_keys($item->itemSets())),
                ];
            },
            // The title the indexer writes into project_s, by the same code.
            static function (int $id) use ($connection, $profile): string {
                if ($profile === null) {
                    return '';
                }
                return (new OmekaSourceRepository($connection, $profile))->publicTitles([$id])[$id] ?? '';
            },
            $profile?->itemSet('project'),
        );

        return new BlockScopeResolver($connection, $resources);
    }
}
