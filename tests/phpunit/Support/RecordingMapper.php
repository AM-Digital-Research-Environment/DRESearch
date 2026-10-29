<?php

declare(strict_types=1);

namespace DRESearch\Test\Support;

use DRESearch\Indexer\MapperInterface;
use DRESearch\Indexer\PreparesBatch;

/** Records what the assembler hands a mapper; maps to the id plus any fixed fields. */
final class RecordingMapper implements MapperInterface, PreparesBatch
{
    /** @var list<array<int,array<string,mixed>>> */
    public array $prepared = [];
    /** @var list<array{0:array<string,mixed>,1:array<string,mixed>,2:?string}> */
    public array $mapped = [];

    /** @param array<string,mixed> $fields */
    public function __construct(private readonly array $fields = [])
    {
    }

    public function prepare(array $valuesById): void
    {
        $this->prepared[] = $valuesById;
    }

    public function map(array $item, array $values, ?string $thumbnailUrl): array
    {
        $this->mapped[] = [$item, $values, $thumbnailUrl];
        return ['id' => (string) $item['id']] + $this->fields;
    }
}
