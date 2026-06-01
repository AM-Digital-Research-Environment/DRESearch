<?php
declare(strict_types=1);

namespace DRESearch\Search;

use Typesense\Client;

/**
 * Lazily constructs the Typesense client from resolved connection settings.
 *
 * The "Typesense is optional" guarantee lives here: with no host or API key
 * configured, {@see isConfigured()} is false and {@see getClient()} returns
 * null, so every caller (search proxy, reindex job, block render) can show a
 * graceful "search unavailable" state instead of fataling.
 *
 * A single API key is used for both search and indexing. That key is only ever
 * used server-side (the search proxy enforces is_public:=true and forwards
 * results) and never reaches the browser, so there's no need for a separate
 * search-only / scoped key the way a browser-direct architecture would require.
 */
final class TypesenseClientProvider
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $protocol,
        private readonly string $apiKey,
        private readonly string $collection,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->apiKey !== '';
    }

    /** Collection alias the search reads from / the reindexer swaps. */
    public function collection(): string
    {
        return $this->collection;
    }

    /**
     * Build a client, or null when Typesense isn't configured. Constructing the
     * client opens no connection — the first request does — so a null check
     * here plus try/catch at call sites is enough to stay non-fatal.
     */
    public function getClient(): ?Client
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            return new Client([
                'api_key' => $this->apiKey,
                'nodes'   => [[
                    'host'     => $this->host,
                    'port'     => (string) $this->port,
                    'protocol' => $this->protocol,
                ]],
                'connection_timeout_seconds' => 5,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }
}
