<?php

declare(strict_types=1);

namespace DRESearch\Search;

use Doctrine\DBAL\Connection;
use Laminas\Log\LoggerInterface;
use Omeka\Settings\SettingsInterface;

/**
 * The Typesense key public searches run with: least privilege for the proxy.
 *
 * The admin key stays for what needs it — indexing, rebuilds, health,
 * analytics provisioning and reading, maintenance — while every collection
 * search the public endpoints run uses a key limited to `documents:search` on
 * the module's collection aliases (anchored regexes, so retained generations
 * and analytics collections stay out of reach). It is server-side only, like
 * the admin key: DRE Search never hands a key to the browser.
 *
 * Resolution, first match wins:
 *   1. an operator-configured search-only key (the module's config form, else
 *      TYPESENSE_SEARCH_API_KEY), used as given — the maintenance page shows
 *      what Typesense says it may do;
 *   2. a key minted once with the admin key and cached in a settings slot
 *      named after its scope ({@see slot()}), so a changed alias list mints a
 *      fresh key and changing it back finds the old one again;
 *   3. the admin key. When minting fails, the failure is logged once, kept for
 *      the maintenance page, and retried after {@see RETRY_SECONDS}; search
 *      keeps working meanwhile.
 *
 * Minting runs under a MySQL advisory lock with a fresh read of the slot once
 * the lock is held (Omeka's settings service caches every value for the
 * request, so it cannot see a key another request has just minted): two first
 * searches must not create two keys, the loser's left orphaned in Typesense.
 * Ported from IwacSearch's TypesenseSearchKeyProvider.
 */
final class TypesenseSearchKeyProvider
{
    /** The operator-configured key (config form). */
    public const SETTING = 'dre_search_typesense_search_key';
    /** Minted keys live under this prefix plus a hash of their scope. */
    public const SLOT_PREFIX = 'dre_search_minted_search_key_';
    /** The last minting failure, for the maintenance page: {at, message, scope}. */
    public const FAILURE = 'dre_search_search_key_failure';
    /** Typesense's description of a key this module minted; revocation matches on it. */
    public const DESCRIPTION = 'DRESearch public search-only key (auto-created)';
    /** After a failed mint, the admin key serves this long before the next attempt. */
    public const RETRY_SECONDS = 300;

    private const LOCK = 'dre_search:search_key';

    private ?string $resolved = null;
    private bool $done = false;

    /**
     * @param \Closure():(object|null) $adminClient the admin-key Typesense client, or null when unconfigured
     * @param list<string> $aliases the collection aliases public search reads
     * @param string $configured an operator-configured search-only key, or ''
     */
    public function __construct(
        private readonly \Closure $adminClient,
        private readonly SettingsInterface $settings,
        private readonly LoggerInterface $logger,
        private readonly array $aliases,
        private readonly string $configured = '',
        private readonly ?Connection $connection = null,
    ) {
    }

    /**
     * The search-only key, or null to search with the admin key (no key
     * configured and none could be minted, or no alias to scope one to).
     */
    public function key(): ?string
    {
        if (!$this->done) {
            $this->done = true;
            $this->resolved = $this->resolve();
        }
        return $this->resolved;
    }

    /**
     * Forget a minted key Typesense refused (deleted by hand, or a volume
     * reset): the next request mints a new one. An operator-configured key is
     * the operator's to fix; it is left alone.
     */
    public function rejected(): void
    {
        if ($this->configured !== '' || $this->resolved === null) {
            return;
        }
        $this->logger->warn('DRESearch: Typesense refused the minted search-only key; a new one will be minted', [
            'scope' => $this->scope(),
        ]);
        $this->forget($this->slot());
        $this->resolved = null;
    }

