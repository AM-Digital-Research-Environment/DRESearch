<?php
declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\ImportResult;
use PHPUnit\Framework\TestCase;

final class ImportResultTest extends TestCase
{
    public function testParsesJsonLinesAndPromotesOnlyExplicitSuccess(): void
    {
        $result = ImportResult::fromResponse(
            "{\"success\":true}\n{\"success\":false,\"error\":\"bad schema\"}\n",
            [['id' => '1'], ['id' => '2']],
        );

        self::assertSame(1, $result->successful());
        self::assertSame(1, $result->failed());
        self::assertSame(['2'], $result->failedIds());
        self::assertSame(['bad schema'], $result->errors());
        self::assertFalse($result->isComplete());
    }

    public function testMissingResponseRowsAreFailures(): void
    {
        $result = ImportResult::fromResponse([['success' => true]], [['id' => '1'], ['id' => '2']]);
        self::assertSame(1, $result->failed());
        self::assertSame(['2'], $result->failedIds());
    }
}
