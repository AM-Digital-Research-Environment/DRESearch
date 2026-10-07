<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\AnalyticsSync;
use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\Exception\RequestValidationException;
use DRESearch\Search\PopularAnalytics;
use DRESearch\Search\PopularSearches;
use DRESearch\Search\SearchProxy;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Settings\ProfileRegistry;
use PHPUnit\Framework\TestCase;

final class PopularSearchesTest extends TestCase
{
    use ProfileFixture;

    public function testOnlyRepeatedQueriesThatFoundSomethingAreShown(): void
    {
        $popular = [
            ['q' => 'Islam', 'count' => 40],
            ['q' => ' islam ', 'count' => 12],
            ['q' => 'Ivory   Coast', 'count' => 9],
            ['q' => 'xyzzy', 'count' => 8],
            ['q' => 'Swahili', 'count' => 2],
        ];
        self::assertSame(
            ['Islam', 'Ivory Coast'],
            PopularSearches::select($popular, ['XYZZY'], 5, 5),
            'Deduplicated case-insensitively, whitespace collapsed, no-hit and rare queries dropped.',
        );
        self::assertSame(['Islam'], PopularSearches::select($popular, [], 5, 1));
    }

    public function testQueriesShapedLikePersonalDataAreNeverShown(): void
    {
        $rows = array_map(static fn(string $q): array => ['q' => $q, 'count' => 50], [
            'jane.doe@example.org',
            'https://example.org/private',
            'www.example.org',
            '+49 92155 41234',
            'id 0123456',
            "tab\tchar",
            "\u{202E}reversed",
            str_repeat('a', PopularSearches::MAX_LENGTH + 1),
            'x',
            "bad \xC3\x28 utf8",
            'Mau Mau 1952',
        ]);
        self::assertSame(['tab char', 'Mau Mau 1952'], PopularSearches::select($rows, [], 1, 10));
    }

    public function testOnlyApprovedCandidatesAreServedInTheApprovedSpelling(): void
    {
        $candidates = PopularSearches::candidates([
            ['q' => 'spam one', 'count' => 90],
            ['q' => 'spam two', 'count' => 80],
            ['q' => 'Ivory   Coast', 'count' => 40],
            ['q' => 'kenya', 'count' => 30],
            ['q' => 'rare', 'count' => 2],
        ], [], 5);
        self::assertSame(
            [['q' => 'spam one', 'count' => 90], ['q' => 'spam two', 'count' => 80], ['q' => 'Ivory Coast', 'count' => 40], ['q' => 'kenya', 'count' => 30]],
            $candidates,
            'Candidates keep their counts for the moderation list.',
        );
        self::assertSame(
            array_column($candidates, 'q'),
            PopularSearches::select([['q' => 'spam one', 'count' => 90], ['q' => 'spam two', 'count' => 80], ['q' => 'Ivory   Coast', 'count' => 40], ['q' => 'kenya', 'count' => 30], ['q' => 'rare', 'count' => 2]], [], 5, 10),
        );
        $approved = [
            PopularSearches::key('ivory coast') => 'Ivory Coast',
            PopularSearches::key('Kenya') => 'Kenya',
            PopularSearches::key('rare') => 'rare',
        ];
        self::assertSame(
            ['Ivory Coast', 'Kenya'],
            PopularSearches::approved($candidates, $approved, 5),
            'Unapproved queries are skipped however often they ran, the limit counts only approved ones, a non-candidate approval is not served, and the approved spelling is shown.',
        );
        self::assertSame(['Ivory Coast'], PopularSearches::approved($candidates, $approved, 1));
        self::assertSame([], PopularSearches::approved($candidates, [], 5));
    }

