<?php
declare(strict_types=1);

namespace DRESearch\Indexer;

use DRESearch\Settings\ProfileRegistry;
use Laminas\Log\LoggerInterface;
use Typesense\Client;

/**
 * Idempotently provisions popular-query and no-hit analytics for every live
 * profile alias. Destination collections are persistent and intentionally
 * separate from versioned search collections so their history survives swaps.
 *
 * Optional by design: Typesense must run with search analytics enabled and a
 * persistent analytics directory. sync() reports failures instead of throwing,
 * so analytics can never make a reindex fail.
 */
final class AnalyticsSync
{
    private const LIMIT = 1000;

    public function __construct(
        private readonly Client $client,
        private readonly ProfileRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{enabled:bool,rules:list<string>,errors:list<string>} */
    public function sync(): array
    {
        $rules = [];
        $errors = [];
        foreach ($this->registry->all() as $profile) {
            foreach (['popular_queries' => 'popular', 'nohits_queries' => 'nohits'] as $type => $suffix) {
                $destination = self::collectionName($profile->name(), $suffix);
                $ruleName = self::ruleName($profile->name(), $suffix);
                try {
                    $this->ensureDestination($destination);
                    $params = [
                        'destination_collection' => $destination,
                        'limit' => self::LIMIT,
                        'capture_search_requests' => true,
                    ];
                    if ($type === 'popular_queries') {
                        $params['expand_query'] = false;
                    }
                    $this->client->analytics->rules()[$ruleName]->update([
                        'name' => $ruleName,
                        'type' => $type,
                        'collection' => $profile->collection(),
                        'event_type' => 'search',
                        'rule_tag' => 'dre_search',
                        'params' => $params,
                    ]);
                    $rules[] = $ruleName;
                } catch (\Throwable $e) {
                    $errors[] = sprintf('%s: %s', $ruleName, $e->getMessage());
                }
            }
        }

        if ($errors !== []) {
            $this->logger->warn(
                'DRESearch: analytics provisioning is unavailable. Start Typesense with '
                . '--enable-search-analytics=true and a persistent --analytics-dir. '
                . implode(' | ', array_slice($errors, 0, 3))
            );
        } else {
            $this->logger->info('DRESearch: analytics rules provisioned', ['rules' => $rules]);
        }
        return ['enabled' => $errors === [], 'rules' => $rules, 'errors' => $errors];
    }

    public static function collectionName(string $profile, string $suffix): string
    {
        return self::boundedName('dre_a_' . $profile . '_' . $suffix);
    }

    private static function ruleName(string $profile, string $suffix): string
    {
        return self::boundedName('dre_' . $profile . '_' . $suffix);
    }

    private static function boundedName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $name) ?? 'dre_analytics';
        return strlen($name) <= 64
            ? $name
            : substr($name, 0, 51) . '_' . substr(hash('sha256', $name), 0, 12);
    }

    private function ensureDestination(string $name): void
    {
        try {
            $this->client->collections[$name]->retrieve();
            return;
        } catch (\Throwable) {
            // Missing is expected on the first run; create below. A real backend
            // failure will make create throw and be reported by sync().
        }
        $this->client->collections->create([
            'name' => $name,
            'fields' => [
                ['name' => 'q', 'type' => 'string'],
                ['name' => 'count', 'type' => 'int32'],
            ],
        ]);
    }
}
