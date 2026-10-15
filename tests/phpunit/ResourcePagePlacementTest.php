<?php

declare(strict_types=1);

namespace DRESearch\Test;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DRESearch\Site\ResourcePagePlacement as Placement;
use DRESearch\Site\ResourcePageBlockLayout\ProjectRecords;
use PHPUnit\Framework\TestCase;

/** The install/upgrade migration that puts Project records on saved item pages. */
final class ResourcePagePlacementTest extends TestCase
{
    /** The DRE theme's defaults, as an administrator saves them. */
    private const SAVED = [
        'items' => [
            'left' => [],
            'main' => ['mediaEmbeds', 'values', 'itemSets', 'sitePages', 'mediaList', 'dreLinkedItemsDashboard', 'linkedResources'],
            'right' => ['citation'],
        ],
        'item_sets' => ['main' => ['values']],
        'media' => ['main' => ['mediaRender', 'values']],
    ];

    /** @return list<string>|null */
    private static function main(?array $blocks): ?array
    {
        return $blocks === null ? null : array_values($blocks['items']['main']);
    }

    public function testTheBlockGoesRightBeforeLinkedResources(): void
    {
        $placed = Placement::place(self::SAVED);
        self::assertSame(
            ['mediaEmbeds', 'values', 'itemSets', 'sitePages', 'mediaList', 'dreLinkedItemsDashboard', 'dreSearchProjectRecords', 'linkedResources'],
            self::main($placed),
        );
        self::assertNotNull($placed);
        self::assertSame([], $placed['items']['left']);
        self::assertSame(['citation'], $placed['items']['right']);
        self::assertSame(self::SAVED['item_sets'], $placed['item_sets']);
        self::assertSame(self::SAVED['media'], $placed['media'], 'Every other region and resource keeps what the administrator chose.');

        $recordAfter = ['items' => ['main' => ['linkedResources', 'values', 'mirador', 'sitePages']]];
        self::assertSame(
            ['dreSearchProjectRecords', 'linkedResources', 'values', 'mirador', 'sitePages'],
            self::main(Placement::place($recordAfter)),
            'Linked resources wins over the record blocks, wherever the administrator put it.',
        );
    }

    public function testWithoutLinkedResourcesTheBlockFollowsTheLastRecordBlockElseGoesLast(): void
    {
        self::assertSame(
            ['values', 'mirador', 'dreSearchProjectRecords', 'sitePages'],
            self::main(Placement::place(['items' => ['main' => ['values', 'mirador', 'sitePages']]])),
        );
        self::assertSame(
            ['sitePages', 'mediaLinks', 'dreSearchProjectRecords'],
            self::main(Placement::place(['items' => ['main' => ['sitePages', 'mediaLinks']]])),
        );
        $noItems = Placement::place(['media' => ['main' => ['mediaRender']]]);
        self::assertSame(['dreSearchProjectRecords'], self::main($noItems));
        self::assertNotNull($noItems);
        self::assertSame(['mediaRender'], $noItems['media']['main']);
    }

    public function testNothingChangesWhenTheBlockIsPresentOrNothingWasSaved(): void
    {
        $placed = Placement::place(self::SAVED);
        self::assertNull(Placement::place($placed), 'Placing twice changes nothing.');
        self::assertSame(Placement::STATE_PRESENT, Placement::state($placed));
        self::assertSame(Placement::STATE_MISSING, Placement::state(self::SAVED));

        $elsewhere = self::SAVED;
        $elsewhere['items']['right'][] = 'dreSearchProjectRecords';
        self::assertNull(Placement::place($elsewhere), 'A block an administrator moved to another region stays there.');

        foreach ([null, [], ''] as $unset) {
            self::assertNull(Placement::place($unset));
            self::assertSame(Placement::STATE_DEFAULT, Placement::state($unset), 'Omeka then uses the theme and module defaults.');
        }
    }

