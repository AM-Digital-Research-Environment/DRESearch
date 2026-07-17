<?php
declare(strict_types=1);

namespace DRESearch\Settings\Definition;

use InvalidArgumentException;

final class SourceScope
{
    /** @param list<array{template_id:?int,item_set_id:?int,require_property:?string}> $extraSources */
    private function __construct(
        private readonly ?int $templateId,
        private readonly ?int $itemSetId,
        private readonly array $extraSources,
    ) {
        if ($templateId !== null && $templateId <= 0) {
            throw new InvalidArgumentException('template_id must be a positive integer.');
        }
        if ($itemSetId !== null && $itemSetId <= 0) {
            throw new InvalidArgumentException('item_set_id must be a positive integer.');
        }
        if ($templateId === null && $itemSetId === null) {
            throw new InvalidArgumentException('A profile must define template_id or item_set_id.');
        }
        foreach ($extraSources as $source) {
            if ($source['template_id'] === null && $source['item_set_id'] === null) {
                throw new InvalidArgumentException('Every extra source must define template_id or item_set_id.');
            }
            if (($source['template_id'] ?? 1) <= 0 || ($source['item_set_id'] ?? 1) <= 0) {
                throw new InvalidArgumentException('Extra source ids must be positive integers.');
            }
            $term = $source['require_property'];
            if ($term !== null && !preg_match('/^[A-Za-z][A-Za-z0-9._-]*:[A-Za-z][A-Za-z0-9._-]*$/', $term)) {
                throw new InvalidArgumentException(sprintf('Invalid extra-source property term "%s".', $term));
            }
        }
    }

    public static function fromArray(array $config): self
    {
        $extra = [];
        $configuredExtra = $config['extra_sources'] ?? [];
        if (!is_array($configuredExtra)) {
            throw new InvalidArgumentException('extra_sources must be an array.');
        }
        foreach ($configuredExtra as $source) {
            if (!is_array($source)) {
                throw new InvalidArgumentException('Every extra source must be an object.');
            }
            $extra[] = [
                'template_id' => isset($source['template_id']) ? (int) $source['template_id'] : null,
                'item_set_id' => isset($source['item_set_id']) ? (int) $source['item_set_id'] : null,
                'require_property' => isset($source['require_property']) && $source['require_property'] !== ''
                    ? (string) $source['require_property']
                    : null,
            ];
        }
        return new self(
            isset($config['template_id']) && $config['template_id'] !== null ? (int) $config['template_id'] : null,
            isset($config['item_set_id']) && $config['item_set_id'] !== null ? (int) $config['item_set_id'] : null,
            $extra,
        );
    }

    public function templateId(): ?int { return $this->templateId; }
    public function itemSetId(): ?int { return $this->itemSetId; }
    /** @return list<array{template_id:?int,item_set_id:?int,require_property:?string}> */
    public function extraSources(): array { return $this->extraSources; }
}
