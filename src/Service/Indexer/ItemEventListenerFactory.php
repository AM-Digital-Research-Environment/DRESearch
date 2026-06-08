<?php
declare(strict_types=1);

namespace DRESearch\Service\Indexer;

use DRESearch\Indexer\IncrementalIndexer;
use DRESearch\Indexer\ItemEventListener;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Trivial factory — the listener has one dependency. Kept as a dedicated class
 * for symmetry with the rest of the service layer.
 */
final class ItemEventListenerFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): ItemEventListener {
        return new ItemEventListener(
            indexer: $container->get(IncrementalIndexer::class)
        );
    }
}
