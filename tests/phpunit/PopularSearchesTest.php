<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\AnalyticsSync;
use DRESearch\Search\BlockScopeResolver;
use DRESearch\Search\Exception\RequestValidationException;
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

    public function testTheListIsOffUnlessConfigured(): void
    {
        $proxy = $this->proxy([]);
        self::assertFalse($proxy->popularEnabled());
        self::assertSame(['available' => true, 'queries' => []], $proxy->popular('records'));
        $this->expectException(RequestValidationException::class);
        $proxy->popular('nope');
    }

    public function testPopularQueriesAreReadFromTheAnalyticsCollections(): void
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
            self::assertSame(['available' => true, 'queries' => ['kenya', 'bayreuth']], $proxy->popular('records'));
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
