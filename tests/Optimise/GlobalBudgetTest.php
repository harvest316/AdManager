<?php

declare(strict_types=1);

namespace AdManager\Tests\Optimise;

use AdManager\DB;
use AdManager\Optimise\GlobalBudget;
use PDO;
use PHPUnit\Framework\TestCase;

class GlobalBudgetTest extends TestCase
{
    private PDO $db;
    private GlobalBudget $gb;

    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();

        $this->db = DB::get();
        $schema   = file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql');
        $this->db->exec($schema);

        $this->gb = new GlobalBudget();

        $this->db->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (1, 'test', 'Test Project')"
        );
    }

    protected function tearDown(): void
    {
        DB::reset();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function insertBudget(string $platform, float $daily): void
    {
        $this->db->exec(
            "INSERT INTO budgets (project_id, platform, daily_budget_aud)
             VALUES (1, '{$platform}', {$daily})"
        );
    }

    private function insertCampaign(int $id, string $platform, string $name, float $budget, string $createdAt = ''): void
    {
        $at = $createdAt ?: date('Y-m-d H:i:s', strtotime('-60 days'));
        $this->db->exec(
            "INSERT INTO campaigns (id, project_id, platform, name, type, status, daily_budget_aud, created_at)
             VALUES ({$id}, 1, '{$platform}', '{$name}', 'search', 'enabled', {$budget}, '{$at}')"
        );
    }

    private function insertPerformance(int $campaignId, int $daysAgo, int $impressions, int $clicks, float $conversions, float $convValue, int $costMicros): void
    {
        $date = date('Y-m-d', strtotime("-{$daysAgo} days"));
        $this->db->exec(
            "INSERT INTO performance (campaign_id, date, impressions, clicks, conversions, conversion_value, cost_micros)
             VALUES ({$campaignId}, '{$date}', {$impressions}, {$clicks}, {$conversions}, {$convValue}, {$costMicros})"
        );
    }

    // ── set() / get() ─────────────────────────────────────────────────────────

    public function testSetCreatesNewRow(): void
    {
        $this->gb->set(1, 200.0);

        $row = $this->gb->get(1);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(200.0, (float) $row['daily_budget_aud'], 0.001);
    }

    public function testSetUpdatesExistingRow(): void
    {
        $this->gb->set(1, 100.0);
        $this->gb->set(1, 250.0);

        $row = $this->gb->get(1);

        $this->assertEqualsWithDelta(250.0, (float) $row['daily_budget_aud'], 0.001);
    }

    public function testSetOptionalFieldsArePreservedWhenNull(): void
    {
        $this->gb->set(1, 100.0, 50.0, 500.0, 20.0, true);

        // Update only the daily amount — other fields should be unchanged.
        $this->gb->set(1, 150.0);

        $row = $this->gb->get(1);

        $this->assertEqualsWithDelta(150.0, (float) $row['daily_budget_aud'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $row['min_daily_budget_aud'], 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $row['max_daily_budget_aud'], 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $row['max_variance_pct'], 0.001);
        $this->assertSame(1, (int) $row['scaling_enabled']);
    }

    public function testSetAllOptionalFields(): void
    {
        $this->gb->set(1, 200.0, 50.0, 800.0, 30.0, false);

        $row = $this->gb->get(1);

        $this->assertEqualsWithDelta(200.0, (float) $row['daily_budget_aud'], 0.001);
        $this->assertEqualsWithDelta(50.0,  (float) $row['min_daily_budget_aud'], 0.001);
        $this->assertEqualsWithDelta(800.0, (float) $row['max_daily_budget_aud'], 0.001);
        $this->assertEqualsWithDelta(30.0,  (float) $row['max_variance_pct'], 0.001);
        $this->assertSame(0, (int) $row['scaling_enabled']);
    }

    public function testGetReturnsNullWhenNoRow(): void
    {
        $this->assertNull($this->gb->get(99));
    }

    // ── distribute() — no data ─────────────────────────────────────────────

    public function testDistributeEvenSplitWithNoPerformanceData(): void
    {
        $this->gb->set(1, 200.0);
        $this->insertBudget('google', 100.0);
        $this->insertBudget('meta', 100.0);

        // Campaigns exist but no performance — even split.
        $this->insertCampaign(1, 'google', 'G', 100.0);
        $this->insertCampaign(2, 'meta',   'M', 100.0);

        $dist = $this->gb->distribute(1);

        $this->assertCount(2, $dist);

        // With even split and global=$200, each platform should get ~$100.
        foreach ($dist as $d) {
            $this->assertEqualsWithDelta(100.0, $d['recommended_daily'], 5.0,
                "Even split should give each platform ~\$100");
        }
    }

    public function testDistributeReturnsEmptyWhenNoBudgetsConfigured(): void
    {
        $this->gb->set(1, 200.0);
        // No budgets table entries.

        $dist = $this->gb->distribute(1);

        $this->assertSame([], $dist);
    }

    public function testDistributeReturnsEmptyWhenNoGlobalBudget(): void
    {
        $this->insertBudget('google', 100.0);

        $dist = $this->gb->distribute(1);

        $this->assertSame([], $dist);
    }

    // ── distribute() — performance-weighted ───────────────────────────────

    public function testDistributeWeightsHigherRoasPlatformMore(): void
    {
        $this->gb->set(1, 200.0, 0.0, 1000.0, 50.0);  // 50% variance to allow meaningful shifts
        $this->insertBudget('google', 100.0);
        $this->insertBudget('meta', 100.0);

        $this->insertCampaign(1, 'google', 'G', 100.0);
        $this->insertCampaign(2, 'meta',   'M', 100.0);

        // Google: ROAS 20, 100 conversions (eligible for cross-platform)
        $this->insertPerformance(1, 1, 10000, 1000, 100, 20000.0, 100_000_000);
        // Meta: ROAS 2, 100 conversions (eligible)
        $this->insertPerformance(2, 1, 10000, 1000, 100, 2000.0,  100_000_000);

        $dist = $this->gb->distribute(1);

        $googleEntry = array_values(array_filter($dist, fn($d) => $d['platform'] === 'google'))[0] ?? null;
        $metaEntry   = array_values(array_filter($dist, fn($d) => $d['platform'] === 'meta'))[0]   ?? null;

        $this->assertNotNull($googleEntry);
        $this->assertNotNull($metaEntry);

        // Google (higher ROAS) should get more than or equal to Meta.
        $this->assertGreaterThanOrEqual(
            $metaEntry['recommended_daily'],
            $googleEntry['recommended_daily'],
            'Google (ROAS 20) should get >= budget compared to Meta (ROAS 2)'
        );
    }

    // ── distribute() — variance clamping ──────────────────────────────────

    public function testDistributeRespectsMaxVariancePct(): void
    {
        // 10% variance cap.
        $this->gb->set(1, 200.0, 0.0, 1000.0, 10.0);
        $this->insertBudget('google', 100.0);
        $this->insertBudget('meta', 100.0);

        $this->insertCampaign(1, 'google', 'G', 100.0);
        $this->insertCampaign(2, 'meta',   'M', 100.0);

        // Extreme ROAS difference to force large ideal shift.
        $this->insertPerformance(1, 1, 10000, 1000, 100, 50000.0, 100_000_000);
        $this->insertPerformance(2, 1, 10000, 100,  100, 100.0,   100_000_000);

        $dist = $this->gb->distribute(1);

        foreach ($dist as $d) {
            $changePct = abs($d['change_pct']);
            $this->assertLessThanOrEqual(10.0 + 0.1, $changePct,
                "Change of {$d['platform']} ({$changePct}%) must not exceed 10% variance cap");
        }
    }

    // ── executeDistribution() ─────────────────────────────────────────────

    public function testExecuteDistributionUpdatesBudgetsTable(): void
    {
        $this->gb->set(1, 200.0);
        $this->insertBudget('google', 100.0);
        $this->insertBudget('meta', 100.0);

        $distribution = [
            ['platform' => 'google', 'current_daily' => 100.0, 'recommended_daily' => 120.0, 'change_pct' => 20.0],
            ['platform' => 'meta',   'current_daily' => 100.0, 'recommended_daily' => 80.0,  'change_pct' => -20.0],
        ];

        $result = $this->gb->executeDistribution(1, $distribution);

        $this->assertContains('google', $result['updated']);
        $this->assertContains('meta',   $result['updated']);

        $googleRow = $this->db->query(
            "SELECT daily_budget_aud FROM budgets WHERE project_id = 1 AND platform = 'google'"
        )->fetch();
        $metaRow = $this->db->query(
            "SELECT daily_budget_aud FROM budgets WHERE project_id = 1 AND platform = 'meta'"
        )->fetch();

        $this->assertEqualsWithDelta(120.0, (float) $googleRow['daily_budget_aud'], 0.001);
        $this->assertEqualsWithDelta(80.0,  (float) $metaRow['daily_budget_aud'],   0.001);
    }

    public function testExecuteDistributionLogsToAdjustments(): void
    {
        $this->gb->set(1, 200.0);
        $this->insertBudget('google', 100.0);

        $distribution = [
            ['platform' => 'google', 'current_daily' => 100.0, 'recommended_daily' => 110.0, 'change_pct' => 10.0],
        ];

        $this->gb->executeDistribution(1, $distribution);

        $logRow = $this->db->query(
            "SELECT * FROM budget_adjustments WHERE project_id = 1"
        )->fetch();

        $this->assertNotFalse($logRow, 'Should have logged to budget_adjustments');
        $this->assertSame('distribute', $logRow['trigger_type']);
    }

    public function testExecuteDistributionReturnsEmptyForEmptyInput(): void
    {
        $result = $this->gb->executeDistribution(1, []);

        $this->assertSame([], $result['updated']);
        $this->assertSame([], $result['errors']);
    }
}