    /**
     * What public search uses, for the maintenance page. Resolves the key
     * (minting it if needed) and asks Typesense, with the admin key, what the
     * key in use may do. `problem` is null or one of: `lookup_failed` (with
     * `detail`), `gone` (a minted key Typesense no longer has; forgotten, so the
     * next search mints another), `unmatched` (no single key matches the
     * configured one), `too_broad` (the key may do more than search).
     *
     * @return array{source:string, scope:list<string>, actions:?list<string>, collections:?list<string>,
     *     problem:?string, detail:?string, failure:?array{at:string,message:string}}
     */
    public function status(): array
    {
        $key = $this->key();
        $source = $key === null ? 'admin' : ($this->configured !== '' ? 'configured' : 'minted');
        $status = [
            'source' => $source,
            'scope' => $this->scope(),
            'actions' => null,
            'collections' => null,
            'problem' => null,
            'detail' => null,
            'failure' => $source === 'admin' ? $this->failure() : null,
        ];
        if ($key === null) {
            return $status;
        }
        try {
            $match = $this->lookup($key, $source === 'minted');
        } catch (\Throwable $error) {
            $status['problem'] = 'lookup_failed';
            $status['detail'] = $error->getMessage();
            return $status;
        }
        if ($match === null) {
            $status['problem'] = $source === 'minted' ? 'gone' : 'unmatched';
            if ($source === 'minted') {
                $this->forget($this->slot());
            }
            return $status;
        }
        $status['actions'] = array_values(array_map('strval', (array) ($match['actions'] ?? [])));
        $status['collections'] = array_values(array_map('strval', (array) ($match['collections'] ?? [])));
        if ($status['actions'] !== ['documents:search']) {
            $status['problem'] = 'too_broad';
        }
        return $status;
    }

    /**
     * Uninstall: revoke every key this install minted and delete its slots and
     * the failure note. A key is revoked only when exactly one listed key
     * carries both the slot value's prefix and this module's description —
     * never an operator's key, and never an ambiguous match that could be
     * another install's.
     *
     * @param \Closure():(object|null) $adminClient
     * @return int keys revoked
     */
    public static function revokeMinted(Connection $connection, \Closure $adminClient, ?LoggerInterface $logger = null): int
    {
        $rows = $connection->executeQuery(
            'SELECT id, value FROM setting WHERE id LIKE ?',
            [addcslashes(self::SLOT_PREFIX, '_%') . '%'],
        )->fetchAllAssociative();
        $revoked = 0;
        if ($rows !== []) {
            try {
                $client = $adminClient();
                // @phpstan-ignore property.notFound (a Typesense\Client, duck-typed so tests can fake it)
                $listed = $client !== null ? ($client->keys->retrieve()['keys'] ?? []) : [];
                foreach ($rows as $row) {
                    $value = json_decode((string) $row['value'], true);
                    $match = is_string($value) ? self::match(is_array($listed) ? $listed : [], $value, true) : null;
                    if ($client !== null && $match !== null) {
                        // @phpstan-ignore property.notFound
                        $client->keys[(string) $match['id']]->delete();
                        $revoked++;
                    }
                }
            } catch (\Throwable $error) {
                $logger?->warn('DRESearch: could not revoke the minted search-only key: ' . $error->getMessage());
            }
        }
        $connection->executeStatement(
            'DELETE FROM setting WHERE id LIKE ? OR id = ?',
            [addcslashes(self::SLOT_PREFIX, '_%') . '%', self::FAILURE],
        );
        return $revoked;
    }

    /**
     * The key collections a public search may read: each alias, anchored, so
     * `dre_research_current` never matches a generation or an analytics
     * collection that merely starts with it.
     *
     * @return list<string>
     */
    public function scope(): array
    {
        // Aliases are [A-Za-z0-9_-]+ (SearchProfile), nothing a regex reads
        // as other than itself.
        $scope = array_values(array_unique(array_map(
            static fn(string $alias): string => '^' . $alias . '$',
            $this->aliases,
        )));
        sort($scope);
        return $scope;
    }

    /** The settings slot of the key minted for the current scope. */
    public function slot(): string
    {
        return self::SLOT_PREFIX . substr(hash('sha256', implode(',', $this->scope())), 0, 12);
    }

