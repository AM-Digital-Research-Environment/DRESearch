<?php

declare(strict_types=1);

namespace DRESearch\Test\Support\Typesense;

use Typesense\Collection;
use Typesense\Exceptions\ObjectNotFound;

/** @internal One collection of {@see FakeTypesense}. */
final class FakeCollection extends Collection
{
    public function __construct(private readonly FakeTypesense $server, private readonly string $name)
    {
        $this->documents = new FakeDocuments($server, $name);
    }

    /** @return array{name:string,num_documents:int} */
    public function retrieve(): array
    {
        if (!isset($this->server->collections[$this->name])) {
            throw new ObjectNotFound('Not found: no collection named ' . $this->name);
        }
        return ['name' => $this->name, 'num_documents' => count($this->server->collections[$this->name])];
    }

    /** @return array{name:string} */
    public function delete(): array
    {
        if ($this->server->dropFailure !== null) {
            throw $this->server->dropFailure;
        }
        if (!isset($this->server->collections[$this->name])) {
            throw new ObjectNotFound('Not found: no collection named ' . $this->name);
        }
        unset($this->server->collections[$this->name]);
        $this->server->dropped[] = $this->name;
        return ['name' => $this->name];
    }
}
