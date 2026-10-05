<?php

declare(strict_types=1);

namespace DRESearch\Search;

/** One consistent missing-stopword fallback for single, federated and union searches. */
final class SearchExecutor
{
    public static function single(object $collection, array $params): array
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

    private static function missingStopwords(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'stopword')
            && (str_contains($message, 'not found') || str_contains($message, 'could not find'));
    }
}
