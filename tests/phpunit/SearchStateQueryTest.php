<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Search\SearchStateQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchStateQueryTest extends TestCase
{
    /** @return iterable<string,array{string,bool}> */
    public static function urls(): iterable
    {
        yield 'no query string' => ['', false];
        yield 'a query' => ['b42.q=kenya', true];
        yield 'an encoded query' => ['b42.q=C%C3%B4te+d%27Ivoire', true];
        yield 'an empty query' => ['b42.q=&b42.q=+', false];
        yield 'a facet filter' => ['b42.f.type_s=Text', true];
        yield 'a facet filter, percent-encoded key' => ['b42%2Ef.type_s=Text', true];
        yield 'a sort' => ['b42.sort=newest', true];
        yield 'a year window' => ['b42.date.from=1990', true];
        yield 'page 2' => ['b42.page=2', true];
        yield 'page 1' => ['b42.page=1', false];
        // The client keeps the server's page for any view.
        yield 'a view' => ['b42.view=gallery', false];
        yield 'another block' => ['b7.q=kenya&b7.f.type_s=Text', false];
        yield 'a block whose id starts the same' => ['b420.q=kenya', false];
        yield 'the host page' => ['q=kenya&page=3', false];
        yield 'among other params' => ['utm_source=x&b42.f.language_ss=French&page=2', true];
    }

    #[DataProvider('urls')]
    public function testOnlyTheBlocksOwnStateCounts(string $query, bool $carries): void
    {
        self::assertSame($carries, SearchStateQuery::carriesState($query, SearchStateQuery::blockPrefix(42)));
    }
}
