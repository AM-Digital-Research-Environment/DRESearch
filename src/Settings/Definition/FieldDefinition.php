<?php
declare(strict_types=1);

namespace DRESearch\Settings\Definition;

use InvalidArgumentException;

final class FieldDefinition
{
    private const TYPES = ['string', 'string[]', 'int32', 'int32[]', 'int64', 'int64[]', 'float', 'float[]', 'bool', 'bool[]', 'geopoint', 'geopoint[]'];

    private function __construct(
        private readonly ?string $property,
        private readonly string $type,
        private readonly bool $facet,
        private readonly bool $sort,
        private readonly bool $index,
        private readonly bool $searchOnly,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported Typesense field type "%s".', $type));
        }
        if (!$index && ($facet || $sort || $searchOnly)) {
            throw new InvalidArgumentException('A non-indexed field cannot be faceted, sorted, or search-only.');
        }
    }

    public static function fromArray(array $definition): self
    {
        return new self(
            isset($definition['property']) && $definition['property'] !== '' ? (string) $definition['property'] : null,
            (string) ($definition['type'] ?? 'string'),
            (bool) ($definition['facet'] ?? false),
            (bool) ($definition['sort'] ?? false),
            array_key_exists('index', $definition) ? (bool) $definition['index'] : true,
            (bool) ($definition['search_only'] ?? false),
        );
    }

    /** @return array{property:?string,type:string,facet:bool,sort:bool,index:bool,search_only:bool} */
    public function toArray(): array
    {
        return [
            'property' => $this->property,
            'type' => $this->type,
            'facet' => $this->facet,
            'sort' => $this->sort,
            'index' => $this->index,
            'search_only' => $this->searchOnly,
        ];
    }
}
