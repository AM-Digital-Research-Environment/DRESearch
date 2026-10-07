<?php

declare(strict_types=1);

namespace DRESearch\View;

/**
 * Reads Vite's build manifest (asset/dist/manifest.json) to find the hashed
 * chunks a page will import, so they can be preloaded from the head instead of
 * being discovered one round trip at a time.
 *
 * Keys are source paths ("src/svelte/App.svelte") or, for shared chunks,
 * "_<file>"; each entry lists its output `file`, its static `imports` (other
 * keys) and the `css` files it brings. A missing or malformed manifest yields
 * an empty one: the bundle still loads, only without preload hints.
 */
final class BundleManifest
{
    /** @var array<string, self> */
    private static array $loaded = [];

    /** @param array<string, array{file: string, imports: list<string>, css: list<string>}> $chunks */
    private function __construct(private readonly array $chunks)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!isset(self::$loaded[$path])) {
            $raw = is_readable($path) ? file_get_contents($path) : false;
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            self::$loaded[$path] = self::fromArray(is_array($decoded) ? $decoded : []);
        }
        return self::$loaded[$path];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $chunks = [];
        foreach ($data as $key => $chunk) {
            if (!is_string($key) || !is_array($chunk) || !is_string($chunk['file'] ?? null)) {
                continue;
            }
            $chunks[$key] = [
                'file'    => $chunk['file'],
                'imports' => array_values(array_filter((array) ($chunk['imports'] ?? []), 'is_string')),
                'css'     => array_values(array_filter((array) ($chunk['css'] ?? []), 'is_string')),
            ];
        }
        return new self($chunks);
    }

    public function has(string $key): bool
    {
        return isset($this->chunks[$key]);
    }

    /**
     * The JS files `$key` needs before it can run: its own file (unless
     * `$includeSelf` is false, e.g. for the entry the page already loads) and
     * every chunk it statically imports, transitively.
     *
     * @return list<string> Paths relative to asset/dist.
     */
    public function modules(string $key, bool $includeSelf = true): array
    {
        $files = [];
        foreach ($this->closure($key) as $chunk) {
            if ($includeSelf || $chunk !== $key) {
                $files[] = $this->chunks[$chunk]['file'];
            }
        }
        return array_values(array_unique($files));
    }

    /**
     * The stylesheets `$key` and its static imports bring.
     *
     * @return list<string> Paths relative to asset/dist.
     */
    public function styles(string $key): array
    {
        $files = [];
        foreach ($this->closure($key) as $chunk) {
            array_push($files, ...$this->chunks[$chunk]['css']);
        }
        return array_values(array_unique($files));
    }

    /** @return list<string> `$key` followed by its static imports, depth first, each once. */
    private function closure(string $key): array
    {
        $seen = [];
        $stack = [$key];
        while ($stack !== []) {
            $current = array_shift($stack);
            if (isset($seen[$current]) || !isset($this->chunks[$current])) {
                continue;
            }
            $seen[$current] = true;
            array_unshift($stack, ...$this->chunks[$current]['imports']);
        }
        return array_keys($seen);
    }

    /** For tests: forget manifests read from disk. */
    public static function reset(): void
    {
        self::$loaded = [];
    }
}
