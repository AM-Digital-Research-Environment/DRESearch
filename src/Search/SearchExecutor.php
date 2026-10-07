<?php

declare(strict_types=1);

namespace DRESearch\Search;

/**
 * Every Typesense search the proxy runs goes through here, so they all share
 * one transport (POST /multi_search) and one missing-stopword fallback.
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
            $response = $client->multiSearch->perform($body, $params);
        } catch (\Throwable $error) {
            if (!self::missingStopwords($error->getMessage())) {
                throw $error;
            }
            $response = ['error' => $error->getMessage()];
        }
        $retry = self::missingStopwords((string) ($response['error'] ?? ''));
        foreach ($response['results'] ?? [] as $result) {
            $retry = $retry || self::missingStopwords((string) ($result['error'] ?? ''));
        }
        if ($retry) {
            foreach ($body['searches'] as &$search) {
                unset($search['stopwords']);
            }
            unset($search);
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
            return $collection->documents->search($params);
        } catch (\Throwable $error) {
            if (!isset($params['stopwords']) || !self::missingStopwords($error->getMessage())) {
                throw $error;
            }
            unset($params['stopwords']);
            return $collection->documents->search($params);
        }
    }

    private static function missingStopwords(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'stopword')
            && (str_contains($message, 'not found') || str_contains($message, 'could not find'));
    }
}
