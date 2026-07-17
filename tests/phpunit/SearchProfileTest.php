<?php
declare(strict_types=1);

namespace DRESearch\Test;

use DRESearch\Settings\ProfileRegistry;
use DRESearch\Settings\SearchProfile;
use PHPUnit\Framework\TestCase;

final class SearchProfileTest extends TestCase
{
    use ProfileFixture;

    public function testUnknownExplicitProfileDoesNotFallBack(): void
    {
        $profile = $this->profile();
        $registry = new ProfileRegistry(['records' => $profile]);
        self::assertNull($registry->get('typo'));
        self::assertSame($profile, $registry->get(''));
    }

    public function testRejectsQueryFieldThatIsNotIndexed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profile(['query_by' => 'title,missing']);
    }

    public function testRejectsDuplicateCollectionAliases(): void
    {
        $config = [
            'label' => 'Records',
            'collection' => 'same_current',
            'kind' => 'item',
            'template_id' => 10,
            'query_by' => 'title',
            'date' => ['mode' => 'none'],
        ];
        $this->expectException(\InvalidArgumentException::class);
        ProfileRegistry::fromArray(['one' => $config, 'two' => $config]);
    }

    public function testShippedProfileConfigurationPassesTheStrictSchema(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $registry = ProfileRegistry::fromArray($config['dre_search']['profiles']);

        self::assertCount(12, $registry->all());
        self::assertSame('research_items', $registry->default()?->name());
    }
}
