<?php

declare(strict_types=1);

namespace DRESearch\Service;

use DRESearch\Search\CorpusCounts;
use DRESearch\Settings\ProfileRegistry;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class CorpusCountsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CorpusCounts
    {
        $connection = $container->get('Omeka\\Connection');
        return new CorpusCounts(
            static fn (string $sql, array $params) => $connection->executeQuery($sql, $params)->fetchOne(),
            $container->get(ProfileRegistry::class),
        );
    }
}
