<?php
declare(strict_types=1);

namespace DRESearch\Settings\Definition;

use InvalidArgumentException;

final class SortDefinition
{
    private function __construct(
        private readonly string $field,
        private readonly string $direction,
        private readonly string $label,
    ) {
        if ($field === '') {
            throw new InvalidArgumentException('A sort definition must name a field.');
        }
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('A sort direction must be asc or desc.');
        }
    }

    public static function fromArray(string $key, array $definition): self
    {
        return new self(
            (string) ($definition['field'] ?? ''),
            strtolower((string) ($definition['dir'] ?? 'desc')),
            (string) ($definition['label'] ?? $key),
        );
    }

    /** @return array{field:string,dir:string,label:string} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'dir' => $this->direction, 'label' => $this->label];
    }
}
