<?php

declare(strict_types=1);

namespace DRESearch\Site\Navigation\Link;

use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\Navigation\Link\LinkInterface;
use Omeka\Stdlib\ErrorStore;

/** Site-navigation entry pointing at /s/{site}/dre-search, the federated results page. */
final class FederatedSearch implements LinkInterface
{
    public function getName()
    {
        return 'DRE Search: all results'; // @translate
    }

    public function getFormTemplate()
    {
        return 'common/navigation-link-form/dre-search-results';
    }

    public function isValid(array $data, ErrorStore $errorStore)
    {
        return true;
    }

    public function getLabel(array $data, SiteRepresentation $site)
    {
        return isset($data['label']) && trim((string) $data['label']) !== '' ? (string) $data['label'] : null;
    }

    public function toZend(array $data, SiteRepresentation $site)
    {
        return [
            'type' => 'mvc',
            'route' => 'site/dre-search',
            'params' => ['site-slug' => $site->slug()],
        ];
    }

    public function toJstree(array $data, SiteRepresentation $site)
    {
        return ['label' => $data['label'] ?? ''];
    }
}
