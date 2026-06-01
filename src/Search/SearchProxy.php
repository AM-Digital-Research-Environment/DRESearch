<?php
declare(strict_types=1);

namespace DRESearch\Search;

use DRESearch\Settings\FacetConfig;

/**
 * Server-side search: runs the Typesense query with the server-held key and
 * normalises the response into the compact shape the Svelte client expects.
 * Every method is null-safe — when Typesense isn't configured or is
 * unreachable, it returns an "available: false" payload rather than throwing,
 * so the block shows a quiet notice instead of breaking the page.
 */
final class SearchProxy
{
    private readonly QueryBuilder $queryBuilder;

    public function __construct(private readonly TypesenseClientProvider $provider)
    {
        $this->queryBuilder = new QueryBuilder();
    }

    public function isAvailable(): bool
    {
        return $this->provider->isConfigured();
    }

    /** @param array<string,mixed> $req */
    public function search(array $req): array
    {
        $client = $this->provider->getClient();
        if ($client === null) {
            return $this->unavailable();
        }
        try {
            $result = $client->collections[$this->provider->collection()]
                ->documents
                ->search($this->queryBuilder->search($req));
        } catch (\Throwable $e) {
            return $this->unavailable($e->getMessage());
        }
        return $this->normalize($result);
    }

    public function suggest(string $q): array
    {
        $q = trim($q);
        $client = $this->provider->getClient();
        if ($client === null || $q === '') {
            return ['available' => $client !== null, 'suggestions' => []];
        }
        try {
            $result = $client->collections[$this->provider->collection()]
                ->documents
                ->search($this->queryBuilder->suggest($q));
        } catch (\Throwable $e) {
            return ['available' => false, 'suggestions' => []];
        }

        $suggestions = [];
        foreach ($result['hits'] ?? [] as $hit) {
            $doc = $hit['document'] ?? [];
            $suggestions[] = [
                'id'      => (string) ($doc['id'] ?? ''),
                'title'   => (string) ($doc['title'] ?? ''),
                'type'    => $doc['type_s'] ?? null,
                'project' => $doc['project_s'] ?? null,
                'year'    => isset($doc['year']) ? (int) $doc['year'] : null,
            ];
        }
        return ['available' => true, 'suggestions' => $suggestions];
    }

    private function normalize(array $result): array
    {
        $hits = [];
        foreach ($result['hits'] ?? [] as $hit) {
            $doc = $hit['document'] ?? [];
            if (isset($hit['highlight']['title']['snippet'])) {
                $doc['_title_highlight'] = $hit['highlight']['title']['snippet'];
            }
            $hits[] = $doc;
        }

        $facets = [];
        foreach ($result['facet_counts'] ?? [] as $facet) {
            $field = (string) ($facet['field_name'] ?? '');
            if ($field === '') {
                continue;
            }
            $counts = [];
            foreach ($facet['counts'] ?? [] as $count) {
                $counts[] = [
                    'value' => (string) ($count['value'] ?? ''),
                    'count' => (int) ($count['count'] ?? 0),
                ];
            }
            $facets[] = [
                'field'  => $field,
                'label'  => FacetConfig::label($field),
                'counts' => $counts,
            ];
        }

        return [
            'available'      => true,
            'found'          => (int) ($result['found'] ?? 0),
            'page'           => (int) ($result['page'] ?? 1),
            'hits'           => $hits,
            'facets'         => $facets,
            'search_time_ms' => (int) ($result['search_time_ms'] ?? 0),
        ];
    }

    private function unavailable(?string $error = null): array
    {
        return [
            'available' => false,
            'found'     => 0,
            'page'      => 1,
            'hits'      => [],
            'facets'    => [],
            'error'     => $error,
        ];
    }
}