    public function testMalformedInputIsStandardisedAsCoreDoes(): void
    {
        $placed = Placement::place(['items' => ['main' => ['values', '', 'mediaList'], 'side' => 'not a list']]);
        self::assertSame(['values', 'mediaList', 'dreSearchProjectRecords'], self::main($placed));
        self::assertNotNull($placed);
        self::assertSame([], $placed['items']['side']);
    }

    /** The names the migration, the block class and the module defaults use agree. */
    public function testTheRegisteredNameIsTheOneEverywhere(): void
    {
        if (!interface_exists(\Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface::class)) {
            self::markTestSkipped('Requires Omeka core.');
        }
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        self::assertSame(Placement::BLOCK, ProjectRecords::NAME);
        self::assertArrayHasKey(Placement::BLOCK, $config['resource_page_block_layouts']['factories']);
        self::assertSame([Placement::BLOCK], $config['resource_page_blocks_default']['items']['main']);
    }

    public function testRepairUpdatesOnlySavedSitesThatLackTheBlock(): void
    {
        $db = $this->sites();
        $before = $this->settings($db);

        self::assertSame([1], Placement::repair($db, [1, 2, 3, 4]));
        $after = $this->settings($db);
        self::assertSame(
            ['mediaEmbeds', 'values', 'itemSets', 'sitePages', 'mediaList', 'dreLinkedItemsDashboard', 'dreSearchProjectRecords', 'linkedResources'],
            self::main($after[1]['resource_page_blocks']),
        );
        self::assertSame('kept', $after[1]['other_setting'], 'The rest of the theme settings survive.');
        self::assertSame($before[2], $after[2], 'A site that already has the block is untouched.');
        self::assertArrayNotHasKey(3, $after, 'A site that never saved theme settings keeps none.');
        self::assertSame($before[4], $after[4], 'A site that saved settings without blocks keeps the defaults.');
        self::assertSame($before[5], $after[5], 'A site outside the requested list is untouched.');

        self::assertSame([5], Placement::repair($db), 'A full repair reaches the remaining site.');
        self::assertSame([], Placement::repair($db), 'Idempotent.');
        self::assertSame(
            [1 => Placement::STATE_PRESENT, 2 => Placement::STATE_PRESENT, 3 => Placement::STATE_DEFAULT, 4 => Placement::STATE_DEFAULT, 5 => Placement::STATE_PRESENT],
            Placement::states($db),
        );
    }

    /**
     * Five sites: 1 saved without the block (theme id in mixed case, stored
     * lowercased as Omeka writes it), 2 saved with it, 3 never saved, 4 saved
     * other settings only, 5 saved without it.
     */
    private function sites(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE site (id INTEGER PRIMARY KEY, theme TEXT)');
        $db->executeStatement('CREATE TABLE site_setting (id TEXT, site_id INTEGER, value TEXT, PRIMARY KEY (id, site_id))');
        $with = self::SAVED;
        $with['items']['main'][] = 'dreSearchProjectRecords';
        foreach (
            [
                1 => ['DRE-theme', ['resource_page_blocks' => self::SAVED, 'other_setting' => 'kept']],
                2 => ['dre-theme', ['resource_page_blocks' => $with]],
                3 => ['default', null],
                4 => ['default', ['other_setting' => 'kept']],
                5 => ['dre-theme', ['resource_page_blocks' => ['items' => ['main' => ['values']]]]],
            ] as $id => [$theme, $settings]
        ) {
            $db->insert('site', ['id' => $id, 'theme' => $theme]);
            if ($settings !== null) {
                $db->insert('site_setting', [
                    'id' => strtolower('theme_settings_' . $theme),
                    'site_id' => $id,
                    'value' => json_encode($settings),
                ]);
            }
        }
        return $db;
    }

    /** @return array<int,array<string,mixed>> */
    private function settings(Connection $db): array
    {
        $out = [];
        foreach ($db->fetchAllAssociative('SELECT site_id, value FROM site_setting ORDER BY site_id') as $row) {
            $out[(int) $row['site_id']] = json_decode((string) $row['value'], true);
        }
        return $out;
    }
}
