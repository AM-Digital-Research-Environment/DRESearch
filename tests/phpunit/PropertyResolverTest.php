<?php

declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Indexer\OmekaSourceRepository;
use DRESearch\Indexer\PropertyResolver;
use DRESearch\Indexer\ScopeMatcher;
use DRESearch\Settings\ProfileRegistry;
use DRESearch\Test\Support\SqliteOmeka;
use PHPUnit\Framework\TestCase;

/** Property ids by term: one query for many terms, and never asked twice. */
final class PropertyResolverTest extends TestCase
{
    use ProfileFixture;
    use SqliteOmeka;

    protected function setUp(): void
    {
        $this->createSqliteOmeka();
    }

    public function testTermsResolveByPrefixAndLocalNameOnceEach(): void
    {
        $title = $this->term('dcterms:title');
        $type = $this->term('dcterms:type');
        $lat = $this->term('geo:lat');
        $resolver = new PropertyResolver($this->db);

        self::assertSame(
            ['dcterms:type' => $type, 'geo:lat' => $lat],
            $resolver->ids(['dcterms:type', 'geo:lat', 'bibo:nowhere']),
            'A term the instance does not have is left out.',
        );
        self::assertSame(['dcterms:title' => $title], $resolver->ids(['dcterms:title']));

        // Every answer is remembered, the unknown term's too: with the tables
        // gone, nothing can be asked again.
        $this->db->executeStatement('DROP TABLE property');
        self::assertSame($type, $resolver->id('dcterms:type'));
        self::assertSame($title, $resolver->id('dcterms:title'));
        self::assertNull($resolver->id('bibo:nowhere'));
        self::assertSame([], $resolver->ids([]));
    }

    public function testOneResolverServesTheSourceAndTheScopeMatcher(): void
    {
        $profile = $this->profile(['extra_sources' => [['template_id' => 2, 'require_property' => 'geo:lat']]]);
        $this->item(1, 'Record', 10);
        $this->literalValue(1, 'dcterms:creator', 'Doe, Jane');
        $this->item(5, 'Geocoded institution', 2);
        $this->literalValue(5, 'geo:lat', '6.4');
        $resolver = new PropertyResolver($this->db);
        $source = new OmekaSourceRepository($this->db, $profile, $resolver);
        self::assertSame(['1', '5'], array_map('strval', array_column($source->rows(after: 0), 'id')));
        self::assertSame('Doe, Jane', $source->loadValues([1])[1]['dcterms:creator'][0]['value']);

        $this->db->executeStatement('DROP TABLE vocabulary');
        $matcher = new ScopeMatcher($this->db, new ProfileRegistry(['records' => $profile]), $resolver);
        self::assertSame(['records' => [1, 5]], $matcher->match([1, 5, 6]), 'The scope reuses the ids the source resolved.');
    }
}