    private function resolve(): ?string
    {
        if ($this->configured !== '') {
            return $this->configured;
        }
        if ($this->scope() === []) {
            return null;
        }
        $cached = $this->settings->get($this->slot());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $failure = $this->failure();
        if ($failure !== null && ($failure['scope'] ?? null) === $this->slot() && time() - strtotime($failure['at'] . ' UTC') < self::RETRY_SECONDS) {
            return null;
        }
        try {
            return $this->mintOnce();
        } catch (\Throwable $error) {
            $this->logger->err('DRESearch: could not mint the search-only Typesense key; public search uses the admin key', [
                'exception' => $error::class,
                'message' => $error->getMessage(),
                'scope' => $this->scope(),
            ]);
            $this->remember(self::FAILURE, [
                'at' => gmdate('Y-m-d H:i:s'),
                'message' => mb_substr($error->getMessage(), 0, 500),
                'scope' => $this->slot(),
            ]);
            return null;
        }
    }

    /** Mint under the lock, unless another request did meanwhile. */
    private function mintOnce(): string
    {
        if ($this->connection === null) {
            return $this->mint();
        }
        $held = (int) $this->connection->executeQuery('SELECT GET_LOCK(?, 10)', [self::LOCK])->fetchOne() === 1;
        if (!$held) {
            throw new \RuntimeException('Another request held the key-minting lock for 10 seconds.');
        }
        try {
            $fresh = $this->connection->executeQuery('SELECT value FROM setting WHERE id = ?', [$this->slot()])->fetchOne();
            $value = is_string($fresh) ? json_decode($fresh, true) : null;
            return is_string($value) && $value !== '' ? $value : $this->mint();
        } finally {
            $this->connection->executeQuery('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    private function mint(): string
    {
        $client = ($this->adminClient)();
        if ($client === null) {
            throw new \RuntimeException('Typesense is not configured.');
        }
        // @phpstan-ignore property.notFound (a Typesense\Client, duck-typed so tests can fake it)
        $response = $client->keys->create([
            'description' => self::DESCRIPTION,
            // The only action: a key that could also write would make a proxy
            // bug a write path.
            'actions' => ['documents:search'],
            'collections' => $this->scope(),
        ]);
        $value = is_array($response) ? ($response['value'] ?? null) : null;
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('Typesense returned no key value.');
        }
        // Typesense never shows a key's value again: keep it now.
        $this->remember($this->slot(), $value);
        $this->forget(self::FAILURE);
        $this->logger->info('DRESearch: minted the search-only Typesense key', [
            'key_id' => is_array($response) ? ($response['id'] ?? null) : null,
            'scope' => $this->scope(),
        ]);
        return $value;
    }

    /**
     * The listed key the value belongs to, or null when none or several do.
     *
     * @return array<string,mixed>|null
     */
    private function lookup(string $key, bool $minted): ?array
    {
        $client = ($this->adminClient)();
        // @phpstan-ignore property.notFound
        $listed = $client !== null ? ($client->keys->retrieve()['keys'] ?? []) : [];
        return self::match(is_array($listed) ? $listed : [], $key, $minted);
    }

    /**
     * @param array<mixed> $listed Typesense's key listing
     * @return array<string,mixed>|null
     */
    private static function match(array $listed, string $value, bool $minted): ?array
    {
        $matches = array_values(array_filter(
            $listed,
            static fn(mixed $key): bool => is_array($key)
                && ($key['value_prefix'] ?? null) === substr($value, 0, 4)
                && (!$minted || ($key['description'] ?? null) === self::DESCRIPTION),
        ));
        return count($matches) === 1 && isset($matches[0]['id']) ? $matches[0] : null;
    }

    /** @return array{at:string,message:string,scope?:string}|null */
    private function failure(): ?array
    {
        $failure = $this->settings->get(self::FAILURE);
        return is_array($failure) && is_string($failure['at'] ?? null) && is_string($failure['message'] ?? null)
            ? $failure
            : null;
    }

    private function remember(string $id, mixed $value): void
    {
        try {
            $this->settings->set($id, $value);
        } catch (\Throwable $error) {
            $this->logger->err('DRESearch: could not save a search-key setting: ' . $error->getMessage());
        }
    }

    private function forget(string $id): void
    {
        try {
            $this->settings->delete($id);
            // Omeka's settings cache may not know a slot another request wrote.
            $this->connection?->executeStatement('DELETE FROM setting WHERE id = ?', [$id]);
        } catch (\Throwable) {
            // A stale slot is retried, not fatal.
        }
    }
}
