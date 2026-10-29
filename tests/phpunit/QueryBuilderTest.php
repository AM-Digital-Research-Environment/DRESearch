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

    public function testShortPrefixQueriesGetAWiderCandidatePool(): void
    {
        $builder = new QueryBuilder($this->profile());

        // Every query path that a user's text reaches, including the ones derived
        // from search() — a truncated candidate pool under-reports each of them.
        self::assertSame(QueryBuilder::MAX_CANDIDATES, $builder->search(['q' => 'k'])['max_candidates']);
        self::assertSame(QueryBuilder::MAX_CANDIDATES, $builder->suggest('k')['max_candidates']);
        self::assertSame(QueryBuilder::MAX_CANDIDATES, $builder->countOnly(['q' => 'k'])['max_candidates']);
        self::assertSame(QueryBuilder::MAX_CANDIDATES, $builder->union('k')['max_candidates']);
        self::assertSame(QueryBuilder::MAX_CANDIDATES, $builder->export(['q' => 'k'], 1)['max_candidates']);

        // exhaustive_search would recover the rest of the tail, but it also lifts
        // the typo-pass early exit for EVERY query — a relevance change, not a fix.
        self::assertArrayNotHasKey('exhaustive_search', $builder->search(['q' => 'k']));
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
        self::assertSame(0, $params['per_page']);
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

    public function testFilterIsExactlyTheVisibilityGuardPlusEachConstraint(): void
    {
        $params = (new QueryBuilder($this->multiFacetProfile(), 'tenant_s:=`A`', [7, 3]))->search([
            'filters' => ['type_s' => ['Book', 'Map'], 'language_ss' => ['French']],
        ]);
        // Whole-string equality: a substring assertion cannot catch a clause
        // that escapes its group.
        self::assertSame(
            'is_public:=true && id:!=[7,3] && (tenant_s:=`A`) && type_s:=[`Book`,`Map`] && language_ss:=[`French`]',
            $params['filter_by'],
        );
    }

    public function testPendingChangesAreHiddenFromEveryDerivedQuery(): void
    {
        $profile = $this->profile(['display_fields' => [
            'geo' => ['property' => null, 'type' => 'geopoint', 'facet' => false, 'index' => true],
            'has_coords' => ['property' => null, 'type' => 'bool', 'facet' => true, 'index' => true],
        ]]);
        $builder = new QueryBuilder($profile, null, [3, 7]);
        $req = ['q' => 'river', 'filters' => ['type_s' => ['Book']]];
        foreach (
            [
                $builder->search($req), $builder->suggest('river'), $builder->countOnly($req),
                $builder->export($req, 1), $builder->map($req, 1), $builder->facetCountsFor($req, 'type_s'),
                $builder->facetSearch($req, 'type_s', 'Bo'), $builder->union('river'),
            ] as $params
        ) {
            self::assertStringContainsString('id:!=[3,7]', $params['filter_by']);
        }
    }

    public function testUnbalancedLockedFilterIsRefusedBeforeReachingTypesense(): void
    {
        $this->expectException(\DRESearch\Search\Exception\RequestValidationException::class);
        (new QueryBuilder($this->profile(), 'type_s:=`A`) || (type_s:=`B`'))->search([]);
    }

    public function testVisibilityFieldNeverTravelsWithHits(): void
    {
        $params = (new QueryBuilder($this->profile()))->search(['q' => 'x']);
        self::assertContains('is_public', explode(',', $params['exclude_fields']));
    }

    /**
     * Every mode built from a visitor's request matches exactly what the live
     * search matches: same text, fields, filter, sort, recall, time budget and
     * stopword/synonym sets. A tab count or an export that matched differently
     * would contradict the list it sits beside.
     */
    public function testEveryModeMatchesWhatTheLiveSearchMatches(): void
    {
        $builder = new QueryBuilder($this->geoProfile(), 'tenant_s:=`A`', [3]);
        $req = [
            'q' => 'river', 'sort' => 'title', 'year_from' => 1990, 'year_to' => 2000,
            // A chip filter, so the recount below lifts nothing it shares.
            'filters' => ['creator_ss' => ['Achebe']],
        ];
        $search = $builder->search($req);
        $core = ['q', 'query_by', 'filter_by', 'sort_by', 'max_candidates', 'search_cutoff_ms', 'stopwords', 'synonym_sets'];
        foreach (
            [
                'count' => $builder->countOnly($req),
                'export' => $builder->export($req, 2),
                'recount' => $builder->facetCountsFor($req, 'type_s'),
                'facet search' => $builder->facetSearch($req, 'type_s', 'Bo'),
            ] as $mode => $params
        ) {
            foreach ($core as $key) {
                self::assertSame($search[$key], $params[$key] ?? null, sprintf('%s: %s', $mode, $key));
            }
        }
        $map = $builder->map($req, 1);
        self::assertSame($search['filter_by'] . ' && has_coords:=true', $map['filter_by']);
        foreach (array_diff($core, ['filter_by']) as $key) {
            self::assertSame($search[$key], $map[$key] ?? null, 'map: ' . $key);
        }
        // The merged stream takes the query alone, but matches it the same way.
        $union = $builder->union('river');
        foreach (['q', 'query_by', 'max_candidates', 'search_cutoff_ms', 'stopwords', 'synonym_sets'] as $key) {
            self::assertSame($search[$key], $union[$key] ?? null, 'union: ' . $key);
        }
    }

    /**
     * What a derived mode sends is exactly its core plus what it uses, so a
     * parameter added to the live search's presentation (facets, highlights,
     * analytics, paging) cannot slip into a count, export, map or recount. A
     * new key here is a decision: add it to the core or to the one mode.
     */
    public function testDerivedModesSendOnlyTheCorePlusWhatTheyUse(): void
    {
        $builder = new QueryBuilder($this->geoProfile(), null, [3]);
        $req = [
            'q' => 'river', 'page' => 4, 'per_page' => 7, 'facets' => ['type_s'],
            'filters' => ['type_s' => ['Book']], 'record_query' => true, 'analytics_id' => str_repeat('a', 32),
        ];
        $core = ['enable_analytics', 'q', 'query_by', 'filter_by', 'sort_by', 'max_candidates', 'search_cutoff_ms', 'stopwords', 'synonym_sets'];
        $highlights = ['highlight_fields', 'enable_highlight_v1', 'highlight_start_tag', 'highlight_end_tag', 'highlight_affix_num_tokens', 'highlight_full_fields'];
        $expected = [
            'count' => [$builder->countOnly($req), ['collection', 'per_page', 'include_fields', 'highlight_fields']],
            'recount' => [$builder->facetCountsFor($req, 'type_s'), ['collection', 'page', 'per_page', 'include_fields', 'facet_by', 'max_facet_values', 'highlight_fields']],
            'export' => [$builder->export($req, 1), ['page', 'per_page', 'exclude_fields', 'highlight_fields']],
            'map' => [$builder->map($req, 1), ['page', 'per_page', 'include_fields', 'exclude_fields', 'highlight_fields']],
            'union' => [$builder->union('river'), ['collection', 'exclude_fields', ...$highlights]],
        ];
        foreach ($expected as $mode => [$params, $extra]) {
            $keys = array_keys($params);
            sort($keys);
            $want = array_values(array_unique([...$core, ...$extra]));
            sort($want);
            self::assertSame($want, $keys, $mode);
            self::assertFalse($params['enable_analytics'], $mode . ' never counts as a visitor query');
        }
        // Only the live search shows a page of the visitor's choosing.
        self::assertSame([4, 7], [$builder->search($req)['page'], $builder->search($req)['per_page']]);
        self::assertSame([1, QueryBuilder::EXPORT_PER_PAGE], [$builder->export($req, 1)['page'], $builder->export($req, 1)['per_page']]);
        self::assertSame(0, $builder->countOnly($req)['per_page']);
        self::assertSame('none', $builder->export($req, 1)['highlight_fields']);
    }

    public function testABrowseCarriesNoStopwordsOrHighlights(): void
    {
        $builder = new QueryBuilder($this->profile());
        foreach ([$builder->search([]), $builder->countOnly([]), $builder->export([], 1), $builder->union('')] as $params) {
            self::assertSame('*', $params['q']);
            self::assertArrayNotHasKey('stopwords', $params);
            self::assertArrayNotHasKey('highlight_start_tag', $params);
        }
        self::assertSame('title:asc', $builder->union('')['sort_by']);
    }

    private function geoProfile(): \DRESearch\Settings\SearchProfile
    {
        return $this->profile(['display_fields' => [
            'geo' => ['property' => null, 'type' => 'geopoint', 'facet' => false, 'index' => true],
            'has_coords' => ['property' => null, 'type' => 'bool', 'facet' => true, 'index' => true],
        ]]);
    }

    /** Two sidebar facets + a filterable-but-not-faceted display field. */
    private function multiFacetProfile(): \DRESearch\Settings\SearchProfile
    {
        return $this->profile(['facets' => [
            'language_ss' => ['property' => 'dcterms:language', 'label' => 'Language', 'array' => true],
        ]]);
    }
}
