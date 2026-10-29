<?php

declare(strict_types=1);

namespace DRESearch\Test\Support\Typesense;

use Typesense\Alias;
use Typesense\Exceptions\ObjectNotFound;

/** @internal One alias of {@see FakeTypesense}. */
final class FakeAlias extends Alias
{
    public function __construct(private readonly FakeTypesense $server, private readonly string $name)
    {
    }

    /** @return array{name:string,collection_name:string} */
    public function retrieve(): array
    {
        if ($this->server->aliasReadFailure !== null) {
            throw $this->server->aliasReadFailure;
        }
        if (!isset($this->server->aliases[$this->name])) {
            throw new ObjectNotFound('Not found: no alias named ' . $this->name);
        }
        return ['name' => $this->name, 'collection_name' => $this->server->aliases[$this->name]];
    }

    /** @return array{name:string} */
    public function delete(): array
    {
        if (!isset($this->server->aliases[$this->name])) {
            throw new ObjectNotFound('Not found: no alias named ' . $this->name);
        }
        unset($this->server->aliases[$this->name]);
        return ['name' => $this->name];
    }
}
