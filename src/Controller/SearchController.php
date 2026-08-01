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
    public function __construct(
        private readonly SearchProxy $proxy,
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function apiSearchAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['POST']);
            $this->requireRateLimit('search', 120);
            $body = $this->readJsonBody();
            return $this->proxy->search(SearchRequest::profile($body['profile'] ?? ''), $body, $requestId);
        });
    }

    public function apiExportAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['POST']);
            $this->requireRateLimit('export', 10);
            $body = $this->readJsonBody();
            return $this->proxy->export(SearchRequest::profile($body['profile'] ?? ''), $body, $requestId);
        }, 'no-store');
    }

    public function apiSuggestAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['GET', 'POST']);
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
        }, 'public, max-age=15, stale-while-revalidate=30');
    }

    public function apiSuggestAllAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['GET', 'POST']);
            $q = SearchRequest::query(
                $this->params()->fromQuery('q') ?? $this->params()->fromPost('q') ?? '',
            );
            return $this->proxy->suggestAll(
                $q,
                fn(string $s): string => (string) $this->translate($s),
                $requestId,
            );
        }, 'public, max-age=15, stale-while-revalidate=30');
    }

    public function apiSearchAllAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['POST']);
            $this->requireRateLimit('federated', 60);
            $body = $this->readJsonBody();
            return $this->proxy->searchAll(SearchRequest::profile($body['profile'] ?? ''), $body, $requestId);
        });
    }

    public function apiUnionAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['POST']);
            $this->requireRateLimit('union', 60);
            return $this->proxy->union($this->readJsonBody(), $requestId);
        });
    }

    public function apiMapAction(): Response
    {
        return $this->respond(function (string $requestId): array {
            $this->requireMethod(['POST']);
            $this->requireRateLimit('map', 30);
            $body = $this->readJsonBody();
            return $this->proxy->map(SearchRequest::profile($body['profile'] ?? ''), $body, $requestId);
        });
    }

    public function resultsAction(): ViewModel
    {
        $query = (string) $this->params()->fromQuery('q', '');
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
            ], $e->status(), $requestId, 'no-store');
        } catch (\Throwable $e) {
            $this->logger->err('DRESearch public endpoint failed unexpectedly', [
                'request_id' => $requestId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return $this->json([
                'available' => false,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'The request could not be completed.',
                    'request_id' => $requestId,
                ],
            ], 503, $requestId, 'no-store');
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
        $lengthHeader = $request->getHeaders()->get('Content-Length');
        $length = $lengthHeader ? (int) $lengthHeader->getFieldValue() : 0;
        if ($length > SearchRequest::MAX_BODY_BYTES) {
            throw new RequestValidationException('body_too_large', 'The request body is too large.', 413);
        }
        $content = (string) $request->getContent();
        if (strlen($content) > SearchRequest::MAX_BODY_BYTES) {
            throw new RequestValidationException('body_too_large', 'The request body is too large.', 413);
        }
        $contentType = $request->getHeaders()->get('Content-Type');
        $type = $contentType ? strtolower((string) $contentType->getFieldValue()) : '';
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

    private function requireRateLimit(string $scope, int $limit): void
    {
        $server = $this->getRequest()->getServer();
        $identity = is_object($server) && method_exists($server, 'get')
            ? (string) $server->get('REMOTE_ADDR', 'unknown')
            : 'unknown';
        if (!$this->rateLimiter->allow($scope, $identity, $limit)) {
            throw new RequestValidationException('rate_limited', 'Too many requests. Please try again shortly.', 429);
        }
    }

    /** @param array<string,mixed> $data */
    private function json(
        array $data,
        int $status,
        string $requestId,
        string $cacheControl,
    ): Response {
        /** @var Response $response */
        $response = $this->getResponse();
        $response->setStatusCode($status);
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json; charset=utf-8');
        $response->getHeaders()->addHeaderLine('Cache-Control', $cacheControl);
        $response->getHeaders()->addHeaderLine('X-Request-ID', $requestId);
        $response->getHeaders()->addHeaderLine('X-Content-Type-Options', 'nosniff');
        $response->setContent((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response;
    }
}
