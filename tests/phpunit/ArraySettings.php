<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Omeka\Settings\SettingsInterface;

/** Omeka settings held in memory, for code that only reads and writes them. */
final class ArraySettings implements SettingsInterface
{
    /** @var array<string,mixed> */
    private array $values = [];

    public function set($id, $value): void
    {
        $this->values[(string) $id] = $value;
    }

    public function get($id, $default = null): mixed
    {
        return $this->values[(string) $id] ?? $default;
    }

    public function delete($id): void
    {
        unset($this->values[(string) $id]);
    }
}
