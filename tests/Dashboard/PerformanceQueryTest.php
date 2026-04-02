<?php

namespace AdManager\Tests\Dashboard;

use AdManager\Dashboard\PerformanceQuery;
use AdManager\DB;
use PHPUnit\Framework\TestCase;

class PerformanceQueryTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();
        DB::init();

        $db = DB::get();
        $db->exec("INSERT INTO projects (id, name, display_name, website_url) VALUES (1, 'test', 'Test', 'https://test.com')");
        $db->exec("INSERT INTO campaigns (id, project_id, name, platform, type, status, daily_budget_aud) VALUES (1, 1, 'Search', 'google', 'search', 'active', 50.00)");
        $db->exec("INSERT INTO campaigns (id, project_id, name, platform, type, status, daily_budget_aud) VALUES (2, 1, 'Display', 'google', 'display', 'active', 30.00)");
        $db->exec("INSERT INTO ad_groups (id, campaign_id, name, status) VALUES (1, 1, 'Exact Match', 'active')");
        $db->exec("INSERT INTO ads (id, ad_group_id, type, status, final_url) VALUES (1, 1, 'responsive_search', 'active', 'https://test.com')");
        $db->exec("INSERT INTO ads (id, ad_group_id, type, status, final_url) VALUES (2, 1, 'responsive_search', 'active', 'https://test.com/landing')");

        // Insert performance data for "today" and yesterday
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        // Campaign-level performance (ad_group_id IS NULL, ad_id IS NULL)
        $db->exec("INSERT INTO performance (campaign_id, date, impressions, clicks, cost_micros, conversions, conversion_value) VALUES (1, '{$today}', 1000, 50, 10000000, 5, 100)");
        $db->exec("INSERT INTO performance (campaign_id, date, impressions, clicks, cost_micros, conversions, conversion_value) VALUES (1, '{$yesterday}', 800, 40, 8000000, 4, 80)");
        $db->exec("INSERT INTO performance (campaign_id, date, impressions, clicks, cost_micros, conversions, conversion_value) VALUES (2, '{$today}', 500, 20, 5000000, 2, 40)");

        // Ad group level
        $db->exec("INSERT INTO performance (campaign_id, ad_group_id, date, impressions, clicks, cost_micros, conversions, conversion_value) VALUES (1, 1, '{$today}', 1000, 50, 10000000, 5, 100)");

        // Ad level
        $db->exec("INSERT INTO performance (campaign_id, ad_group_id, ad_id, date, impressions, clicks, cost_micros, conversions, conversion_value) VALUES (1, 1, 1, '{$today}', 600, 30, 6000000, 3, 60)");
        $db->exec("INSERT INTO performance (campaign_id, ad_group_id, ad_id, date, impressions, clicks, cost_micros, conversions, conversion_value) VALUES (1, 1, 2, '{$today}', 400, 20, 4000000, 2, 40)");
    }

    protected function tearDown(): void
    {
        DB::reset();
        putenv('ADMANAGER_DB_PATH');
    }

    public function testProjectSummaryReturnsCurrentAndPrior(): void
    {
        $result = PerformanceQuery::projectSummary(1, 7);

        $this->assertArrayHasKey('current', $result);
        $this->assertArrayHasKey('prior', $result);
        $this->assertArrayHasKey('deltas', $result);

        $this->assertGreaterThan(0, $result['current']['impressions']);
        $this->assertGreaterThan(0, $result['current']['clicks']);
    }

    public function testProjectSummaryComputesMetrics(): void
    {
        $result = PerformanceQuery::projectSummary(1, 7);
        $current = $result['current'];

        $this->assertArrayHasKey('ctr', $current);
        $this->assertArrayHasKey('cpa', $current);
        $this->assertArrayHasKey('roas', $current);
        $this->assertArrayHasKey('conversion_rate', $current);
    }

    public function testProjectSummaryDeltasIncludeDirection(): void
    {
        $result = PerformanceQuery::projectSummary(1, 7);
        $deltas = $result['deltas'];

        $this->assertArrayHasKey('cost', $deltas);
        $this->assertArrayHasKey('value', $deltas['cost']);
        $this->assertArrayHasKey('direction', $deltas['cost']);
    }

    public function testCampaignBreakdownReturnsAllCampaigns(): void
    {
        $result = PerformanceQuery::campaignBreakdown(1, 14);

        $this->assertCount(2, $result);
        $names = array_column($result, 'name');
        $this->assertContains('Search', $names);
        $this->assertContains('Display', $names);
    }

    public function testCampaignBreakdownIncludesBudgetUtilisation(): void
    {
        $result = PerformanceQuery::campaignBreakdown(1, 14);

        $search = array_values(array_filter($result, fn($c) => $c['name'] === 'Search'))[0];
        $this->assertArrayHasKey('budget_utilisation', $search);
        $this->assertArrayHasKey('daily_budget', $search);
        $this->assertEquals(50.00, $search['daily_budget']);
    }

    public function testAdGroupBreakdownReturnsGroups(): void
    {
        $result = PerformanceQuery::adGroupBreakdown(1, 14);

        $this->assertCount(1, $result);
        $this->assertEquals('Exact Match', $result[0]['name']);
        $this->assertArrayHasKey('active_ads', $result[0]);
        $this->assertEquals(2, $result[0]['active_ads']);
    }

    public function testAdBreakdownReturnsAds(): void
    {
        $result = PerformanceQuery::adBreakdown(1, 14);

        $this->assertCount(2, $result);
        foreach ($result as $ad) {
            $this->assertArrayHasKey('type', $ad);
            $this->assertArrayHasKey('status', $ad);
            $this->assertArrayHasKey('final_url', $ad);
            $this->assertGreaterThan(0, $ad['impressions']);
        }
    }

    public function testAdBreakdownOrderedByCost(): void
    {
        $result = PerformanceQuery::adBreakdown(1, 14);

        // Ad 1 (6M cost_micros) should come before Ad 2 (4M cost_micros)
        $this->assertEquals(1, $result[0]['id']);
        $this->assertEquals(2, $result[1]['id']);
    }

    public function testDailySeriesReturnsDateRows(): void
    {
        $result = PerformanceQuery::dailySeries(1, 7);

        $this->assertNotEmpty($result);
        foreach ($result as $row) {
            $this->assertArrayHasKey('date', $row);
            $this->assertArrayHasKey('impressions', $row);
            $this->assertArrayHasKey('cost', $row);
        }
    }

    public function testGoalsStatusReturnsOnTrack(): void
    {
        $db = DB::get();
        $db->exec("INSERT INTO goals (project_id, metric, target_value, platform) VALUES (1, 'ctr', 1.0, 'all')");
        $db->exec("INSERT INTO goals (project_id, metric, target_value, platform) VALUES (1, 'cpa', 10.0, 'all')");

        $result = PerformanceQuery::goalsStatus(1, 14);

        $this->assertCount(2, $result);
        foreach ($result as $g) {
            $this->assertArrayHasKey('metric', $g);
            $this->assertArrayHasKey('target', $g);
            $this->assertArrayHasKey('actual', $g);
            $this->assertArrayHasKey('on_track', $g);
        }
    }

    public function testGoalsStatusEmptyWhenNoGoals(): void
    {
        $result = PerformanceQuery::goalsStatus(1, 14);
        $this->assertEmpty($result);
    }

    public function testSyncStatus(): void
    {
        $result = PerformanceQuery::syncStatus(1);

        $this->assertArrayHasKey('last_sync_at', $result);
        $this->assertArrayHasKey('seconds_ago', $result);
        $this->assertArrayHasKey('is_running', $result);
        $this->assertFalse($result['is_running']);
    }

    public function testEmptyProjectReturnsZeros(): void
    {
        $result = PerformanceQuery::projectSummary(999, 7);
        $this->assertEquals(0, $result['current']['impressions']);
        $this->assertEquals(0, $result['current']['clicks']);
    }
}
