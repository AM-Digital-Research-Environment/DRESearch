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
        // Typesense names one missing set per answer, so a fresh volume with
        // neither the stopword nor the synonym set needs a retry for each. Every
        // pass drops at least one new key, so this ends.
        $dropped = [];
        while (true) {
            try {
                // @phpstan-ignore property.notFound (a Typesense\Client, duck-typed so tests can fake it)
                $response = $client->multiSearch->perform($body, $params);
            } catch (\Throwable $error) {
                if (array_diff(self::missingSet($error->getMessage()), $dropped) === []) {
                    throw $error;
                }
                $response = ['error' => $error->getMessage()];
            }
            $missing = self::missingSet((string) ($response['error'] ?? ''));
            foreach ($response['results'] ?? [] as $result) {
                $missing = array_merge($missing, self::missingSet((string) ($result['error'] ?? '')));
            }
            $drop = array_values(array_diff(array_unique($missing), $dropped));
            if ($drop === []) {
                break;
            }
            foreach ($body['searches'] as &$search) {
                foreach ($drop as $key) {
                    unset($search[$key]);
                }
            }
            unset($search);
            $dropped = array_merge($dropped, $drop);
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
        // One retry per missing set, as in multi(); each pass removes a key.
        while (true) {
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
            }
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
