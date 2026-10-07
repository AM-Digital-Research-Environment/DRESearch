<?php

declare(strict_types=1);

namespace DRESearch\Search;

use Typesense\Client;

/**
 * Lazily constructs the Typesense clients from resolved connection settings.
 *
 * The "Typesense is optional" guarantee lives here: with no host or API key
 * configured, {@see isConfigured()} is false and both getters return null, so
 * every caller (search proxy, reindex job, block render) can show a graceful
 * "search unavailable" state instead of fataling.
 *
 * Two clients share one connection but not one deadline. Public searches hold
 * a PHP worker while a visitor waits, so they give up quickly
 * ({@see getClient()}); imports of large documents (full texts, transcripts)
 * run in background jobs and may take longer ({@see getIndexClient()}).
 *
 * A single API key is used for both. It is only ever used server-side (the
 * search proxy enforces is_public:=true and forwards results) and never
 * reaches the browser.
 */
final class TypesenseClientProvider
{
    private ?Client $client = null;
    private bool $resolved = false;
    private ?Client $indexClient = null;
    private bool $indexResolved = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $protocol,
        private readonly string $apiKey,
        private readonly float $searchTimeout = 5.0,
        private readonly float $indexTimeout = 60.0,
        private readonly float $connectTimeout = 2.0,
    ) {
    }

    public function cacheNamespace(): string
    {
        return hash('sha256', $this->protocol . '://' . $this->host . ':' . $this->port . ':' . $this->apiKey);
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->apiKey !== '';
    }

    /**
     * The client for public, interactive requests, or null when Typesense isn't
     * configured. Constructing it opens no connection — the first request does —
     * so a null check plus try/catch at call sites is enough to stay non-fatal.
     */
    public function getClient(): ?Client
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $this->client = $this->build($this->searchTimeout);
        }
        return $this->client;
    }

    /** The client for rebuilds, drains and provisioning jobs. */
    public function getIndexClient(): ?Client
    {
        if (!$this->indexResolved) {
            $this->indexResolved = true;
            $this->indexClient = $this->build($this->indexTimeout);
        }
        return $this->indexClient;
    }

    private function build(float $timeout): ?Client
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
                // Deadlines live on the transport: the SDK's own timeout options
                // are not honoured by a caller-supplied PSR-18 client.
                'client' => new \GuzzleHttp\Client(['connect_timeout' => $this->connectTimeout, 'timeout' => $timeout]),
                'num_retries' => 0,
                'retry_interval_seconds' => 0.1,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }
}
