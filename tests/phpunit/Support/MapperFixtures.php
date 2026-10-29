<?php

declare(strict_types=1);

namespace DRESearch\Test\Support;

use DRESearch\Settings\SearchProfile;

/**
 * Value rows in the shape {@see \DRESearch\Indexer\OmekaSourceRepository::loadValues()}
 * returns them, and the profiles the module ships, for mapper tests that map
 * exactly what the indexer would hand a mapper.
 */
trait MapperFixtures
{
    /** A shipped profile from config/module.config.php. */
    private function configProfile(string $name): SearchProfile
    {
        $config = require dirname(__DIR__, 3) . '/config/module.config.php';
        return SearchProfile::fromArray($name, $config['dre_search']['profiles'][$name]);
    }

    /** @return array{vrid:?int,value:?string,uri:?string,title:?string} */
    private function literal(string $value): array
    {
        return ['vrid' => null, 'value' => $value, 'uri' => null, 'title' => null];
    }

    /**
     * A link to another resource, its public title already resolved.
     *
     * @return array{vrid:?int,value:?string,uri:?string,title:?string}
     */
    private function link(int $id, string $title): array
    {
        return ['vrid' => $id, 'value' => null, 'uri' => null, 'title' => $title];
    }

    /**
     * A URI value with its label.
     *
     * @return array{vrid:?int,value:?string,uri:?string,title:?string}
     */
    private function uri(string $uri, ?string $label = null): array
    {
        return ['vrid' => null, 'value' => $label ?? $uri, 'uri' => $uri, 'title' => null];
    }

    /**
     * The $item a mapper receives.
     *
     * @param array<string,mixed> $extra item_count, counts, roles
     * @return array{id:int, title:string, is_public:bool, item_count?:int, counts?:array<string,int>, roles?:list<string>}
     */
    private function record(int $id, string $title, array $extra = []): array
    {
        /** @var array{id:int, title:string, is_public:bool, item_count?:int, counts?:array<string,int>, roles?:list<string>} */
        return ['id' => $id, 'is_public' => true, 'title' => $title] + $extra;
    }
}
