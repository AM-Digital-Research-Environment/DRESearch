<?php
declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\QueryBuilder;
use DRESearch\Site\BlockLayout\SearchBlockSettings;
use PHPUnit\Framework\TestCase;

final class SearchBlockSettingsTest extends TestCase
{
    use ProfileFixture;

    public function testMissingFacetsMeansAllButExplicitEmptyStaysEmpty(): void
    {
        $profile = $this->profile();
        self::assertSame(['type_s'], (new SearchBlockSettings([], $profile))->facets());
        self::assertSame([], (new SearchBlockSettings(['facets' => []], $profile))->facets());
    }

    public function testClampsPerPageAndLockedFilterLength(): void
    {
        $settings = new SearchBlockSettings([
            'results_per_page' => 999,
            'locked_filter' => str_repeat('x', 1200),
        ], $this->profile());
        self::assertSame(QueryBuilder::PER_PAGE_MAX, $settings->perPage());
        self::assertSame(1000, mb_strlen($settings->lockedFilter()));
    }
}
