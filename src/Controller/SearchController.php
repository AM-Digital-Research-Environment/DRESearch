<?php
declare(strict_types=1);

namespace DRESearch\Controller;

use DRESearch\Search\SearchProxy;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;

/**
 * Public JSON proxy in front of Typesense. The page block's Svelte client posts
 * search + autocomplete requests here; the Typesense key stays server-side and
 * is_public:=true is enforced by the QueryBuilder. Both actions emit a Response
 * directly (no view), so they don't depend on a JSON view strategy.
 */
class SearchController extends AbstractActionController
{
    public function __construct(private readonly SearchProxy $proxy)
    {
    }

    public function apiSearchAction(): Response
    {
        return $this->json($this->proxy->search($this->readBody()));
    }

    public function apiSuggestAction(): Response
    {
        $q = (string) ($this->params()->fromQuery('q') ?? $this->params()->fromPost('q') ?? '');
        return $this->json($this->proxy->suggest($q));
    }

    /**
     * Read the request payload: a JSON body (the client's normal path) or, as a
     * fallback, POST/GET params.
     *
     * @return array<string,mixed>
     */
    private function readBody(): array
    {
        $request = $this->getRequest();
        if ($request->isPost()) {
            $content = (string) $request->getContent();
            if ($content !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            $post = $this->params()->fromPost();
            return is_array($post) ? $post : [];
        }
        $query = $this->params()->fromQuery();
        return is_array($query) ? $query : [];
    }

    /** @param array<string,mixed> $data */
    private function json(array $data): Response
    {
        /** @var Response $response */
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json; charset=utf-8');
        $response->setContent((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response;
    }
}
