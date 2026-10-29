<?php

declare(strict_types=1);

namespace DRESearch\Test\Support\Typesense;

use Typesense\Collection;
use Typesense\Collections;

/** @internal {@see FakeTypesense}'s `$client->collections`. */
final class FakeCollections extends Collections
{
    public function __construct(private readonly FakeTypesense $server)
    {
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function create(array $schema, array $options = []): array
    {
        $name = (string) ($schema['name'] ?? '');
        if ($name === '' || isset($this->server->collections[$name])) {
            throw new \RuntimeException('Cannot create collection "' . $name . '".');
        }
        $this->server->collections[$name] = [];
        return $schema;
    }

    /** @return list<array{name:string,num_documents:int}> */
    public function retrieve(): array
    {
        $out = [];
        foreach ($this->server->collections as $name => $docs) {
            $out[] = ['name' => (string) $name, 'num_documents' => count($docs)];
        }
        return $out;
    }

    public function offsetGet($offset): Collection
    {
        return new FakeCollection($this->server, (string) $offset);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->server->collections[(string) $offset]);
    }

    public function offsetSet($offset, $value): void
    {
    }

    public function offsetUnset($offset): void
    {
    }
}
