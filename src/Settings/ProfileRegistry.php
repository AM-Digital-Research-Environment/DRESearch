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
        $collections = [];
        foreach ($profilesConfig as $name => $config) {
            if (!is_array($config)) {
                throw new \InvalidArgumentException(sprintf('Search profile "%s" must be an object.', (string) $name));
            }
            $profile = SearchProfile::fromArray((string) $name, $config);
            if (isset($collections[$profile->collection()])) {
                throw new \InvalidArgumentException(sprintf(
                    'Profiles "%s" and "%s" use the same collection alias "%s".',
                    $collections[$profile->collection()],
                    $profile->name(),
                    $profile->collection(),
                ));
            }
            $collections[$profile->collection()] = $profile->name();
            $profiles[$profile->name()] = $profile;
        }
        if ($profiles === []) {
            throw new \InvalidArgumentException('At least one DRE Search profile must be configured.');
        }
        return new self($profiles);
    }

    public function has(string $name): bool
    {
        return isset($this->profiles[$name]);
    }

    /**
     * Resolve a profile. Only null/empty means "default"; an explicit unknown
     * name is rejected so jobs and public requests can never rebuild/search the
     * first corpus by accident.
     */
    public function get(?string $name): ?SearchProfile
    {
        if ($name !== null && $name !== '') {
            return $this->profiles[$name] ?? null;
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
