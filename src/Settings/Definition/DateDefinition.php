<?php
declare(strict_types=1);

namespace DRESearch\Settings\Definition;

use InvalidArgumentException;

final class DateDefinition
{
    private function __construct(
        private readonly string $mode,
        private readonly ?string $property,
        private readonly string $label,
        private readonly bool $facet,
    ) {
        if (!in_array($mode, ['none', 'single', 'range'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported date mode "%s".', $mode));
        }
        if ($mode !== 'none' && ($property === null || $property === '')) {
            throw new InvalidArgumentException('A dated profile must define date.property.');
        }
        if ($mode === 'none' && $facet) {
            throw new InvalidArgumentException('A profile without a date cannot expose a year facet.');
        }
    }

    public static function fromArray(array $config): self
    {
        return new self(
            (string) ($config['mode'] ?? 'single'),
            isset($config['property']) && $config['property'] !== '' ? (string) $config['property'] : null,
            (string) ($config['label'] ?? 'Year'),
            (bool) ($config['facet'] ?? false),
        );
    }

    /** @return array{mode:string,property:?string,label:string,facet:bool} */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'property' => $this->property, 'label' => $this->label, 'facet' => $this->facet];
    }
}
