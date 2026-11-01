<?php

declare(strict_types=1);

namespace DRESearch\Test;

/**
 * Typesense's keys API for {@see \DRESearch\Search\TypesenseSearchKeyProvider}:
 * create() mints `minted-N` (or throws `$failure`), retrieve() lists `$listed`.
 */
final class FakeTypesenseKeys
{
    /** @var list<array<string,mixed>> what was minted */
    public array $created = [];
    /** @var list<array<string,mixed>> every create() call, failed or not */
    public array $attempts = [];
    /** @var list<array<string,mixed>> */
    public array $listed = [];

    public function __construct(public ?\Throwable $failure = null)
    {
    }

    /**
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    public function create(array $schema): array
    {
        $this->attempts[] = $schema;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->created[] = $schema;
        return ['id' => count($this->created), 'value' => 'minted-' . count($this->created)];
    }

    /** @return array{keys:list<array<string,mixed>>} */
    public function retrieve(): array
    {
        return ['keys' => $this->listed];
    }
}
