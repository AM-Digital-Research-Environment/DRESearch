<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Doctrine\DBAL\DriverManager;
use DRESearch\Controller\SearchController;
use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\RateLimiter;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\SearchRequest;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Test\Support\SqliteUpsertConnection;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Http\Response;
use Laminas\Stdlib\Parameters;
use PHPUnit\Framework\TestCase;

/**
 * The public JSON boundary: methods, body limits, content type, rate limits
 * and the error shape a browser and a monitor rely on. Typesense is
 * unconfigured, so an accepted search answers "unavailable" (503) without a
 * network call; the rate limiter counts in SQLite.
 */
final class SearchControllerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(DriverManager::class)) {
            self::markTestSkipped('Requires Omeka core (Doctrine DBAL, Laminas MVC).');
        }
    }

    public function testASearchMustBePosted(): void
    {
        $response = $this->call('apiSearchAction', 'GET');
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('method_not_allowed', $this->body($response)['error']['code']);
        $this->assertCommonHeaders($response);
    }

    /** @return array<string,array{string,string,int,string}> content type, body, status, error code */
    public static function badBodies(): array
    {
        return [
            'not JSON' => ['application/json', '{"profile":', 400, 'invalid_json'],
            'a JSON scalar' => ['application/json', '"research_items"', 400, 'invalid_json'],
            'a form post' => ['application/x-www-form-urlencoded', 'profile=research_items', 400, 'invalid_content_type'],
            'too large' => ['application/json', '{"q":"' . str_repeat('a', SearchRequest::MAX_BODY_BYTES) . '"}', 413, 'body_too_large'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badBodies')]
    public function testAMalformedBodyIsRefusedWithAStableCode(string $type, string $body, int $status, string $code): void
    {
        $response = $this->call('apiSearchAction', 'POST', $body, $type);
        self::assertSame($status, $response->getStatusCode());
        $json = $this->body($response);
        self::assertFalse($json['available']);
        self::assertSame($code, $json['error']['code']);
        $this->assertCommonHeaders($response);
    }

    /**
     * An accepted search with Typesense away: 503 with the request id in the
     * body and the header (the client shows it, the log has it), never cached.
     */
    public function testAnOutageIsA503ThatIsNeverCached(): void
    {
        $response = $this->call('apiSearchAction', 'POST', '{"profile":"research_items","q":"river"}');
        self::assertSame(503, $response->getStatusCode());
        $json = $this->body($response);
        self::assertSame('backend_unavailable', $json['error']['code']);
        self::assertSame($this->header($response, 'X-Request-ID'), $json['error']['request_id']);
        $this->assertCommonHeaders($response);
    }

    public function testAnUnknownProfileIsAClientError(): void
    {
        $response = $this->call('apiSearchAction', 'POST', '{"profile":"no_such_corpus"}');
        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertLessThan(500, $response->getStatusCode());
        self::assertNotSame('', $this->body($response)['error']['code']);
    }

    /** Over the budget: 429 with Retry-After, before the proxy is asked. */
    public function testTheRateLimitAnswers429WithRetryAfter(): void
    {
        $controller = $this->controller(['search' => 1]);
        $first = $this->call('apiSearchAction', 'POST', '{"profile":"research_items"}', 'application/json', $controller);
        self::assertSame(503, $first->getStatusCode());

        $second = $this->call('apiSearchAction', 'POST', '{"profile":"research_items"}', 'application/json', $controller);
        self::assertSame(429, $second->getStatusCode());
        self::assertSame('rate_limited', $this->body($second)['error']['code']);
        self::assertGreaterThan(0, (int) $this->header($second, 'Retry-After'));
    }

    /** The probe: 503 while Typesense is not configured, with the report as the body. */
    public function testTheHealthProbeReportsAnUnconfiguredServer(): void
    {
        $response = $this->call('apiHealthAction', 'GET');
        self::assertSame(503, $response->getStatusCode());
        $json = $this->body($response);
        self::assertFalse($json['ok']);
        self::assertFalse($json['configured']);
        self::assertNull($json['search_key']);
        $this->assertCommonHeaders($response);

        self::assertSame(405, $this->call('apiHealthAction', 'POST')->getStatusCode());
    }

    private function controller(array $limits = SearchController::DEFAULT_LIMITS): SearchController
    {
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter(new \Laminas\Log\Writer\Noop());
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $registry = ProfileRegistry::fromArray($config['dre_search']['profiles']);
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $proxy = new SearchProxy(new TypesenseClientProvider('', 0, 'http', ''), $registry, new BlockScopeResolver($sqlite), $logger);

        $limitsDb = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => SqliteUpsertConnection::class]);
        $limitsDb->executeStatement(
            'CREATE TABLE dre_search_rate_limit (bucket_key CHAR(64) NOT NULL PRIMARY KEY, window_started DATETIME NOT NULL, request_count INT NOT NULL DEFAULT 0)',
        );
        return new SearchController($proxy, new RateLimiter($limitsDb), $logger, $limits);
    }

    private function call(string $action, string $method, string $body = '', string $type = 'application/json', ?SearchController $controller = null): Response
    {
        $controller ??= $this->controller();
        $request = new Request();
        $request->setMethod($method);
        $request->setServer(new Parameters(['REMOTE_ADDR' => '203.0.113.5']));
        if ($body !== '') {
            $request->setContent($body);
            $headers = new \Laminas\Http\Headers();
            $headers->addHeaderLine('Content-Type', $type);
            $request->setHeaders($headers);
        }
        (new \ReflectionProperty($controller, 'request'))->setValue($controller, $request);
        (new \ReflectionProperty($controller, 'response'))->setValue($controller, new Response());
        $response = $controller->{$action}();
        self::assertInstanceOf(Response::class, $response);
        return $response;
    }

    /** @return array<string,mixed> */
    private function body(Response $response): array
    {
        $json = json_decode($response->getContent(), true);
        self::assertIsArray($json);
        return $json;
    }

    private function assertCommonHeaders(Response $response): void
    {
        self::assertSame('application/json; charset=utf-8', $this->header($response, 'Content-Type'));
        self::assertSame('no-store', $this->header($response, 'Cache-Control'));
        self::assertSame('nosniff', $this->header($response, 'X-Content-Type-Options'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $this->header($response, 'X-Request-ID'));
    }

    private function header(Response $response, string $name): string
    {
        $header = $response->getHeaders()->get($name);
        self::assertInstanceOf(\Laminas\Http\Header\HeaderInterface::class, $header, $name);
        return (string) $header->getFieldValue();
    }
}
