<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\HealthReport;
use DRESearch\Search\TypesenseClientProvider;
use DRESearch\Search\TypesenseSearchKeyProvider;
use PHPUnit\Framework\TestCase;

final class HealthReportTest extends TestCase
{
    public function testReachableTypesenseAndCorpusStates(): void
    {
        $client = (object) ['health' => new class {
            /** @return array{ok:bool} */
            public function retrieve(): array
            {
                return ['ok' => true];
            }
        }];
        $report = (new HealthReport(null))->build(
            true,
            $client,
            ['research_items', 'research_people'],
            ['research_items' => ['ready' => true, 'exclude' => []], 'research_people' => ['ready' => false, 'exclude' => []]],
            static fn(): never => throw new \LogicException('No failure to report.'),
        );

        self::assertTrue($report['ok']);
        self::assertSame(['public' => 'live', 'pending' => 0], $report['profiles']['research_items']);
        self::assertSame('paused', $report['profiles']['research_people']['public']);
        self::assertNull($report['oldest_pending_seconds']);
        self::assertNull($report['worker'], 'No database: no worker to report.');
    }

    public function testAFailedProbeIsReportedToTheBreakerAndIsNotOk(): void
    {
        $failures = [];
        $client = (object) ['health' => new class {
            public function retrieve(): never
            {
                throw new \RuntimeException('refused');
            }
        }];
        $report = (new HealthReport(null))->build(true, $client, [], [], function (\Throwable $e) use (&$failures): void {
            $failures[] = $e->getMessage();
        });
        self::assertFalse($report['ok']);
        self::assertFalse($report['reachable']);
        self::assertSame(['refused'], $failures);

        $unconfigured = (new HealthReport(null))->build(false, null, [], [], static function (): void {
        });
        self::assertSame([false, false], [$unconfigured['ok'], $unconfigured['configured']]);
    }

    /**
     * When minting fails, public search falls back to the admin key: it still
     * works, so the probe stays ok, but it says which key is in use.
     */
    public function testTheProbeSaysWhichKeyPublicSearchUses(): void
    {
        $build = static fn(?bool $searchOnly): array => (new HealthReport(null))->build(
            false,
            null,
            [],
            [],
            static function (): void {
            },
            $searchOnly,
        );
        self::assertSame('search_only', $build(true)['search_key']);
        self::assertSame('admin', $build(false)['search_key']);
        self::assertNull($build(null)['search_key']);
    }

    /** The provider answers from the key provider it was given, without a request. */
    public function testTheClientProviderReportsTheAdminKeyFallback(): void
    {
        $provider = static fn(?\Closure $searchKeys, string $apiKey = 'admin'): TypesenseClientProvider
            => new TypesenseClientProvider('typesense', 8108, 'http', $apiKey, searchKeys: $searchKeys);

        self::assertTrue($provider(fn() => $this->keys('scoped', ['dre_items_current']))->usesSearchKey());
        // No alias to scope a key to: the admin key serves.
        self::assertFalse($provider(fn() => $this->keys('', []))->usesSearchKey());
        self::assertFalse($provider(null)->usesSearchKey(), 'No key provider: the admin key serves.');
        self::assertNull($provider(null, '')->usesSearchKey(), 'Unconfigured: no key at all.');
    }

    /** @param list<string> $aliases */
    private function keys(string $configured, array $aliases): TypesenseSearchKeyProvider
    {
        $logger = new \Laminas\Log\Logger();
        $logger->addWriter(new \Laminas\Log\Writer\Noop());
        return new TypesenseSearchKeyProvider(static fn(): ?object => null, new ArraySettings(), $logger, $aliases, $configured);
    }
}
