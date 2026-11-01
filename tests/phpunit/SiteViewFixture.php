<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Laminas\View\Renderer\PhpRenderer;

/** Omeka's site-page view helpers a template reads, without a router or a site entity. */
trait SiteViewFixture
{
    /**
     * `currentSite()` answers a site with this slug (null: no site), and
     * `url()` builds Omeka's site routes the way its router does.
     */
    private function stubSiteHelpers(PhpRenderer $view, ?string $slug = 'amira'): void
    {
        $helpers = $view->getHelperPluginManager();
        $helpers->setService('currentSite', new class ($slug) {
            public function __construct(private readonly ?string $slug)
            {
            }

            public function __invoke(): ?object
            {
                if ($this->slug === null) {
                    return null;
                }
                return new class ($this->slug) {
                    public function __construct(private readonly string $slug)
                    {
                    }

                    public function slug(): string
                    {
                        return $this->slug;
                    }
                };
            }
        });
        $helpers->setService('url', new class {
            /**
             * @param array<string,string> $params
             * @param array{query?:array<string,string>} $options
             */
            public function __invoke(string $route, array $params = [], array $options = []): string
            {
                $path = match ($route) {
                    'site/resource' => '/omeka/s/' . $params['site-slug'] . '/' . $params['controller'] . '/' . $params['action'],
                    'site/dre-search' => '/omeka/s/' . $params['site-slug'] . '/dre-search',
                    default => '/omeka/' . $route,
                };
                return $path . (isset($options['query']) ? '?' . http_build_query($options['query']) : '');
            }
        });
    }
}
