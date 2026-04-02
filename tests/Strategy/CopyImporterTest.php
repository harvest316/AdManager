<?php

namespace AdManager\Tests\Strategy;

use AdManager\Strategy\CopyImporter;
use AdManager\DB;
use PHPUnit\Framework\TestCase;

class CopyImporterTest extends TestCase
{
    private CopyImporter $importer;

    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();
        DB::init();

        $db = DB::get();
        $db->exec("INSERT INTO projects (id, name, display_name, website_url) VALUES (1, 'test', 'Test Product', 'https://test.com')");

        $this->importer = new CopyImporter();
    }

    protected function tearDown(): void
    {
        DB::reset();
        putenv('ADMANAGER_DB_PATH');
    }

    private function makeCopyItems(int $count = 3): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = [
                'campaign_name' => 'Test Campaign',
                'ad_group_name' => 'Ad Group 1',
                'platform'      => 'google',
                'copy_type'     => 'headline',
                'content'       => "Get Customized Results {$i}",
                'pin_position'  => $i === 1 ? 1 : null,
            ];
        }
        return $items;
    }

    public function testImportCreatesRowsForEachMarket(): void
    {
        $items = $this->makeCopyItems(2);
        $count = $this->importer->import(1, $items, ['US', 'GB']);

        // Each item gets US + GB variants. "Customized" differs between US/GB so no 'all' variant.
        $this->assertGreaterThanOrEqual(4, $count);

        $db = DB::get();
        $rows = $db->query("SELECT * FROM ad_copy WHERE project_id = 1")->fetchAll();
        $this->assertCount($count, $rows);
    }

    public function testImportLocalisesSpelling(): void
    {
        $items = [[
            'campaign_name' => 'Test Campaign',
            'ad_group_name' => 'Ad Group 1',
            'platform'      => 'google',
            'copy_type'     => 'headline',
            'content'       => 'Customize Your Colors',
            'pin_position'  => null,
        ]];

        $this->importer->import(1, $items, ['US', 'GB']);

        $db = DB::get();
        $usRow = $db->query("SELECT content FROM ad_copy WHERE target_market = 'US'")->fetch();
        $gbRow = $db->query("SELECT content FROM ad_copy WHERE target_market = 'GB'")->fetch();

        // US keeps original spelling
        $this->assertStringContainsString('Customize', $usRow['content']);
        // GB gets localised spelling
        $this->assertStringContainsString('Customise', $gbRow['content']);
    }

    public function testImportCreatesMarketNeutralVariant(): void
    {
        // Content with no spelling differences between US and GB
        $items = [[
            'campaign_name' => 'Test Campaign',
            'ad_group_name' => null,
            'platform'      => 'google',
            'copy_type'     => 'headline',
            'content'       => 'Free Trial Today',
            'pin_position'  => null,
        ]];

        $this->importer->import(1, $items, ['US', 'GB']);

        $db = DB::get();
        $allRow = $db->query("SELECT * FROM ad_copy WHERE target_market = 'all'")->fetch();
        $this->assertNotFalse($allRow);
        $this->assertEquals('Free Trial Today', $allRow['content']);
    }

    public function testImportSetsCorrectFields(): void
    {
        $items = [[
            'campaign_name' => 'Brand Campaign',
            'ad_group_name' => 'Exact Match',
            'platform'      => 'meta',
            'copy_type'     => 'primary_text',
            'content'       => 'Try our product today.',
            'pin_position'  => null,
        ]];

        $this->importer->import(1, $items, ['US']);

        $db = DB::get();
        $row = $db->query("SELECT * FROM ad_copy WHERE target_market = 'US'")->fetch();

        $this->assertEquals(1, $row['project_id']);
        $this->assertEquals('Brand Campaign', $row['campaign_name']);
        $this->assertEquals('Exact Match', $row['ad_group_name']);
        $this->assertEquals('meta', $row['platform']);
        $this->assertEquals('primary_text', $row['copy_type']);
        $this->assertEquals('draft', $row['status']);
        $this->assertEquals('en', $row['language']);
    }

    public function testImportPreservesPinPosition(): void
    {
        $items = [[
            'campaign_name' => 'Test',
            'ad_group_name' => null,
            'platform'      => 'google',
            'copy_type'     => 'headline',
            'content'       => 'Pinned Headline',
            'pin_position'  => 1,
        ]];

        $this->importer->import(1, $items, ['US']);

        $db = DB::get();
        $row = $db->query("SELECT pin_position FROM ad_copy WHERE target_market = 'US'")->fetch();
        $this->assertEquals(1, $row['pin_position']);
    }

    public function testImportClearExistingDeletesPriorCopy(): void
    {
        $items = $this->makeCopyItems(2);
        $this->importer->import(1, $items, ['US']);

        $db = DB::get();
        $beforeCount = $db->query("SELECT COUNT(*) FROM ad_copy WHERE project_id = 1")->fetchColumn();
        $this->assertGreaterThan(0, $beforeCount);

        // Re-import with clearExisting
        $newItems = [[
            'campaign_name' => 'New Campaign',
            'ad_group_name' => null,
            'platform'      => 'google',
            'copy_type'     => 'headline',
            'content'       => 'Fresh Start',
            'pin_position'  => null,
        ]];

        $this->importer->import(1, $newItems, ['US'], [], true);

        // Old rows should be gone
        $oldCampaign = (int) $db->query("SELECT COUNT(*) FROM ad_copy WHERE campaign_name = 'Test Campaign'")->fetchColumn();
        $this->assertEquals(0, $oldCampaign);

        // New rows should exist
        $newCampaign = (int) $db->query("SELECT COUNT(*) FROM ad_copy WHERE campaign_name = 'New Campaign'")->fetchColumn();
        $this->assertGreaterThan(0, $newCampaign);
    }

    public function testImportWithMultipleMarkets(): void
    {
        $items = [[
            'campaign_name' => 'Global',
            'ad_group_name' => null,
            'platform'      => 'google',
            'copy_type'     => 'headline',
            'content'       => 'Optimize Performance',
            'pin_position'  => null,
        ]];

        $count = $this->importer->import(1, $items, ['US', 'GB', 'AU', 'CA']);

        $db = DB::get();
        $markets = $db->query("SELECT DISTINCT target_market FROM ad_copy ORDER BY target_market")->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains('US', $markets);
        $this->assertContains('GB', $markets);
        $this->assertContains('AU', $markets);
        $this->assertContains('CA', $markets);
    }

    public function testImportUnknownMarketDefaultsToEnglish(): void
    {
        $items = [[
            'campaign_name' => 'Test',
            'ad_group_name' => null,
            'platform'      => 'google',
            'copy_type'     => 'headline',
            'content'       => 'Test Headline',
            'pin_position'  => null,
        ]];

        // FR is not in GOOGLE_MARKETS but defaults to 'en' via null-coalesce
        $count = $this->importer->import(1, $items, ['FR']);

        $db = DB::get();
        $frRow = $db->query("SELECT * FROM ad_copy WHERE target_market = 'FR'")->fetch();
        $this->assertNotFalse($frRow);
        $this->assertEquals('en', $frRow['language']);
    }

    public function testImportWithEmptyCopyItems(): void
    {
        $count = $this->importer->import(1, [], ['US']);
        $this->assertEquals(0, $count);
    }

    public function testImportTranslationSkippedWithoutApiKey(): void
    {
        // Ensure OPENROUTER_API_KEY is not set
        $original = getenv('OPENROUTER_API_KEY');
        putenv('OPENROUTER_API_KEY');

        $items = [[
            'campaign_name' => 'Test',
            'ad_group_name' => null,
            'platform'      => 'google',
            'copy_type'     => 'headline',
            'content'       => 'Test Headline',
            'pin_position'  => null,
        ]];

        ob_start();
        $count = $this->importer->import(1, $items, ['US'], ['es']);
        $output = ob_get_clean();

        // Should have skipped translation
        $this->assertStringContainsString('OPENROUTER_API_KEY not set', $output);

        if ($original !== false) {
            putenv("OPENROUTER_API_KEY={$original}");
        }
    }

    public function testImportDescriptionCopyType(): void
    {
        $items = [[
            'campaign_name' => 'Test',
            'ad_group_name' => null,
            'platform'      => 'google',
            'copy_type'     => 'description',
            'content'       => 'This is a longer description that describes the product in detail.',
            'pin_position'  => null,
        ]];

        $this->importer->import(1, $items, ['US']);

        $db = DB::get();
        $row = $db->query("SELECT * FROM ad_copy WHERE copy_type = 'description'")->fetch();
        $this->assertNotFalse($row);
        $this->assertEquals('description', $row['copy_type']);
    }
}