    public function testAnalyticsArePagedThroughSoSpamCannotPushApprovedQueriesOutOfReach(): void
    {
        // 1,300 recorded queries (more than analytics keep) and three no-hits.
        $multiSearch = new class {
            /** @var list<list<array<string,mixed>>> */
            public array $calls = [];

            /**
             * @param array{searches:list<array<string,mixed>>} $body
             * @param array<string,mixed> $params
             * @return array{results:list<array{found:int,hits:list<array{document:array{q:string,count:int}}>}>}
             */
            public function perform(array $body, array $params = []): array
            {
                $this->calls[] = $body['searches'];
                $results = [];
                foreach ($body['searches'] as $search) {
                    $popular = str_ends_with((string) $search['collection'], '_popular');
                    $found = $popular ? 1300 : 3;
                    $hits = [];
                    for ($i = ((int) $search['page'] - 1) * 250; $i < min($found, (int) $search['page'] * 250); $i++) {
                        $hits[] = ['document' => ['q' => ($popular ? 'q' : 'none') . $i, 'count' => 10_000 - $i]];
                    }
                    $results[] = ['found' => $found, 'hits' => $hits];
                }
                return ['results' => $results];
            }
        };
        $analytics = PopularAnalytics::fetch((object) ['multiSearch' => $multiSearch], 'records', 5);
        self::assertTrue($analytics['available']);
        self::assertCount(1000, $analytics['popular'], 'Every query analytics keep is read, not just the top page.');
        self::assertSame('q999', $analytics['popular'][999]['q']);
        self::assertSame(['none0', 'none1', 'none2'], $analytics['nohits']);
        [$first, $rest] = $multiSearch->calls + [[], []];
        self::assertCount(2, $multiSearch->calls, 'One request for the first pages, one for the rest.');
        self::assertSame([2, 3, 4], array_column($rest, 'page'));
        self::assertSame('count:>=5', $first[0]['filter_by'] ?? null);
        self::assertArrayNotHasKey('filter_by', $first[1] ?? []);
    }

    public function testTheListIsOffUnlessConfigured(): void
    {
        $proxy = $this->proxy([]);
        self::assertFalse($proxy->popularEnabled());
        self::assertSame(['available' => true, 'queries' => []], $proxy->popular('records'));
        $this->expectException(RequestValidationException::class);
        $proxy->popular('nope');
    }

    public function testCandidatesAreReadFromTheAnalyticsCollectionsButNeedApproval(): void
    {
        if (!getenv('TYPESENSE_HOST')) {
            self::markTestSkipped('Requires disposable Typesense.');
        }
        $provider = new TypesenseClientProvider(
            (string) getenv('TYPESENSE_HOST'),
            (int) (getenv('TYPESENSE_PORT') ?: 8108),
            'http',
            (string) getenv('TYPESENSE_API_KEY'),
        );
        $client = $provider->getClient();
        self::assertNotNull($client);
        $collections = [];
        foreach (['popular', 'nohits'] as $suffix) {
            $name = AnalyticsSync::collectionName('records', $suffix);
            try {
                $client->collections[$name]->delete();
            } catch (\Throwable) {
            }
            $client->collections->create(['name' => $name, 'fields' => [
                ['name' => 'q', 'type' => 'string'],
                ['name' => 'count', 'type' => 'int32'],
            ]]);
            $collections[] = $name;
        }
        try {
            $client->collections[$collections[0]]->documents->import([
                ['id' => 'a', 'q' => 'kenya', 'count' => 30],
                ['id' => 'b', 'q' => 'zanzibar', 'count' => 3],
                ['id' => 'c', 'q' => 'nothing here', 'count' => 20],
                ['id' => 'd', 'q' => 'bayreuth', 'count' => 12],
            ]);
            $client->collections[$collections[1]]->documents->import([
                ['id' => 'a', 'q' => 'nothing here', 'count' => 20],
            ]);
            $proxy = $this->proxy(['enabled' => true, 'min_count' => 5, 'limit' => 5], $provider);
            self::assertTrue($proxy->popularEnabled());
            self::assertSame(
                ['available' => true, 'queries' => []],
                $proxy->popular('records'),
                'Without the moderation table nothing is approved, so nothing is served.',
            );
            $analytics = PopularAnalytics::fetch($client, 'records', 5);
            self::assertSame(
                ['kenya', 'bayreuth'],
                PopularSearches::select($analytics['popular'], $analytics['nohits'], 5, 5),
                'The candidates: run five times or more and found something.',
            );
        } finally {
            foreach ($collections as $name) {
                $client->collections[$name]->delete();
            }
        }
    }

    /** @param array<string,mixed> $config */
    private function proxy(array $config, ?TypesenseClientProvider $provider = null): SearchProxy
    {
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter(new \Laminas\Log\Writer\Noop());
        return new SearchProxy(
            $provider ?? new TypesenseClientProvider('', 0, 'http', ''),
            new ProfileRegistry(['records' => $this->profile()]),
            new BlockScopeResolver(\Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])),
            $logger,
            [],
            null,
            null,
            $config,
        );
    }
}
