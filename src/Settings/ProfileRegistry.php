<?php
declare(strict_types=1);

namespace DRESearch\Settings;

/**
 * The set of search profiles (corpora) the module exposes, built from
 * `dre_search.profiles` config. The first profile is the default — used when a
 * request or block omits an explicit profile name (back-compat with the
 * single-corpus era, where everything was "research items").
 */
final class ProfileRegistry
{
    /** @var array<string,SearchProfile> */
    private readonly array $profiles;

    /** @param array<string,SearchProfile> $profiles */
    public function __construct(array $profiles)
    {
        $this->profiles = $profiles;
    }

    public static function fromArray(array $profilesConfig): self
    {
        $profiles = [];
        foreach ($profilesConfig as $name => $config) {
            if (is_array($config)) {
                $profiles[(string) $name] = SearchProfile::fromArray((string) $name, $config);
            }
        }
        return new self($profiles);
    }

    public function has(string $name): bool
    {
        return isset($this->profiles[$name]);
    }

    /** Resolve a profile by name, falling back to the default profile. */
    public function get(?string $name): ?SearchProfile
    {
        if ($name !== null && $name !== '' && isset($this->profiles[$name])) {
            return $this->profiles[$name];
        }
        return $this->default();
    }

    public function default(): ?SearchProfile
    {
        foreach ($this->profiles as $profile) {
            return $profile;
        }
        return null;
    }

    /** @return array<string,SearchProfile> */
    public function all(): array
    {
        return $this->profiles;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->profiles);
    }
}
