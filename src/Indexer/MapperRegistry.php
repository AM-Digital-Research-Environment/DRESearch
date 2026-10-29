<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Closure;
use Doctrine\DBAL\Connection;
use DRESearch\Settings\SearchProfile;

/**
 * The one map from a profile `kind` to the mapper that indexes it.
 *
 * Why a registry instead of an if-chain in the reindexer: the kinds used to be
 * listed twice, once where the mapper was chosen and once in the profile
 * validation that rejects an unknown kind, and the two lists could drift (a
 * kind accepted by config but falling through to the research-item mapper, or
 * a new mapper whose kind config still refused). Now the profile validation
 * asks {@see has()} and the indexer asks {@see create()}, against the same
 * entries — adding a kind is one line in {@see default()}.
 *
 * Each entry is a factory rather than a mapper because a mapper is built per
 * profile (and, for research items, per run: a full rebuild loads every
 * authority once, an incremental batch resolves only the ones it links to).
 * A kind registered twice is a wiring bug and fails at construction, as the
 * IwacSearch registry does, instead of silently keeping the later entry.
 */
final class MapperRegistry
{
    private static ?self $default = null;

    /** @var array<string, Closure(SearchProfile, Connection, bool): MapperInterface> */
    private readonly array $factories;

    /**
     * @param iterable<array{0:string, 1:Closure(SearchProfile, Connection, bool): MapperInterface}> $entries
     *        kind => factory pairs; the factory receives the profile, the Omeka
     *        connection and whether the batch is targeted (incremental)
     */
    public function __construct(iterable $entries)
    {
        $factories = [];
        foreach ($entries as [$kind, $factory]) {
            if (!preg_match('/^[a-z][a-z_]*$/', $kind)) {
                throw new \LogicException(sprintf('Invalid mapper kind "%s".', $kind));
            }
            if (isset($factories[$kind])) {
                throw new \LogicException(sprintf('Mapper kind "%s" is registered twice.', $kind));
            }
            $factories[$kind] = $factory;
        }
        if ($factories === []) {
            throw new \LogicException('The mapper registry needs at least one kind.');
        }
        $this->factories = $factories;
    }

    /**
     * The module's kinds. Built once per process: the entries are stateless
     * factories, and every profile validation reads them.
     */
    public static function default(): self
    {
        return self::$default ??= new self([
            // Research items: shared-property facets disambiguated by the
            // authorities' item sets and types (see AuthorityResolver).
            ['item', static function (SearchProfile $profile, Connection $connection, bool $targeted): MapperInterface {
                $authorities = new AuthorityResolver($connection, $profile);
                if (!$targeted) {
                    // A full rebuild loads every tracked authority once; an
                    // incremental batch primes only the ones it links to.
                    $authorities->load();
                }
                return new ResearchItemMapper($authorities, $profile);
            }],
            ['project', static fn(SearchProfile $profile): MapperInterface => new ProjectMapper($profile)],
            ['publication', static fn(SearchProfile $profile): MapperInterface => new PublicationMapper($profile)],
            ['podcast', static fn(SearchProfile $profile): MapperInterface => new PodcastMapper($profile)],
            ['video', static fn(SearchProfile $profile): MapperInterface => new VideoMapper($profile)],
            ['section', static fn(SearchProfile $profile): MapperInterface => new SectionMapper($profile)],
            // People and organisations are the same reverse-link entity mapping
            // under two kinds (each picks its own card); authority terms add the
            // optional geopoint of the location corpora on top of it.
            ['person', static fn(SearchProfile $profile): MapperInterface => new ReverseLinkMapper($profile)],
            ['organisation', static fn(SearchProfile $profile): MapperInterface => new ReverseLinkMapper($profile)],
            ['term', static fn(SearchProfile $profile): MapperInterface => new TermMapper($profile)],
        ]);
    }

    public function has(string $kind): bool
    {
        return isset($this->factories[$kind]);
    }

    /** @return list<string> registered kinds, in registration order */
    public function kinds(): array
    {
        return array_keys($this->factories);
    }

    /**
     * @param bool $targeted incremental batches resolve only the authorities they
     *                       link to ({@see AuthorityResolver::prime()}); a full
     *                       rebuild loads them all once.
     */
    public function create(SearchProfile $profile, Connection $connection, bool $targeted = false): MapperInterface
    {
        $factory = $this->factories[$profile->kind()] ?? throw new \InvalidArgumentException(sprintf(
            'No mapper is registered for kind "%s" (profile "%s").',
            $profile->kind(),
            $profile->name(),
        ));
        return $factory($profile, $connection, $targeted);
    }
}
