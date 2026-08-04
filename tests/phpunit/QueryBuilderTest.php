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

    public function testOnlyRefinedSidebarFacetsAreRecounted(): void
    {
        $builder = new QueryBuilder($this->multiFacetProfile());
        $refined = $builder->refinedFacetFields([
            'facets'  => ['type_s', 'language_ss'],
            'filters' => [
                'type_s'     => ['Book'],
                'language_ss' => [],            // no selection → counts already open
                'creator_ss' => ['Achebe'],     // filterable, but not a sidebar facet
            ],
        ]);

        self::assertSame(['type_s'], $refined);
    }

    public function testFacetRecountLiftsOnlyItsOwnFilterAndKeepsTheScope(): void
    {
        $req = [
            'q'       => 'archive',
            'facets'  => ['type_s', 'language_ss'],
            'filters' => ['type_s' => ['Book'], 'language_ss' => ['French']],
        ];
        $params = (new QueryBuilder($this->multiFacetProfile(), 'tenant_s:=`A`'))
            ->facetCountsFor($req, 'type_s');

        self::assertSame('type_s', $params['facet_by']);
        self::assertStringNotContainsString('type_s:=', $params['filter_by']);
        // Every other constraint still applies, so the counts stay honest.
        self::assertStringContainsString('is_public:=true', $params['filter_by']);
        self::assertStringContainsString('tenant_s:=`A`', $params['filter_by']);
        self::assertStringContainsString('language_ss:=[`French`]', $params['filter_by']);
        // Facet payload only.
        self::assertSame('records_current', $params['collection']);
        self::assertSame(1, $params['per_page']);
        self::assertSame('id', $params['include_fields']);
        self::assertArrayNotHasKey('highlight_full_fields', $params);
    }

    public function testUnrefinedSearchStaysASingleQuery(): void
    {
        $builder = new QueryBuilder($this->multiFacetProfile());
        self::assertSame([], $builder->refinedFacetFields(['filters' => []]));
        self::assertSame([], $builder->refinedFacetFields([]));
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

    /** Two sidebar facets + a filterable-but-not-faceted display field. */
    private function multiFacetProfile(): \DRESearch\Settings\SearchProfile
    {
        return $this->profile(['facets' => [
            'language_ss' => ['property' => 'dcterms:language', 'label' => 'Language', 'array' => true],
        ]]);
    }
}
