<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\DocumentAssembler;
use DRESearch\Indexer\OmekaSourceRepository;
use DRESearch\Indexer\SchemaProvider;
use DRESearch\Search\QueryBuilder;
use DRESearch\Search\ResponseNormalizer;
use DRESearch\Settings\SearchProfile;
use DRESearch\Test\Support\MapperFixtures;
use DRESearch\Test\Support\RecordingMapper;
use DRESearch\Test\Support\SqliteOmeka;
use PHPUnit\Framework\TestCase;

/**
 * Discovery through a private full text (`restricted_fulltext`): which texts
 * are read, that the policy stays off until an item set is named, and that a
 * response carries at most one capped excerpt of the text, never the text.
 */
final class RestrictedFulltextTest extends TestCase
{
    use MapperFixtures;
    use ProfileFixture;
    use SqliteOmeka;

    private const S = QueryBuilder::HL_START;
    private const E = QueryBuilder::HL_END;
    private const FIELD = SearchProfile::RESTRICTED_TEXT_FIELD;

    protected function setUp(): void
    {
        $this->createSqliteOmeka();
    }

    private function eligible(array $sets = [27724]): SearchProfile
    {
        return $this->profile(['restricted_fulltext' => ['property' => 'bibo:content', 'item_sets' => $sets]]);
    }

    public function testTheShippedPolicyIsDeclaredButOff(): void
    {
        $profile = $this->configProfile('research_items');

        self::assertSame(['property' => 'bibo:content', 'item_sets' => []], $profile->restrictedFulltext());
        self::assertFalse($profile->restrictedFulltextEnabled());
        self::assertNotContains(self::FIELD, explode(',', $profile->queryBy()));
        self::assertContains(self::FIELD, $profile->searchOnlyFields());
        self::assertNotContains('bibo:content', $profile->readProperties(), 'The public value read never asks for the text.');
        $fields = array_column((new SchemaProvider())->collection('guard', $profile)['fields'], null, 'name');
        self::assertSame(['name' => self::FIELD, 'type' => 'string', 'optional' => true], $fields[self::FIELD]);
    }

    public function testNamingAnItemSetSearchesTheTextLast(): void
    {
        self::assertSame('title,creator_ss,' . self::FIELD, $this->eligible()->queryBy());
        self::assertSame('title,creator_ss', $this->profile()->queryBy());
        self::assertNull($this->profile()->restrictedFulltext());
    }

