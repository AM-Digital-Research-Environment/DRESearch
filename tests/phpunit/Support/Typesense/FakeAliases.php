<?php

declare(strict_types=1);

namespace DRESearch\Test\Support\Typesense;

use Typesense\Alias;
use Typesense\Aliases;

/** @internal {@see FakeTypesense}'s `$client->aliases`. */
final class FakeAliases extends Aliases
{
    public function __construct(private readonly FakeTypesense $server)
    {
    }

    /**
     * @param array<string,mixed> $mapping
     * @return array<string,mixed>
     */
    public function upsert(string $name, array $mapping): array
    {
        $failure = $this->server->aliasWriteFailure;
        if ($failure === null || $this->server->aliasWriteApplied) {
            $this->server->aliases[$name] = (string) $mapping['collection_name'];
        }
        if ($failure !== null) {
            throw $failure;
        }
        return ['name' => $name] + $mapping;
    }

    /** @return array{aliases:list<array{name:string,collection_name:string}>} */
    public function retrieve(): array
    {
        if ($this->server->aliasListFailure !== null) {
            throw $this->server->aliasListFailure;
        }
        $aliases = [];
        foreach ($this->server->aliases as $name => $collection) {
            $aliases[] = ['name' => (string) $name, 'collection_name' => $collection];
        }
        return ['aliases' => $aliases];
    }

    public function offsetGet($offset): Alias
    {
        return new FakeAlias($this->server, (string) $offset);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->server->aliases[(string) $offset]);
    }

    public function offsetSet($offset, $value): void
    {
    }

    public function offsetUnset($offset): void
    {
    }
}
