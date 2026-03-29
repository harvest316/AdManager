<?php

namespace AdManager\Tests\Optimise;

use AdManager\Optimise\CopyRefresher;
use AdManager\Copy\Store as CopyStore;
use AdManager\DB;
use PHPUnit\Framework\TestCase;

class CopyRefresherTest extends TestCase
{
    private CopyRefresher $refresher;
    private CopyStore $store;

    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();
        DB::init();

        $db = DB::get();
        $db->exec("INSERT INTO projects (id, name, display_name, website_url) VALUES (1, 'test', 'Test Product', 'https://test.com')");
        $db->exec("INSERT INTO strategies (id, project_id, name, platform, campaign_type, full_strategy, model) VALUES (1, 1, 'Test Strategy', 'google', 'search', 'test', 'opus')");

        $this->refresher = new CopyRefresher();
        $this->store = new CopyStore();
    }

    protected function tearDown(): void
    {
        DB::reset();
        putenv('ADMANAGER_DB_PATH');
    }

    private function seedCampaignCopy(int $count = 15, int $weakCount = 3): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = [
                'platform'      => 'google',
                'campaign_name' => 'Test-Campaign',
                'ad_group_name' => null,
                'copy_type'     => 'headline',
                'content'       => "Headline Number {$i}",
                'char_limit'    => 30,
                'pin_position'  => $i === 1 ? 1 : null,
            ];
        }

        $ids = $this->store->bulkInsert(1, 1, $items);

        // Approve all and set QA scores
        foreach ($ids as $j => $id) {
            $this->store->approve($id);
            $score = $j < $weakCount ? 55 : 85; // First N are weak
            $this->store->updateQA($id, $score < 70 ? 'warning' : 'pass', [], $score);
        }

        return $ids;
    }

    public function testIdentifyWeakCopyByQAScore(): void
    {
        $this->seedCampaignCopy(10, 3);

        $result = $this->refresher->identifyWeakCopy(1, 'Test-Campaign');

        $this->assertCount(3, $result['weak']);
        $this->assertCount(7, $result['strong']);

        foreach ($result['weak'] as $w) {
            $this->assertLessThan(70, $w['qa_score']);
        }
        foreach ($result['strong'] as $s) {
            $this->assertGreaterThanOrEqual(70, $s['qa_score']);
        }
    }

    public function testIdentifyWeakCopyEmptyCampaign(): void
    {
        $result = $this->refresher->identifyWeakCopy(1, 'Nonexistent');
        $this->assertEmpty($result['weak']);
        $this->assertEmpty($result['strong']);
    }

    public function testIdentifyWeakCopyNoWeakItems(): void
    {
        $this->seedCampaignCopy(5, 0); // All strong

        $result = $this->refresher->identifyWeakCopy(1, 'Test-Campaign');
        $this->assertEmpty($result['weak']);
        $this->assertCount(5, $result['strong']);
    }

    public function testIdentifyWeakCopyAllWeak(): void
    {
        $this->seedCampaignCopy(5, 5); // All weak

        $result = $this->refresher->identifyWeakCopy(1, 'Test-Campaign');
        $this->assertCount(5, $result['weak']);
        $this->assertEmpty($result['strong']);
    }

    public function testRefreshReturnsZeroWhenNoWeakCopy(): void
    {
        $this->seedCampaignCopy(5, 0);

        $result = $this->refresher->refresh(1, 'Test-Campaign');
        $this->assertEquals(0, $result['weak_found']);
        $this->assertEquals(0, $result['generated']);
    }

    public function testRefreshReturnsZeroForMissingProject(): void
    {
        $result = $this->refresher->refresh(999, 'Test-Campaign');
        $this->assertEquals(0, $result['generated']);
    }

    public function testRefreshAllReturnsEmptyWhenNoApprovedCopy(): void
    {
        // No copy at all
        $results = $this->refresher->refreshAll(1);
        $this->assertEmpty($results);
    }

    public function testRefreshAllFindsAllCampaigns(): void
    {
        // Seed two campaigns
        $items = [];
        for ($i = 1; $i <= 5; $i++) {
            $items[] = [
                'platform' => 'google', 'campaign_name' => 'Campaign-A',
                'ad_group_name' => null, 'copy_type' => 'headline',
                'content' => "A Headline {$i}", 'char_limit' => 30, 'pin_position' => null,
            ];
            $items[] = [
                'platform' => 'google', 'campaign_name' => 'Campaign-B',
                'ad_group_name' => null, 'copy_type' => 'headline',
                'content' => "B Headline {$i}", 'char_limit' => 30, 'pin_position' => null,
            ];
        }
        $ids = $this->store->bulkInsert(1, 1, $items);
        foreach ($ids as $id) $this->store->approve($id);

        $results = $this->refresher->refreshAll(1);
        $this->assertArrayHasKey('Campaign-A', $results);
        $this->assertArrayHasKey('Campaign-B', $results);
    }

    public function testClassifyByQAScoreLabels(): void
    {
        $method = new \ReflectionMethod(CopyRefresher::class, 'classifyByQAScore');
        $method->setAccessible(true);

        $items = [
            ['id' => 1, 'content' => 'Weak One', 'qa_score' => 45],
            ['id' => 2, 'content' => 'Medium One', 'qa_score' => 75],
            ['id' => 3, 'content' => 'Strong One', 'qa_score' => 90],
            ['id' => 4, 'content' => 'No Score', 'qa_score' => null],
        ];

        $result = $method->invoke($this->refresher, $items);

        $this->assertCount(1, $result['weak']); // Only score 45
        $this->assertCount(3, $result['strong']); // 75, 90, null

        $this->assertEquals('Low', $result['weak'][0]['label']);
        $this->assertEquals('Good', $result['strong'][0]['label']); // 75
        $this->assertEquals('Best', $result['strong'][1]['label']); // 90
    }
}
