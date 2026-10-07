<?php

declare(strict_types=1);

namespace DRESearch\Controller;

use DRESearch\Search\Exception\RequestValidationException;
use DRESearch\Search\RateLimiter;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\SearchRequest;
use Laminas\Http\Response;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/** Public JSON boundary with bounded input, stable errors, and request IDs. */
class SearchController extends AbstractActionController
{
    /** Requests per minute per client, by scope (overridable: dre_search.rate_limits). */
    public const DEFAULT_LIMITS = [
        'search' => 120,
        'facet' => 120,
        'export' => 10,
        'suggest' => 120,
        'federated' => 60,
        'union' => 60,
        'map' => 30,
        'health' => 30,
    ];

    /** @param array<string,int> $limits */
    public function __construct(
        private readonly SearchProxy $proxy,
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
        private readonly array $limits = self::DEFAULT_LIMITS,
    ) {
    }

    public function apiSearchAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['POST']);
            $this->requireRateLimit('search');
            $body = $this->readJsonBody();
            return $this->proxy->search(SearchRequest::profile($body['profile'] ?? ''), $body, $requestId);
        });
    }

    public function apiFacetAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['POST']);
            $this->requireRateLimit('facet');
            $body = $this->readJsonBody();
            return $this->proxy->facet(SearchRequest::profile($body['profile'] ?? ''), $body, $requestId);
        });
    }

    public function apiExportAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['POST']);
            $this->requireRateLimit('export');
            $body = $this->readJsonBody();
            return $this->proxy->export(SearchRequest::profile($body['profile'] ?? ''), $body, $requestId);
        }, 'no-store');
    }

    public function apiSuggestAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['GET', 'POST']);
            $this->requireRateLimit('suggest');
            $profile = SearchRequest::profile(
                $this->params()->fromQuery('profile') ?? $this->params()->fromPost('profile') ?? '',
            );
            $q = SearchRequest::query(
                $this->params()->fromQuery('q') ?? $this->params()->fromPost('q') ?? '',
            );
            $blockId = SearchRequest::blockId(
                $this->params()->fromQuery('block_id') ?? $this->params()->fromPost('block_id')
            );
            return $this->proxy->suggest($profile, $q, $blockId, $requestId);
        }, 'no-store');
    }

    public function apiSuggestAllAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['GET', 'POST']);
            // One multi_search over every corpus: twice the cost of one suggest.
            $this->requireRateLimit('suggest', 2);
            $q = SearchRequest::query(
                $this->params()->fromQuery('q') ?? $this->params()->fromPost('q') ?? '',
            );
            return $this->proxy->suggestAll(
                $q,
                fn(string $s): string => (string) $this->translate($s),
                $requestId,
            );
        }, 'no-store');
    }

    public function apiSearchAllAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['POST']);
            $body = $this->readJsonBody();
            // With tab counts this is one search per corpus plus the active one.
            $this->requireRateLimit('federated', ($body['include_counts'] ?? true) === false ? 1 : 3);
            return $this->proxy->searchAll(SearchRequest::profile($body['profile'] ?? ''), $body, $requestId);
        });
    }

    public function apiUnionAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['POST']);
            $this->requireRateLimit('union');
            return $this->proxy->union(
                $this->readJsonBody(),
                $requestId,
                fn(string $s): string => (string) $this->translate($s),
            );
        });
    }

    public function apiMapAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['POST']);
            $this->requireRateLimit('map');
            $body = $this->readJsonBody();
            return $this->proxy->map(SearchRequest::profile($body['profile'] ?? ''), $body, $requestId);
        });
    }

    /**
     * Monitoring probe: 200 while Typesense is configured and reachable, 503
     * otherwise. Paused corpora are reported, not treated as an outage.
     */
    public function apiHealthAction(): Response
    {
        $requestId = bin2hex(random_bytes(12));
        try {
            $this->requireMethod(['GET']);
            $this->requireRateLimit('health');
            $data = $this->proxy->health();
            return $this->json($data, $data['ok'] ? 200 : 503, $requestId, 'no-store');
        } catch (RequestValidationException $e) {
            return $this->json(['ok' => false, 'error' => ['code' => $e->publicCode(), 'message' => $e->getMessage(), 'request_id' => $requestId]], $e->status(), $requestId, 'no-store', $e->retryAfter());
        } catch (\Throwable $e) {
            $this->logger->err('DRESearch health probe failed', ['request_id' => $requestId, 'message' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => ['code' => 'internal_error', 'message' => 'The request could not be completed.', 'request_id' => $requestId]], 500, $requestId, 'no-store');
        }
    }

    public function resultsAction(): ViewModel
    {
        $query = (string) $this->params()->fromQuery('q', '');
        // An invalid byte sequence would make the page's bootstrap JSON fail to
        // encode, breaking a shareable link; treat it as no query.
        if (!mb_check_encoding($query, 'UTF-8')) {
            $query = '';
        }
        if (mb_strlen($query) > SearchRequest::MAX_QUERY_LENGTH) {
            $query = mb_substr($query, 0, SearchRequest::MAX_QUERY_LENGTH);
        }
        $view = new ViewModel(['query' => $query]);
        $view->setTemplate('dre-search/federated');
        return $view;
    }

    /** @param callable(string):array<string,mixed> $operation */
    private function respond(callable $operation, string $cacheControl = 'no-store'): Response
    {
        $requestId = bin2hex(random_bytes(12));
        try {
            $data = $operation($requestId);
            $status = ($data['available'] ?? true) === false ? 503 : 200;
            if ($status === 503 && !is_array($data['error'] ?? null)) {
                $data['error'] = [
                    'code' => 'backend_unavailable',
                    'message' => 'Search is temporarily unavailable.',
                    'request_id' => $requestId,
                ];
            }
            return $this->json($data, $status, $requestId, $cacheControl);
        } catch (RequestValidationException $e) {
            return $this->json([
                'available' => false,
                'error' => [
                    'code' => $e->publicCode(),
                    'message' => $e->getMessage(),
                    'request_id' => $requestId,
                ],
            ], $e->status(), $requestId, 'no-store', $e->retryAfter());
        } catch (\Throwable $e) {
            $this->logger->err('DRESearch public endpoint failed unexpectedly', [
                'request_id' => $requestId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            // 500, not 503: a programming error is not an outage, and a 503
            // invites clients and monitors to retry what cannot succeed.
            return $this->json([
                'available' => false,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'The request could not be completed.',
                    'request_id' => $requestId,
                ],
            ], 500, $requestId, 'no-store');
        }
    }

    /** @param list<string> $allowed */
    private function requireMethod(array $allowed): void
    {
        $method = strtoupper((string) $this->getRequest()->getMethod());
        if (!in_array($method, $allowed, true)) {
            throw new RequestValidationException('method_not_allowed', 'This HTTP method is not supported.', 405);
        }
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $request = $this->getRequest();
        $length = (int) $this->header('Content-Length');
        if ($length > SearchRequest::MAX_BODY_BYTES) {
            throw new RequestValidationException('body_too_large', 'The request body is too large.', 413);
        }
        $content = (string) $request->getContent();
        if (strlen($content) > SearchRequest::MAX_BODY_BYTES) {
            throw new RequestValidationException('body_too_large', 'The request body is too large.', 413);
        }
        $type = strtolower($this->header('Content-Type'));
        if ($content !== '' && !str_starts_with($type, 'application/json')) {
            throw new RequestValidationException('invalid_content_type', 'Use application/json for request bodies.');
        }
        if ($content === '') {
            return [];
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RequestValidationException('invalid_json', 'The request body is not valid JSON.');
        }
        return $decoded;
    }

    /** One request header's value, or '' (Laminas returns false/iterators for absent/multi). */
    private function header(string $name): string
    {
        $headers = $this->getRequest()->getHeaders();
        if (!$headers instanceof \Laminas\Http\Headers) {
            return '';
        }
        $header = $headers->get($name);
        return $header instanceof \Laminas\Http\Header\HeaderInterface ? (string) $header->getFieldValue() : '';
    }

    private function requireRateLimit(string $scope, int $weight = 1): void
    {
        $server = $this->getRequest()->getServer();
        $remote = is_object($server) && method_exists($server, 'get') ? (string) $server->get('REMOTE_ADDR', '') : '';
        $forwarded = is_object($server) && method_exists($server, 'get') ? (string) $server->get('HTTP_X_FORWARDED_FOR', '') : '';
        $limit = (int) ($this->limits[$scope] ?? self::DEFAULT_LIMITS[$scope] ?? 60);
        $wait = $this->rateLimiter->hit($scope, $this->rateLimiter->identity($remote, $forwarded), $limit, $weight);
        if ($wait > 0) {
            throw new RequestValidationException('rate_limited', 'Too many requests. Please try again shortly.', 429, $wait);
        }
    }

    /** @param array<string,mixed> $data */
    private function json(
        array $data,
        int $status,
        string $requestId,
        string $cacheControl,
        ?int $retryAfter = null,
    ): Response {
        // Substitute rather than fail on an invalid byte sequence: an encode
        // failure used to produce an empty 200 body.
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            $status = 500;
            $body = (string) json_encode(['available' => false, 'error' => [
                'code' => 'internal_error', 'message' => 'The response could not be encoded.', 'request_id' => $requestId,
            ]]);
        }
        /** @var Response $response */
        $response = $this->getResponse();
        $response->setStatusCode($status);
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json; charset=utf-8');
        $response->getHeaders()->addHeaderLine('Cache-Control', $cacheControl);
        $response->getHeaders()->addHeaderLine('X-Request-ID', $requestId);
        $response->getHeaders()->addHeaderLine('X-Content-Type-Options', 'nosniff');
        if ($retryAfter !== null && $retryAfter > 0) {
            $response->getHeaders()->addHeaderLine('Retry-After', (string) $retryAfter);
        }
        $response->setContent($body);
        return $response;
    }
}
