<?php
declare(strict_types=1);

namespace DRESearch\Controller;

use DRESearch\Search\SearchProxy;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Public JSON proxy in front of Typesense, plus the federated results page.
 *
 * - apiSearch / apiSuggest      : per-corpus search + autocomplete (the blocks).
 * - apiSuggestAll / apiSearchAll: federated across every corpus (the header bar
 *                                 + the grouped-by-type results page).
 * - results                     : the federated results page (a site route).
 *
 * The Typesense key stays server-side and is_public:=true is enforced by the
 * QueryBuilder. The api* actions emit a Response directly (no view), so they
 * don't depend on a JSON view strategy; results returns a ViewModel.
 */
class SearchController extends AbstractActionController
{
    public function __construct(private readonly SearchProxy $proxy)
    {
    }

    public function apiSearchAction(): Response
    {
        $body = $this->readBody();
        $profile = (string) ($body['profile'] ?? '');
        return $this->json($this->proxy->search($profile, $body));
    }

    /**
     * Per-year document counts for the date-slider histogram. Scoped to the
     * current query + categorical filters (the year range is ignored server-side),
     * so the bars show where the current results cluster across the full span.
     */
    public function apiYearHistogramAction(): Response
    {
        $body = $this->readBody();
        $profile = (string) ($body['profile'] ?? '');
        return $this->json(['buckets' => $this->proxy->yearDistribution($profile, $body)]);
    }

    public function apiSuggestAction(): Response
    {
        $profile = (string) ($this->params()->fromQuery('profile') ?? $this->params()->fromPost('profile') ?? '');
        $q = (string) ($this->params()->fromQuery('q') ?? $this->params()->fromPost('q') ?? '');
        return $this->json($this->proxy->suggest($profile, $q));
    }

    /**
     * Federated autocomplete across every corpus (the header search bar). Labels
     * are translated server-side via the controller's translate plugin so the
     * type badge reads in the site language.
     */
    public function apiSuggestAllAction(): Response
    {
        $q = (string) ($this->params()->fromQuery('q') ?? $this->params()->fromPost('q') ?? '');
        return $this->json($this->proxy->suggestAll(
            $q,
            fn(string $s): string => (string) $this->translate($s),
        ));
    }

    /**
     * Federated search for the results page: per-corpus counts (tabs) plus the
     * focused corpus's full faceted response. The focused corpus is the `profile`
     * key in the JSON body (falls back to the default profile in SearchProxy).
     */
    public function apiSearchAllAction(): Response
    {
        $body = $this->readBody();
        $profile = (string) ($body['profile'] ?? '');
        return $this->json($this->proxy->searchAll($profile, $body));
    }

    /**
     * The federated results page (site/dre-search). Renders within the active
     * site theme layout; the dreFederatedSearch view helper builds the bootstrap
     * and mounts the Svelte client. Seeds the initial query from ?q=.
     */
    public function resultsAction(): ViewModel
    {
        $view = new ViewModel(['query' => (string) $this->params()->fromQuery('q', '')]);
        $view->setTemplate('dre-search/federated');
        return $view;
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
