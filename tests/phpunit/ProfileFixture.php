<?php
declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Settings\SearchProfile;

trait ProfileFixture
{
    private function profile(array $overrides = []): SearchProfile
    {
        $base = [
            'label' => 'Records',
            'collection' => 'records_current',
            'kind' => 'item',
            'template_id' => 10,
            'query_by' => 'title,creator_ss',
            'date' => ['mode' => 'none'],
            'facets' => [
                'type_s' => ['property' => 'dcterms:type', 'label' => 'Type', 'array' => false],
            ],
            'display_fields' => [
                'creator_ss' => ['property' => 'dcterms:creator', 'type' => 'string[]', 'facet' => true],
            ],
        ];
        return SearchProfile::fromArray('records', array_replace_recursive($base, $overrides));
    }
}
