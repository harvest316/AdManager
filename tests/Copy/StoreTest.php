<?php

namespace AdManager\Tests\Copy;

use AdManager\Copy\Store;
use AdManager\DB;
use PHPUnit\Framework\TestCase;

class StoreTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        // Use in-memory SQLite for test isolation
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();
        DB::init();

        $this->store = new Store();

        // Seed a project and strategy
        $db = DB::get();
        $db->exec("INSERT INTO projects (id, name, display_name, website_url) VALUES (1, 'test', 'Test Product', 'https://test.com')");
        $db->exec("INSERT INTO strategies (id, project_id, name, platform, campaign_type, full_strategy, model) VALUES (1, 1, 'Test Strategy', 'google', 'search', 'test', 'opus')");
    }

    protected function tearDown(): void
    {
        DB::reset();
        putenv('ADMANAGER_DB_PATH');
    }

    private function sampleItems(): array
    {
        return [
            [
                'platform'      => 'google',
                'campaign_name' => 'Test-Campaign',
                'ad_group_name' => null,
                'copy_type'     => 'headline',
                'content'       => 'Test Headline One',
                'char_limit'    => 30,
                'pin_position'  => 1,
            ],
            [
                'platform'      => 'google',
                'campaign_name' => 'Test-Campaign',
                'ad_group_name' => null,
                'copy_type'     => 'headline',
                'content'       => 'Test Headline Two',
                'char_limit'    => 30,
                'pin_position'  => null,
            ],
            [
                'platform'      => 'google',
                'campaign_name' => 'Test-Campaign',
                'ad_group_name' => null,
                'copy_type'     => 'description',
                'content'       => 'This is a test description for the ad.',
                'char_limit'    => 90,
                'pin_position'  => null,
            ],
        ];
    }

    public function testBulkInsertReturnsIds(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $this->assertCount(3, $ids);
        $this->assertContainsOnly('int', $ids);
    }

    public function testGetByIdReturnsItem(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $item = $this->store->getById($ids[0]);
        $this->assertNotNull($item);
        $this->assertEquals('Test Headline One', $item['content']);
        $this->assertEquals(1, $item['pin_position']);
    }

    public function testGetByIdReturnsNullForMissing(): void
    {
        $this->assertNull($this->store->getById(999));
    }

    public function testListByProject(): void
    {
        $this->store->bulkInsert(1, 1, $this->sampleItems());
        $items = $this->store->listByProject(1);
        $this->assertCount(3, $items);
    }

    public function testListByProjectWithStatusFilter(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $this->store->approve($ids[0]);

        $approved = $this->store->listByProject(1, 'approved');
        $this->assertCount(1, $approved);
        $this->assertEquals($ids[0], $approved[0]['id']);
    }

    public function testGetByCampaign(): void
    {
        $this->store->bulkInsert(1, 1, $this->sampleItems());
        $items = $this->store->getByCampaign(1, 'Test-Campaign');
        $this->assertCount(3, $items);
    }

    public function testGetApprovedForCampaign(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $this->store->approve($ids[0]);
        $this->store->approve($ids[1]);

        $approved = $this->store->getApprovedForCampaign(1, 'Test-Campaign', 'headline');
        $this->assertCount(2, $approved);
    }

    public function testApproveAndUnapprove(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $this->store->approve($ids[0]);

        $item = $this->store->getById($ids[0]);
        $this->assertEquals('approved', $item['status']);

        $this->store->unapprove($ids[0]);
        $item = $this->store->getById($ids[0]);
        $this->assertEquals('draft', $item['status']);
    }

    public function testReject(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $this->store->reject($ids[0], 'Too generic');

        $item = $this->store->getById($ids[0]);
        $this->assertEquals('rejected', $item['status']);
        $this->assertEquals('Too generic', $item['rejected_reason']);
    }

    public function testAddFeedback(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $this->store->addFeedback($ids[0], 'Make it more benefit-driven');

        $item = $this->store->getById($ids[0]);
        $this->assertEquals('feedback', $item['status']);
        $this->assertEquals('Make it more benefit-driven', $item['feedback']);
    }

    public function testFlag(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $this->store->approve($ids[0]);
        $this->store->flag($ids[0], '{"reason": "policy changed"}');

        $item = $this->store->getById($ids[0]);
        $this->assertEquals('flagged', $item['status']);
    }

    public function testUpdateQA(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $issues = [['category' => 'char_limit', 'severity' => 'fail', 'description' => 'Too long']];
        $this->store->updateQA($ids[0], 'fail', $issues, 35);

        $item = $this->store->getById($ids[0]);
        $this->assertEquals('fail', $item['qa_status']);
        $this->assertEquals(35, $item['qa_score']);
        $decoded = json_decode($item['qa_issues'], true);
        $this->assertCount(1, $decoded);
    }

    public function testCountByStatus(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $this->store->approve($ids[0]);
        $this->store->reject($ids[1], 'bad');

        $counts = $this->store->countByStatus(1);
        $this->assertEquals(1, $counts['approved']);
        $this->assertEquals(1, $counts['rejected']);
        $this->assertEquals(1, $counts['draft']);
    }

    public function testExistsForStrategy(): void
    {
        $this->assertFalse($this->store->existsForStrategy(1));
        $this->store->bulkInsert(1, 1, $this->sampleItems());
        $this->assertTrue($this->store->existsForStrategy(1));
    }

    public function testDeleteForStrategy(): void
    {
        $this->store->bulkInsert(1, 1, $this->sampleItems());
        $deleted = $this->store->deleteForStrategy(1);
        $this->assertEquals(3, $deleted);
        $this->assertFalse($this->store->existsForStrategy(1));
    }

    public function testGetApprovedByPlatform(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $this->store->approve($ids[0]);

        $approved = $this->store->getApprovedByPlatform('google');
        $this->assertCount(1, $approved);

        $approved = $this->store->getApprovedByPlatform('meta');
        $this->assertEmpty($approved);
    }

    public function testListByProjectWithCopyTypeFilter(): void
    {
        $this->store->bulkInsert(1, 1, $this->sampleItems());
        $headlines = $this->store->listByProject(1, null, 'headline');
        $this->assertCount(2, $headlines);

        $descriptions = $this->store->listByProject(1, null, 'description');
        $this->assertCount(1, $descriptions);
    }

    public function testListByProjectWithPlatformFilter(): void
    {
        $this->store->bulkInsert(1, 1, $this->sampleItems());
        $google = $this->store->listByProject(1, null, null, 'google');
        $this->assertCount(3, $google);

        $meta = $this->store->listByProject(1, null, null, 'meta');
        $this->assertEmpty($meta);
    }

    public function testListByProjectWithMultipleFilters(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $this->store->approve($ids[0]);

        $result = $this->store->listByProject(1, 'approved', 'headline', 'google');
        $this->assertCount(1, $result);
    }

    public function testSetStatus(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $this->store->setStatus($ids[0], 'proofread');
        $item = $this->store->getById($ids[0]);
        $this->assertEquals('proofread', $item['status']);
    }

    public function testSetStatusFlagged(): void
    {
        $ids = $this->store->bulkInsert(1, 1, $this->sampleItems());
        $this->store->setStatus($ids[0], 'flagged');
        $item = $this->store->getById($ids[0]);
        $this->assertEquals('flagged', $item['status']);
    }

    public function testBulkInsertWithLanguageAndMarket(): void
    {
        $items = [[
            'platform' => 'google',
            'campaign_name' => 'Test',
            'ad_group_name' => null,
            'copy_type' => 'headline',
            'content' => 'Test DE',
            'char_limit' => 30,
            'pin_position' => null,
            'language' => 'de',
            'target_market' => 'DE',
        ]];
        $ids = $this->store->bulkInsert(1, 1, $items);
        $item = $this->store->getById($ids[0]);
        $this->assertEquals('de', $item['language']);
        $this->assertEquals('DE', $item['target_market']);
    }
}
