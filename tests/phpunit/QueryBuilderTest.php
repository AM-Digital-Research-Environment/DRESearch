<?php
declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    use ProfileFixture;

    public function testCombinesPublicConstraintServerScopeAndEscapedFilters(): void
    {
        $params = (new QueryBuilder($this->profile(), 'tenant_s:=`A`'))->search([
            'q' => '',
            'filters' => ['type_s' => ['Book` && is_public:=false']],
        ]);

        self::assertStringContainsString('is_public:=true', $params['filter_by']);
        self::assertStringContainsString('tenant_s:=`A`', $params['filter_by']);
        self::assertStringContainsString('type_s:=[`Book && is_public:=false`]', $params['filter_by']);
    }

    public function testSuggestAlsoEnforcesServerScope(): void
    {
        $params = (new QueryBuilder($this->profile(), 'tenant_s:=`A`'))->suggest('arc');
        self::assertSame('is_public:=true && (tenant_s:=`A`)', $params['filter_by']);
    }

    public function testExplicitlyEmptyFacetsStayDisabled(): void
    {
        $params = (new QueryBuilder($this->profile()))->search(['facets' => []]);

        self::assertArrayNotHasKey('facet_by', $params);
        self::assertArrayNotHasKey('max_facet_values', $params);
    }

    public function testUnionQueriesShareACompatibleSortAndCarrySourceFields(): void
    {
        $params = (new QueryBuilder($this->profile()))->union('archive');
        self::assertSame('records_current', $params['collection']);
        self::assertSame('_text_match:desc,title:asc', $params['sort_by']);
        self::assertStringNotContainsString('_profile', (string) ($params['exclude_fields'] ?? ''));
        self::assertArrayNotHasKey('facet_by', $params);
    }

    public function testMapQueryIsCoordinateScopedAndPayloadBounded(): void
    {
        $profile = $this->profile(['display_fields' => [
            'geo' => ['property' => null, 'type' => 'geopoint', 'facet' => false, 'index' => true],
            'has_coords' => ['property' => null, 'type' => 'bool', 'facet' => true, 'index' => true],
        ]]);
        $params = (new QueryBuilder($profile))->map(['q' => '', 'filters' => []], 1);
        self::assertStringContainsString('has_coords:=true', $params['filter_by']);
        self::assertStringContainsString('geo', $params['include_fields']);
        self::assertLessThanOrEqual(250, $params['per_page']);
    }
}
