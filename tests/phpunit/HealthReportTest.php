<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\HealthReport;
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
}
