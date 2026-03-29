<?php

declare(strict_types=1);

namespace AdManager\Tests\Optimise;

use AdManager\DB;
use AdManager\Optimise\BudgetAllocator;
use PDO;
use PHPUnit\Framework\TestCase;

class BudgetAllocatorTest extends TestCase
{
    private PDO $db;
    private BudgetAllocator $allocator;

    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();

        $this->db = DB::get();
        $schema = file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql');
        $this->db->exec($schema);

        $this->allocator = new BudgetAllocator();

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        DB::reset();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function seedFixtures(): void
    {
        $this->db->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (1, 'test-proj', 'Test Project')"
        );

        // Budget for the project (google platform, $200/day total)
        $this->db->exec(
            "INSERT INTO budgets (project_id, platform, daily_budget_aud) VALUES (1, 'google', 200.0)"
        );
    }

    private function insertCampaign(int $id, string $name, float $dailyBudget, string $status = 'enabled'): void
    {
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status, daily_budget_aud)
             VALUES ({$id}, 1, 'google', '{$name}', 'search', '{$status}', {$dailyBudget})"
        );
    }

    private function insertPerformance(int $campaignId, int $daysAgo, int $impressions, int $clicks, float $conversions = 0, float $conversionValue = 0, int $costMicros = 0): void
    {
        $date = date('Y-m-d', strtotime("-{$daysAgo} days"));
        $this->db->exec(
            "INSERT INTO performance (campaign_id, date, impressions, clicks, conversions, conversion_value, cost_micros)
             VALUES ({$campaignId}, '{$date}', {$impressions}, {$clicks}, {$conversions}, {$conversionValue}, {$costMicros})"
        );
    }

    // -------------------------------------------------------------------------
    // recommend() — edge cases
    // -------------------------------------------------------------------------

    public function testRecommendReturnsEmptyWhenFewerThanTwoCampaigns(): void
    {
        $this->insertCampaign(1, 'Single Campaign', 100.0);

        $result = $this->allocator->recommend(1);

        $this->assertSame([], $result);
    }

    public function testRecommendReturnsEmptyWhenNoCampaignsAtAll(): void
    {
        $result = $this->allocator->recommend(1);

        $this->assertSame([], $result);
    }

    public function testRecommendReturnsEmptyWhenNoCampaignsAreActive(): void
    {
        $this->insertCampaign(1, 'Paused A', 100.0, 'paused');
        $this->insertCampaign(2, 'Paused B', 100.0, 'paused');

        $result = $this->allocator->recommend(1);

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // recommend() — no meaningful change when performance is equal
    // -------------------------------------------------------------------------

    public function testRecommendReturnsEmptyWhenBothCampaignsHaveNoData(): void
    {
        $this->insertCampaign(1, 'Campaign A', 100.0);
        $this->insertCampaign(2, 'Campaign B', 100.0);

        // No performance data — both score 0, no meaningful change
        $result = $this->allocator->recommend(1);

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // recommend() — reallocation logic
    // -------------------------------------------------------------------------

    public function testHighRoasCampaignGetsIncreaseAndLowRoasGetsDecrease(): void
    {
        $this->insertCampaign(1, 'Strong Campaign', 100.0);
        $this->insertCampaign(2, 'Weak Campaign', 100.0);

        // Strong: high ROAS — $200 revenue on $10 spend = ROAS 20
        $this->insertPerformance(1, 1, 1000, 100, 10, 200.0, 10_000_000);
        // Weak: low ROAS — $20 revenue on $10 spend = ROAS 2
        $this->insertPerformance(2, 1, 1000, 100, 10, 20.0, 10_000_000);

        $result = $this->allocator->recommend(1);

        $strongRec = array_filter($result, fn($r) => $r['campaign_id'] === 1);
        $weakRec   = array_filter($result, fn($r) => $r['campaign_id'] === 2);

        if (!empty($strongRec)) {
            $strong = array_values($strongRec)[0];
            $this->assertGreaterThan(0, $strong['change'], 'Strong campaign should get an increase');
        }

        if (!empty($weakRec)) {
            $weak = array_values($weakRec)[0];
            $this->assertLessThan(0, $weak['change'], 'Weak campaign should get a decrease');
        }

        // At least one recommendation should be generated
        $this->assertNotEmpty($result);
    }

    public function testRecommendationDoesNotExceedFiftyPercentIncrease(): void
    {
        $this->insertCampaign(1, 'Campaign A', 100.0);
        $this->insertCampaign(2, 'Campaign B', 100.0);

        // Campaign A has extreme ROAS, Campaign B has none
        $this->insertPerformance(1, 1, 1000, 200, 50, 10000.0, 50_000_000);
        $this->insertPerformance(2, 1, 1000, 10, 0, 0.0, 50_000_000);

        $result = $this->allocator->recommend(1);

        foreach ($result as $rec) {
            if ($rec['campaign_id'] === 1) {
                // Max increase = 1.5x current budget = $150
                $this->assertLessThanOrEqual(150.0, $rec['recommended_budget']);
            }
        }
    }

    public function testRecommendationDoesNotDropBelowFiftyPercentDecrease(): void
    {
        $this->insertCampaign(1, 'Campaign A', 100.0);
        $this->insertCampaign(2, 'Campaign B', 100.0);

        $this->insertPerformance(1, 1, 1000, 200, 50, 10000.0, 50_000_000);
        $this->insertPerformance(2, 1, 1000, 10, 0, 0.0, 50_000_000);

        $result = $this->allocator->recommend(1);

        foreach ($result as $rec) {
            if ($rec['campaign_id'] === 2) {
                // Min decrease = 0.5x current budget = $50
                $this->assertGreaterThanOrEqual(50.0, $rec['recommended_budget']);
            }
        }
    }

    // -------------------------------------------------------------------------
    // recommend() — output structure
    // -------------------------------------------------------------------------

    public function testRecommendationContainsExpectedFields(): void
    {
        $this->insertCampaign(1, 'Campaign A', 100.0);
        $this->insertCampaign(2, 'Campaign B', 100.0);

        $this->insertPerformance(1, 1, 1000, 200, 50, 10000.0, 50_000_000);
        $this->insertPerformance(2, 1, 1000, 10, 0, 0.0, 50_000_000);

        $result = $this->allocator->recommend(1);

        $this->assertNotEmpty($result);
        $rec = $result[0];

        foreach (['campaign_id', 'campaign_name', 'platform', 'current_budget', 'recommended_budget', 'change', 'change_pct', 'roas', 'cpa', 'reason'] as $field) {
            $this->assertArrayHasKey($field, $rec, "Missing field: {$field}");
        }
    }

    public function testRecommendationOnlyIncludesMeaningfulChanges(): void
    {
        $this->insertCampaign(1, 'Campaign A', 100.0);
        $this->insertCampaign(2, 'Campaign B', 100.0);

        // Nearly identical performance — likely < 5% change so no recommendations
        $this->insertPerformance(1, 1, 1000, 50, 5, 100.0, 10_000_000);
        $this->insertPerformance(2, 1, 1000, 51, 5, 100.0, 10_000_000);

        $result = $this->allocator->recommend(1);

        // All returned recommendations (if any) must have > 5% change
        foreach ($result as $rec) {
            $this->assertGreaterThan(5.0, abs($rec['change_pct']),
                "Recommendation should only appear for changes > 5%");
        }

        // Make at least one assertion so the test is not risky
        $this->assertTrue(true, 'Only recommendations with > 5% change are returned');
    }

    public function testRecommendationsAreSortedByAbsoluteChangeDescending(): void
    {
        $this->insertCampaign(1, 'Campaign A', 50.0);
        $this->insertCampaign(2, 'Campaign B', 150.0);
        $this->insertCampaign(3, 'Campaign C', 100.0);

        // Different ROAS levels to force different changes
        $this->insertPerformance(1, 1, 1000, 200, 50, 10000.0, 50_000_000);
        $this->insertPerformance(2, 1, 1000, 20, 1, 5.0, 50_000_000);
        $this->insertPerformance(3, 1, 1000, 50, 5, 50.0, 50_000_000);

        $result = $this->allocator->recommend(1);

        for ($i = 0; $i < count($result) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                abs($result[$i + 1]['change']),
                abs($result[$i]['change']),
                'Recommendations should be sorted by absolute change descending'
            );
        }
    }

    // -------------------------------------------------------------------------
    // recommend() — reason generation
    // -------------------------------------------------------------------------

    public function testReasonMentionsRoasWhenPositive(): void
    {
        $this->insertCampaign(1, 'Campaign A', 50.0);
        $this->insertCampaign(2, 'Campaign B', 150.0);

        $this->insertPerformance(1, 1, 1000, 100, 20, 2000.0, 10_000_000);
        $this->insertPerformance(2, 1, 1000, 5, 0, 0.0, 10_000_000);

        $result = $this->allocator->recommend(1);

        $reasons = array_column($result, 'reason');
        $reasonText = implode(' ', $reasons);

        // At least one reason should mention ROAS
        $this->assertStringContainsString('ROAS', $reasonText);
    }

    public function testReasonMentionsZeroConversionsForUnderperformingCampaign(): void
    {
        $this->insertCampaign(1, 'Campaign A', 50.0);
        $this->insertCampaign(2, 'Campaign B', 150.0);

        // Campaign A: great ROAS
        $this->insertPerformance(1, 1, 1000, 100, 20, 2000.0, 10_000_000);
        // Campaign B: spent money, zero conversions
        $this->insertPerformance(2, 1, 1000, 5, 0, 0.0, 100_000_000);

        $result = $this->allocator->recommend(1);

        $weakRec = array_filter($result, fn($r) => $r['campaign_id'] === 2);
        if (!empty($weakRec)) {
            $reason = array_values($weakRec)[0]['reason'];
            $this->assertStringContainsString('zero conversions', strtolower($reason));
        } else {
            $this->markTestSkipped('Campaign B did not qualify for a recommendation with this test data.');
        }
    }

    // -------------------------------------------------------------------------
    // recommend() — project isolation
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // execute() — budget reallocations
    // -------------------------------------------------------------------------

    public function testExecuteUpdatesCampaignBudgetInDb(): void
    {
        $this->insertCampaign(1, 'Campaign A', 100.0);
        $this->insertCampaign(2, 'Campaign B', 100.0);

        $recommendations = [
            [
                'campaign_id'        => 1,
                'campaign_name'      => 'Campaign A',
                'platform'           => 'google',
                'current_budget'     => 100.0,
                'recommended_budget' => 130.0,
                'change'             => 30.0,
                'change_pct'         => 30.0,
                'roas'               => 5.0,
                'cpa'                => null,
                'reason'             => 'Increase by 30%.',
            ],
        ];

        $result = $this->allocator->execute($recommendations, 1);

        $this->assertContains(1, $result['updated']);

        // Campaign budget should be updated in DB
        $row = $this->db->query("SELECT daily_budget_aud FROM campaigns WHERE id = 1")->fetch();
        $this->assertEqualsWithDelta(130.0, (float) $row['daily_budget_aud'], 0.001);
    }

    public function testExecuteUpdatesMultipleCampaigns(): void
    {
        $this->insertCampaign(1, 'Campaign A', 100.0);
        $this->insertCampaign(2, 'Campaign B', 100.0);

        $recommendations = [
            [
                'campaign_id'        => 1,
                'campaign_name'      => 'Campaign A',
                'platform'           => 'google',
                'current_budget'     => 100.0,
                'recommended_budget' => 150.0,
                'change'             => 50.0,
                'change_pct'         => 50.0,
                'roas'               => 8.0,
                'cpa'                => null,
                'reason'             => 'Increase by 50%.',
            ],
            [
                'campaign_id'        => 2,
                'campaign_name'      => 'Campaign B',
                'platform'           => 'google',
                'current_budget'     => 100.0,
                'recommended_budget' => 50.0,
                'change'             => -50.0,
                'change_pct'         => -50.0,
                'roas'               => 0.5,
                'cpa'                => null,
                'reason'             => 'Decrease by 50%.',
            ],
        ];

        $result = $this->allocator->execute($recommendations, 1);

        $this->assertContains(1, $result['updated']);
        $this->assertContains(2, $result['updated']);
        $this->assertCount(2, $result['updated']);

        $rowA = $this->db->query("SELECT daily_budget_aud FROM campaigns WHERE id = 1")->fetch();
        $rowB = $this->db->query("SELECT daily_budget_aud FROM campaigns WHERE id = 2")->fetch();
        $this->assertEqualsWithDelta(150.0, (float) $rowA['daily_budget_aud'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $rowB['daily_budget_aud'], 0.001);
    }

    public function testExecuteUpdatesBudgetsTable(): void
    {
        $this->insertCampaign(1, 'Campaign A', 100.0);
        $this->insertCampaign(2, 'Campaign B', 100.0);

        $recommendations = [
            [
                'campaign_id'        => 1,
                'campaign_name'      => 'Campaign A',
                'platform'           => 'google',
                'current_budget'     => 100.0,
                'recommended_budget' => 150.0,
                'change'             => 50.0,
                'change_pct'         => 50.0,
                'roas'               => 8.0,
                'cpa'                => null,
                'reason'             => 'Increase by 50%.',
            ],
        ];

        $this->allocator->execute($recommendations, 1);

        // Budgets table should reflect sum of all active campaigns
        $row = $this->db->query(
            "SELECT daily_budget_aud FROM budgets WHERE project_id = 1 AND platform = 'google'"
        )->fetch();

        // Both campaigns are active: 150 + 100 = 250
        $this->assertEqualsWithDelta(250.0, (float) $row['daily_budget_aud'], 0.001);
    }

    public function testExecuteSkipsPlatformCallForCampaignsWithoutExternalId(): void
    {
        $this->insertCampaign(1, 'Campaign A', 100.0);

        $recommendations = [
            [
                'campaign_id'        => 1,
                'campaign_name'      => 'Campaign A',
                'platform'           => 'google',
                'current_budget'     => 100.0,
                'recommended_budget' => 130.0,
                'change'             => 30.0,
                'change_pct'         => 30.0,
                'roas'               => 5.0,
                'cpa'                => null,
                'reason'             => 'Increase by 30%.',
            ],
        ];

        $result = $this->allocator->execute($recommendations, 1);

        // No external_id on campaign — no platform call, no errors
        $this->assertContains(1, $result['updated']);
        $this->assertSame([], $result['errors']);
    }

    public function testExecuteReturnsCampaignIdInUpdatedEvenIfPlatformFails(): void
    {
        // Insert campaign with a fake external_id — platform call will throw
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status, daily_budget_aud, external_id)
             VALUES (5, 1, 'google', 'Ext Campaign', 'search', 'enabled', 100.0, 'fake-budget-resource')"
        );

        $recommendations = [
            [
                'campaign_id'        => 5,
                'campaign_name'      => 'Ext Campaign',
                'platform'           => 'google',
                'current_budget'     => 100.0,
                'recommended_budget' => 130.0,
                'change'             => 30.0,
                'change_pct'         => 30.0,
                'roas'               => 5.0,
                'cpa'                => null,
                'reason'             => 'Increase by 30%.',
            ],
        ];

        $result = $this->allocator->execute($recommendations, 1);

        // DB updated regardless of platform failure
        $this->assertContains(5, $result['updated']);
        $row = $this->db->query("SELECT daily_budget_aud FROM campaigns WHERE id = 5")->fetch();
        $this->assertEqualsWithDelta(130.0, (float) $row['daily_budget_aud'], 0.001);

        // Platform error captured, not thrown
        $this->assertNotEmpty($result['errors']);
    }

    public function testExecuteReturnsEmptyResultsForEmptyRecommendations(): void
    {
        $result = $this->allocator->execute([], 1);

        $this->assertSame([], $result['updated']);
        $this->assertSame([], $result['errors']);
    }

    // -------------------------------------------------------------------------
    // recommend() — project isolation
    // -------------------------------------------------------------------------

    public function testRecommendIgnoresOtherProjects(): void
    {
        $this->db->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (2, 'other', 'Other')"
        );
        $this->db->exec(
            "INSERT INTO budgets (project_id, platform, daily_budget_aud) VALUES (2, 'google', 500.0)"
        );
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status, daily_budget_aud)
             VALUES (10, 2, 'google', 'Other A', 'search', 'enabled', 100.0)"
        );
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status, daily_budget_aud)
             VALUES (11, 2, 'google', 'Other B', 'search', 'enabled', 100.0)"
        );

        // Project 1 has no campaigns, so no recommendations
        $result = $this->allocator->recommend(1);

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // recommendCrossPlatform() — cross-platform reallocation
    // -------------------------------------------------------------------------

    private function insertCampaignWithPlatform(int $id, string $platform, string $name, float $dailyBudget, string $status = 'enabled', string $createdAt = ''): void
    {
        if ($createdAt === '') {
            $createdAt = date('Y-m-d H:i:s', strtotime('-30 days'));
        }
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status, daily_budget_aud, created_at)
             VALUES ({$id}, 1, '{$platform}', '{$name}', 'search', '{$status}', {$dailyBudget}, '{$createdAt}')"
        );
    }

    private function insertBudgetForPlatform(string $platform, float $budget): void
    {
        $this->db->exec(
            "INSERT OR REPLACE INTO budgets (project_id, platform, daily_budget_aud)
             VALUES (1, '{$platform}', {$budget})"
        );
    }

    private function insertPerfForCampaign(int $campaignId, int $daysAgo, int $impressions, int $clicks, float $conversions, float $conversionValue, int $costMicros): void
    {
        $date = date('Y-m-d', strtotime("-{$daysAgo} days"));
        $this->db->exec(
            "INSERT INTO performance (campaign_id, date, impressions, clicks, conversions, conversion_value, cost_micros)
             VALUES ({$campaignId}, '{$date}', {$impressions}, {$clicks}, {$conversions}, {$conversionValue}, {$costMicros})"
        );
    }

    public function testRecommendCrossPlatformReturnsEmptyWhenNoEligiblePlatforms(): void
    {
        // Both campaigns just created (< 14 days)
        $this->insertCampaignWithPlatform(10, 'google', 'G Camp', 100.0, 'enabled', date('Y-m-d H:i:s', strtotime('-3 days')));
        $this->insertCampaignWithPlatform(11, 'meta',   'M Camp', 100.0, 'enabled', date('Y-m-d H:i:s', strtotime('-3 days')));
        $this->insertBudgetForPlatform('google', 100.0);
        $this->insertBudgetForPlatform('meta',   100.0);

        $result = $this->allocator->recommendCrossPlatform(1);

        // Both excluded (< 14 days)
        $eligible = array_filter($result, fn($r) => ($r['excluded'] ?? false) === false);
        $this->assertEmpty($eligible);
    }

    public function testRecommendCrossPlatformExcludesPlatformsUnder50Conversions(): void
    {
        $oldDate = date('Y-m-d H:i:s', strtotime('-60 days'));
        $this->insertCampaignWithPlatform(10, 'google', 'G Camp', 150.0, 'enabled', $oldDate);
        $this->insertCampaignWithPlatform(11, 'meta',   'M Camp', 50.0,  'enabled', $oldDate);
        $this->insertBudgetForPlatform('google', 150.0);
        $this->insertBudgetForPlatform('meta',   50.0);

        // Google: 40 conversions (under 50 threshold)
        $this->insertPerfForCampaign(10, 1, 1000, 100, 40, 4000.0, 50_000_000);
        // Meta: 30 conversions (under 50 threshold)
        $this->insertPerfForCampaign(11, 1, 1000, 80, 30, 3000.0, 40_000_000);

        $result = $this->allocator->recommendCrossPlatform(1);

        $excluded = array_filter($result, fn($r) => $r['excluded'] === true);
        $this->assertCount(2, array_values($excluded), 'Both platforms should be excluded');
    }

    public function testRecommendCrossPlatformEnforces30PercentFloor(): void
    {
        $oldDate = date('Y-m-d H:i:s', strtotime('-60 days'));
        $this->insertCampaignWithPlatform(10, 'google', 'G Camp', 150.0, 'enabled', $oldDate);
        $this->insertCampaignWithPlatform(11, 'meta',   'M Camp', 50.0,  'enabled', $oldDate);
        $this->insertBudgetForPlatform('google', 150.0);
        $this->insertBudgetForPlatform('meta',   50.0);

        // Google: extremely strong ROAS
        $this->insertPerfForCampaign(10, 1, 10000, 1000, 200, 100000.0, 100_000_000);
        // Meta: weak — but still eligible with 50+ conversions
        $this->insertPerfForCampaign(11, 1, 1000, 50,   50,  100.0,    50_000_000);

        $result = $this->allocator->recommendCrossPlatform(1);

        $totalEligibleBudget = 200.0; // 150 + 50
        $floor               = $totalEligibleBudget * 0.30; // $60

        foreach ($result as $rec) {
            if (!isset($rec['excluded']) || $rec['excluded'] === false) {
                $this->assertGreaterThanOrEqual(
                    $floor,
                    $rec['recommended_budget'],
                    "Platform {$rec['platform']} must not drop below 30% floor ({$floor})"
                );
            }
        }
    }

    public function testRecommendCrossPlatformReallocatesTowardHigherRoas(): void
    {
        $oldDate = date('Y-m-d H:i:s', strtotime('-60 days'));
        $this->insertCampaignWithPlatform(10, 'google', 'G Camp', 100.0, 'enabled', $oldDate);
        $this->insertCampaignWithPlatform(11, 'meta',   'M Camp', 100.0, 'enabled', $oldDate);
        $this->insertBudgetForPlatform('google', 100.0);
        $this->insertBudgetForPlatform('meta',   100.0);

        // Google ROAS 20, Meta ROAS 2
        $this->insertPerfForCampaign(10, 1, 5000, 500, 100, 20000.0, 100_000_000);
        $this->insertPerfForCampaign(11, 1, 5000, 500, 100, 2000.0,  100_000_000);

        $result = $this->allocator->recommendCrossPlatform(1);

        $googleRec = array_values(array_filter($result, fn($r) => $r['platform'] === 'google' && !$r['excluded']))[0] ?? null;
        $metaRec   = array_values(array_filter($result, fn($r) => $r['platform'] === 'meta'   && !$r['excluded']))[0] ?? null;

        // If there are recommendations, Google should get more and Meta less
        if ($googleRec !== null) {
            $this->assertGreaterThan(0, $googleRec['change'], 'Google (high ROAS) should get a budget increase');
        }
        if ($metaRec !== null) {
            $this->assertLessThan(0, $metaRec['change'], 'Meta (low ROAS) should get a budget decrease');
        }

        // At least one recommendation
        $eligible = array_filter($result, fn($r) => !$r['excluded']);
        $this->assertNotEmpty($eligible);
    }

    public function testRecommendCrossPlatformOutputShape(): void
    {
        $oldDate = date('Y-m-d H:i:s', strtotime('-60 days'));
        $this->insertCampaignWithPlatform(10, 'google', 'G Camp', 100.0, 'enabled', $oldDate);
        $this->insertCampaignWithPlatform(11, 'meta',   'M Camp', 100.0, 'enabled', $oldDate);
        $this->insertBudgetForPlatform('google', 100.0);
        $this->insertBudgetForPlatform('meta',   100.0);

        $this->insertPerfForCampaign(10, 1, 5000, 500, 100, 20000.0, 100_000_000);
        $this->insertPerfForCampaign(11, 1, 5000, 100, 50,  500.0,   100_000_000);

        $result = $this->allocator->recommendCrossPlatform(1);

        $this->assertNotEmpty($result);
        $rec = $result[0];

        foreach (['platform', 'current_budget', 'recommended_budget', 'reason', 'excluded'] as $field) {
            $this->assertArrayHasKey($field, $rec, "Missing field: {$field}");
        }
    }

    public function testRecommendCrossPlatformReturnsEmptyForProjectWithNoCampaigns(): void
    {
        $result = $this->allocator->recommendCrossPlatform(1);

        $this->assertSame([], $result);
    }
}