    public function testTheFieldCannotBeQueriedOrDeclaredByHand(): void
    {
        foreach (
            [
                ['query_by' => 'title,' . self::FIELD, 'restricted_fulltext' => ['item_sets' => [1]]],
                ['restricted_fulltext' => ['item_sets' => [0]]],
                ['restricted_fulltext' => ['item_sets' => ['ilam']]],
                ['restricted_fulltext' => ['property' => 'content', 'item_sets' => [1]]],
                ['restricted_fulltext' => ['item_sets' => [1]], 'display_fields' => [self::FIELD => ['property' => 'bibo:content', 'type' => 'string']]],
                ['restricted_fulltext' => 'bibo:content'],
            ] as $overrides
        ) {
            try {
                $this->profile($overrides);
                self::fail('Accepted ' . json_encode($overrides));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testOnlyAPrivateLiteralOfAPublicEligibleRecordIsRead(): void
    {
        $this->item(1, 'Eligible', 10, [27724]);
        $this->literalValue(1, 'bibo:content', '   ');
        $this->literalValue(1, 'bibo:content', 'First private text', false);
        $this->literalValue(1, 'bibo:content', 'Second private text', false);
        $this->item(2, 'Public text only', 10, [27724]);
        $this->literalValue(2, 'bibo:content', 'Already public');
        $this->item(3, 'Outside the sets', 10, [99]);
        $this->literalValue(3, 'bibo:content', 'Not eligible', false);
        $this->item(4, 'Private record', 10, [27724], false);
        $this->literalValue(4, 'bibo:content', 'Hidden record', false);
        $this->item(5, 'Linked text', 10, [27724]);
        $this->linkValue(5, 'bibo:content', 2, false);
        $this->item(6, 'Other property', 10, [27724]);
        $this->literalValue(6, 'dcterms:description', 'Private description', false);

        $ids = [1, 2, 3, 4, 5, 6];
        self::assertSame([1 => 'First private text'], (new OmekaSourceRepository($this->db, $this->eligible()))->loadRestrictedTexts($ids));
        self::assertSame([], (new OmekaSourceRepository($this->db, $this->eligible([])))->loadRestrictedTexts($ids), 'No item set, nothing read.');
        self::assertSame([], (new OmekaSourceRepository($this->db, $this->profile()))->loadRestrictedTexts($ids));
    }

    public function testTheAssemblerLaysTheTextOnlyOntoEligibleDocuments(): void
    {
        $this->item(1, 'Eligible', 10, [27724]);
        $this->literalValue(1, 'bibo:content', 'Rhodes drums', false);
        $this->item(2, 'Ineligible', 10, [99]);
        $this->literalValue(2, 'bibo:content', 'Other drums', false);
        $profile = $this->eligible();
        $source = new OmekaSourceRepository($this->db, $profile);
        $mapper = new RecordingMapper();

        $docs = (new DocumentAssembler($source, $mapper, $profile))->documents($source->rows([1, 2]));

        self::assertSame('Rhodes drums', $docs[0][self::FIELD]);
        self::assertArrayNotHasKey(self::FIELD, $docs[1]);
        self::assertSame([[], []], array_column($mapper->mapped, 1), 'A mapper is still handed public values only.');
    }

    public function testEveryPayloadDropsTheTextWhateverTheProfileSays(): void
    {
        $req = ['q' => 'drums'];
        foreach ([$this->eligible(), $this->profile()] as $profile) {
            $builder = new QueryBuilder($profile);
            foreach (
                [
                    'search' => $builder->search($req),
                    'union' => $builder->union('drums'),
                    'export' => $builder->export($req, 1),
                    'map' => $builder->map($req, 1),
                ] as $mode => $params
            ) {
                self::assertContains(self::FIELD, explode(',', $params['exclude_fields']), $mode);
            }
            $suggest = $builder->suggest('dru');
            self::assertSame('title', $suggest['query_by'], 'Autocomplete stays on titles.');
            self::assertNotContains(self::FIELD, explode(',', $suggest['include_fields']));
        }
    }

    public function testTheTextIsSnippetedOnAQueryAndNeverOnABrowse(): void
    {
        $builder = new QueryBuilder($this->eligible());
        $search = $builder->search(['q' => 'drums']);

        self::assertContains(self::FIELD, explode(',', $search['query_by']));
        self::assertContains(self::FIELD, explode(',', $search['highlight_fields']));
        self::assertNotContains(self::FIELD, explode(',', $search['highlight_full_fields']));
        $browse = $builder->search(['q' => '']);
        self::assertArrayNotHasKey('highlight_fields', $browse);
        self::assertSame('*', $browse['q']);
        self::assertNotContains(self::FIELD, explode(',', (new QueryBuilder($this->eligible([])))->search(['q' => 'drums'])['highlight_fields']));
    }

    public function testAResponseCarriesOneCappedExcerptAndNeverTheText(): void
    {
        $text = str_repeat('Lorem ipsum dolor sit amet. ', 40) . 'The ' . self::S . 'mbira' . self::E . ' was tuned. ' . str_repeat('More words follow here. ', 40);
        $result = ResponseNormalizer::search(['found' => 1, 'hits' => [[
            'document' => ['id' => '1', 'title' => 'Record', self::FIELD => 'The whole private text'],
            'highlight' => [self::FIELD => [
                'matched_tokens' => ['mbira'],
                'snippet' => $text,
                'value' => $text,
                'snippets' => [$text, $text],
            ]],
        ]]], $this->eligible());

        $doc = $result['hits'][0];
        self::assertArrayNotHasKey(self::FIELD, $doc);
        self::assertSame([self::FIELD], array_keys($doc['_highlights']));
        self::assertCount(1, $doc['_highlights'][self::FIELD]);
        $excerpt = $doc['_highlights'][self::FIELD][0];
        self::assertLessThanOrEqual(ResponseNormalizer::RESTRICTED_EXCERPT_MAX, mb_strlen($excerpt));
        self::assertStringContainsString(self::S . 'mbira' . self::E, $excerpt);
        self::assertStringNotContainsString('matched_tokens', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testTheExcerptPrefersTheSentenceHoldingTheMatch(): void
    {
        $snippet = "words before. The\nsound of the " . self::S . 'mbira' . self::E . " carried across\tthe valley at dusk. Next sentence";

        self::assertSame(
            '…The sound of the ' . self::S . 'mbira' . self::E . ' carried across the valley at dusk.…',
            ResponseNormalizer::restrictedExcerpt($snippet),
        );
    }

    public function testATooShortSentenceKeepsTheWordWindow(): void
    {
        $snippet = 'Vol. 3. ' . self::S . 'Drums' . self::E . '. of the Shona';

        self::assertSame('Vol. 3. ' . self::S . 'Drums' . self::E . '. of the Shona', ResponseNormalizer::restrictedExcerpt($snippet));
    }

    public function testLongWordsAndOcrRunsAreCutAtTheCap(): void
    {
        $max = ResponseNormalizer::RESTRICTED_EXCERPT_MAX;
        foreach (
            [
                'one long run' => str_repeat('x', 500) . self::S . 'drum' . self::E . str_repeat('y', 500),
                'a long match' => 'before ' . self::S . str_repeat('z', 500) . self::E . ' after',
                'two matches' => str_repeat('word ', 60) . self::S . 'drum' . self::E . str_repeat(' word', 60) . ' ' . self::S . 'drum' . self::E . str_repeat(' tail', 60),
            ] as $case => $snippet
        ) {
            $excerpt = ResponseNormalizer::restrictedExcerpt($snippet);
            self::assertNotNull($excerpt, $case);
            self::assertLessThanOrEqual($max, mb_strlen($excerpt), $case);
            self::assertSame(substr_count($excerpt, self::S), substr_count($excerpt, self::E), "$case: every mark is closed");
            self::assertStringContainsString(self::S, $excerpt, $case);
        }
    }

    public function testAnUnmarkedSnippetIsNeverQuoted(): void
    {
        self::assertNull(ResponseNormalizer::restrictedExcerpt('the whole short private text'));
        self::assertSame([], ResponseNormalizer::highlights([self::FIELD => ['snippet' => 'no match here', 'value' => 'x' . self::S . 'y' . self::E]]));
    }
}
