<?php

declare(strict_types=1);

namespace DRESearch\Search;

/**
 * Every Typesense search the proxy runs goes through here, so they all share
 * one transport (POST /multi_search) and one fallback for a missing stopword
 * or synonym set (a fresh Typesense volume before the first reindex).
 *
 * Single searches are sent as a one-entry multi_search on purpose: the
 * collection search endpoint is a GET, and Typesense rejects query strings over
 * 4,000 bytes — which a long non-Latin query (a pasted Amharic or Arabic title
 * is ~9 bytes per character once percent-encoded), many filter chips, or the
 * pending-change exclusion list all exceed. A POST body has no such limit.
 */
final class SearchExecutor
{
    /**
     * Request-level parameters Typesense reads from the multi_search query
     * string rather than from an individual search in the body.
     */
    private const QUERY_LEVEL = ['x-typesense-user-id'];
    /** Bytes of query string kept under Typesense's 4,000-byte GET limit. */
    private const GET_BUDGET = 3500;

    /**
     * @param object $client  a Typesense\Client (duck-typed for tests)
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function single(object $client, string $collection, array $params): array
    {
        // A deliberate query recorded for analytics keeps the collection search
        // endpoint the analytics rules were provisioned against, whenever its
        // query string fits under Typesense's GET limit; otherwise it is sent as
        // POST and simply not recorded.
        if (!empty($params['enable_analytics'])) {
            if (strlen(http_build_query($params)) < self::GET_BUDGET && isset($client->collections)) {
                return self::get($client->collections[$collection], $params);
            }
            $params['enable_analytics'] = false;
            unset($params['x-typesense-user-id']);
        }
        $query = [];
        foreach (self::QUERY_LEVEL as $key) {
            if (array_key_exists($key, $params)) {
                $query[$key] = $params[$key];
                unset($params[$key]);
            }
        }
        $params['collection'] = $collection;
        $response = self::multi($client, ['searches' => [$params]], $query);
        $result = $response['results'][0] ?? null;
        if (!is_array($result)) {
            throw new \RuntimeException('Typesense returned no search result.');
        }
        if (isset($result['error'])) {
            throw new \RuntimeException(sprintf(
                'Typesense search failed (%s): %s',
                (string) ($result['code'] ?? 'error'),
                (string) $result['error'],
            ));
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $params query-string parameters
     * @return array<string,mixed>
     */
    public static function multi(object $client, array $body, array $params = []): array
    {
        try {
            // @phpstan-ignore property.notFound (a Typesense\Client, duck-typed so tests can fake it)
            $response = $client->multiSearch->perform($body, $params);
        } catch (\Throwable $error) {
            if (self::missingSet($error->getMessage()) === []) {
                throw $error;
            }
            $response = ['error' => $error->getMessage()];
        }
        $drop = self::missingSet((string) ($response['error'] ?? ''));
        foreach ($response['results'] ?? [] as $result) {
            $drop = array_merge($drop, self::missingSet((string) ($result['error'] ?? '')));
        }
        if ($drop !== []) {
            foreach ($body['searches'] as &$search) {
                foreach (array_unique($drop) as $key) {
                    unset($search[$key]);
                }
            }
            unset($search);
            // @phpstan-ignore property.notFound (see above)
            $response = $client->multiSearch->perform($body, $params);
        }
        if (isset($response['error'])) {
            throw new \RuntimeException((string) $response['error']);
        }
        return $response;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function get(object $collection, array $params): array
    {
        try {
            // @phpstan-ignore property.notFound (a Typesense\Collection, duck-typed for tests)
            return $collection->documents->search($params);
        } catch (\Throwable $error) {
            $drop = array_intersect(self::missingSet($error->getMessage()), array_keys($params));
            if ($drop === []) {
                throw $error;
            }
            foreach ($drop as $key) {
                unset($params[$key]);
            }
            // @phpstan-ignore property.notFound (see above)
            return $collection->documents->search($params);
        }
    }

    /**
     * The optional query parameters a Typesense error says are missing, e.g.
     * ["stopwords"] for "Could not find the stopword set" or ["synonym_sets"]
     * for "Synonym index not found". Empty for any other error.
     *
     * @return list<string>
     */
    private static function missingSet(string $message): array
    {
        $message = strtolower($message);
        if (!str_contains($message, 'not found') && !str_contains($message, 'could not find')) {
            return [];
        }
        $keys = [];
        if (str_contains($message, 'stopword')) {
            $keys[] = 'stopwords';
        }
        if (str_contains($message, 'synonym')) {
            $keys[] = 'synonym_sets';
        }
        return $keys;
    }
}
