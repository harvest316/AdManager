<?php

declare(strict_types=1);

namespace AdManager\Tests\Optimise;

use AdManager\DB;
use AdManager\Optimise\GlobalBudget;
use AdManager\Optimise\RevenueScaler;
use PDO;
use PHPUnit\Framework\TestCase;

class RevenueScalerTest extends TestCase
{
    private PDO $db;
    private RevenueScaler $scaler;

    protected function setUp(): void
    {
        putenv('ADMANAGER_DB_PATH=:memory:');
        DB::reset();

        $this->db = DB::get();
        $schema   = file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql');
        $this->db->exec($schema);

        $this->scaler = new RevenueScaler();

        $this->db->exec(
            "INSERT INTO projects (id, name, display_name) VALUES (1, 'test', 'Test Project')"
        );
    }

    protected function tearDown(): void
    {
        DB::reset();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedGlobalBudget(
        float $daily,
        float $min = 0.0,
        float $max = 1000.0,
        float $variance = 25.0,
        bool $scalingEnabled = true
    ): void {
        (new GlobalBudget())->set(1, $daily, $min, $max, $variance, $scalingEnabled);
    }

    private function seedBudget(string $platform, float $daily): void
    {
        $this->db->exec(
            "INSERT INTO budgets (project_id, platform, daily_budget_aud)
             VALUES (1, '{$platform}', {$daily})"
        );
    }

    private function recordRevenue(float $amount, string $date, string $source = 'manual'): void
    {
        $this->scaler->recordRevenue(1, $amount, $date, $source);
    }

    // ── recordRevenue() ───────────────────────────────────────────────────────

    public function testRecordRevenueInsertsRow(): void
    {
        $this->recordRevenue(500.0, '2026-03-01');

        $row = $this->db->query(
            "SELECT * FROM revenue_events WHERE project_id = 1"
        )->fetch();

        $this->assertNotFalse($row);
        $this->assertEqualsWithDelta(500.0, (float) $row['revenue_aud'], 0.001);
        $this->assertSame('2026-03-01', $row['date']);
        $this->assertSame('manual', $row['source']);
    }

    public function testRecordRevenueRespectsCustomSource(): void
    {
        $this->recordRevenue(100.0, '2026-03-01', 'webhook');

        $row = $this->db->query(
            "SELECT source FROM revenue_events WHERE project_id = 1"
        )->fetch();

        $this->assertSame('webhook', $row['source']);
    }

    public function testRecordRevenueAllowsMultipleEntriesPerDate(): void
    {
        $this->recordRevenue(100.0, '2026-03-01');
        $this->recordRevenue(200.0, '2026-03-01');

        $count = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM revenue_events WHERE project_id = 1 AND date = '2026-03-01'"
        )->fetch()['cnt'];

        $this->assertSame(2, (int) $count);
    }

    // ── importFromGA4() ───────────────────────────────────────────────────────

    public function testImportFromGA4InsertsRevenuePerDate(): void
    {
        $this->db->exec(
            "INSERT INTO ga4_performance (project_id, campaign_name, sessions, revenue, conversions, date)
             VALUES (1, 'Campaign A', 100, 300.0, 10, date('now', '-1 days'))"
        );
        $this->db->exec(
            "INSERT INTO ga4_performance (project_id, campaign_name, sessions, revenue, conversions, date)
             VALUES (1, 'Campaign B', 200, 150.0, 5, date('now', '-1 days'))"
        );

        $inserted = $this->scaler->importFromGA4(1, 7);

        $this->assertSame(1, $inserted, 'Two rows for same date → 1 aggregated insert');

        $row = $this->db->query(
            "SELECT revenue_aud FROM revenue_events WHERE project_id = 1 AND source = 'ga4'"
        )->fetch();

        $this->assertEqualsWithDelta(450.0, (float) $row['revenue_aud'], 0.001);
    }

    public function testImportFromGA4SkipsAlreadyImportedDates(): void
    {
        $date = date('Y-m-d', strtotime('-1 days'));

        $this->db->exec(
            "INSERT INTO ga4_performance (project_id, campaign_name, sessions, revenue, conversions, date)
             VALUES (1, 'Camp', 100, 200.0, 5, '{$date}')"
        );

        // First import.
        $first = $this->scaler->importFromGA4(1, 7);
        $this->assertSame(1, $first);

        // Second import — same date should be skipped.
        $second = $this->scaler->importFromGA4(1, 7);
        $this->assertSame(0, $second);
    }

    public function testImportFromGA4ReturnsZeroWhenNoData(): void
    {
        $inserted = $this->scaler->importFromGA4(1, 7);

        $this->assertSame(0, $inserted);
    }

    // ── baseline() ───────────────────────────────────────────────────────────

    public function testBaselineReturnsZeroWhenNoData(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->scaler->baseline(1), 0.001);
    }

    public function testBaselineCalculatesSevenDayAverage(): void
    {
        // 7 days of $100/day = baseline $100.
        for ($i = 1; $i <= 7; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $this->recordRevenue(100.0, $date);
        }

        $baseline = $this->scaler->baseline(1, 7);

        $this->assertEqualsWithDelta(100.0, $baseline, 0.001);
    }

    public function testBaselineAveragesAcrossDistinctDates(): void
    {
        // Two entries on day 1 (total $300), one entry on day 2 ($100) → avg = (300+100)/2 = $200.
        $day1 = date('Y-m-d', strtotime('-1 days'));
        $day2 = date('Y-m-d', strtotime('-2 days'));

        $this->recordRevenue(200.0, $day1);
        $this->recordRevenue(100.0, $day1);
        $this->recordRevenue(100.0, $day2);

        $baseline = $this->scaler->baseline(1, 7);

        $this->assertEqualsWithDelta(200.0, $baseline, 0.001);
    }

    // ── currentRevenue() ─────────────────────────────────────────────────────

    public function testCurrentRevenueReturnsTodayTotal(): void
    {
        $today = date('Y-m-d');
        $this->recordRevenue(300.0, $today);
        $this->recordRevenue(50.0, $today);

        $current = $this->scaler->currentRevenue(1);

        $this->assertEqualsWithDelta(350.0, $current, 0.001);
    }

    public function testCurrentRevenueFallsBackToMostRecentDate(): void
    {
        // No today data — use most recent.
        $date = date('Y-m-d', strtotime('-2 days'));
        $this->recordRevenue(400.0, $date);

        $current = $this->scaler->currentRevenue(1);

        $this->assertEqualsWithDelta(400.0, $current, 0.001);
    }

    public function testCurrentRevenueReturnsZeroWhenNoData(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->scaler->currentRevenue(1), 0.001);
    }

    // ── calculateScaling() — math ─────────────────────────────────────────────

    public function testScalingMathRevenueUp20PctBudgetUp20Pct(): void
    {
        $this->seedGlobalBudget(100.0, 0.0, 1000.0, 50.0);

        // Baseline: 7 days × $100.
        for ($i = 1; $i <= 7; $i++) {
            $this->recordRevenue(100.0, date('Y-m-d', strtotime("-{$i} days")));
        }
        // Today: $120 (+20%).
        $this->recordRevenue(120.0, date('Y-m-d'));

        $result = $this->scaler->calculateScaling(1);

        $this->assertEqualsWithDelta(20.0, $result['revenue_delta_pct'], 0.5);
        $this->assertEqualsWithDelta(120.0, $result['proposed_global'], 0.5);
        $this->assertFalse($result['clamped']);
    }

    public function testScalingClampedToMinBudget(): void
    {
        $this->seedGlobalBudget(100.0, 90.0, 1000.0, 50.0); // min = $90

        // Baseline $100, today $50 → -50% → proposed $50, clamped to $90.
        for ($i = 1; $i <= 7; $i++) {
            $this->recordRevenue(100.0, date('Y-m-d', strtotime("-{$i} days")));
        }
        $this->recordRevenue(50.0, date('Y-m-d'));

        $result = $this->scaler->calculateScaling(1);

        $this->assertEqualsWithDelta(90.0, $result['proposed_global'], 0.1);
        $this->assertTrue($result['clamped']);
    }

    public function testScalingClampedToMaxBudget(): void
    {
        $this->seedGlobalBudget(100.0, 0.0, 110.0, 50.0); // max = $110

        // Baseline $100, today $200 → +100% → proposed $200, clamped to $110.
        for ($i = 1; $i <= 7; $i++) {
            $this->recordRevenue(100.0, date('Y-m-d', strtotime("-{$i} days")));
        }
        $this->recordRevenue(200.0, date('Y-m-d'));

        $result = $this->scaler->calculateScaling(1);

        $this->assertEqualsWithDelta(110.0, $result['proposed_global'], 0.1);
        $this->assertTrue($result['clamped']);
    }

    public function testScalingClampedToMaxVariance(): void
    {
        $this->seedGlobalBudget(100.0, 0.0, 1000.0, 10.0); // 10% max variance

        // Revenue +50% → ideal budget $150, clamped to $110 (10% up).
        for ($i = 1; $i <= 7; $i++) {
            $this->recordRevenue(100.0, date('Y-m-d', strtotime("-{$i} days")));
        }
        $this->recordRevenue(150.0, date('Y-m-d'));

        $result = $this->scaler->calculateScaling(1);

        $this->assertEqualsWithDelta(110.0, $result['proposed_global'], 0.1);
        $this->assertTrue($result['clamped']);
    }

    public function testScalingReturnsNoChangeWhenNoBaseline(): void
    {
        $this->seedGlobalBudget(100.0);

        $result = $this->scaler->calculateScaling(1);

        $this->assertEqualsWithDelta(100.0, $result['proposed_global'], 0.001);
        $this->assertEqualsWithDelta(0.0, $result['budget_delta_pct'], 0.001);
    }

    public function testScalingReturnsMeaningfulFields(): void
    {
        $this->seedGlobalBudget(100.0);

        for ($i = 1; $i <= 7; $i++) {
            $this->recordRevenue(100.0, date('Y-m-d', strtotime("-{$i} days")));
        }
        $this->recordRevenue(110.0, date('Y-m-d'));

        $result = $this->scaler->calculateScaling(1);

        foreach (['current_global', 'proposed_global', 'revenue_baseline', 'revenue_current',
                  'revenue_delta_pct', 'budget_delta_pct', 'clamped', 'reason'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    // ── execute() ─────────────────────────────────────────────────────────────

    public function testExecuteUpdatesGlobalBudgets(): void
    {
        $this->seedGlobalBudget(100.0, 0.0, 1000.0, 50.0);
        $this->seedBudget('google', 100.0);

        for ($i = 1; $i <= 7; $i++) {
            $this->recordRevenue(100.0, date('Y-m-d', strtotime("-{$i} days")));
        }
        $this->recordRevenue(120.0, date('Y-m-d')); // +20%

        $this->scaler->execute(1);

        $row = $this->db->query(
            "SELECT daily_budget_aud FROM global_budgets WHERE project_id = 1"
        )->fetch();

        $this->assertEqualsWithDelta(120.0, (float) $row['daily_budget_aud'], 0.5);
    }

    public function testExecuteLogsToAdjustmentsTable(): void
    {
        $this->seedGlobalBudget(100.0, 0.0, 1000.0, 50.0);
        $this->seedBudget('google', 100.0);

        for ($i = 1; $i <= 7; $i++) {
            $this->recordRevenue(100.0, date('Y-m-d', strtotime("-{$i} days")));
        }
        $this->recordRevenue(130.0, date('Y-m-d')); // +30%

        $this->scaler->execute(1);

        $log = $this->db->query(
            "SELECT * FROM budget_adjustments WHERE project_id = 1 AND trigger_type = 'revenue_scaling'"
        )->fetch();

        $this->assertNotFalse($log, 'Should have logged to budget_adjustments');
    }

    public function testExecuteReturnsNoChangeWhenDeltaUnder2Pct(): void
    {
        $this->seedGlobalBudget(100.0);

        // 1% delta — should not trigger action.
        for ($i = 1; $i <= 7; $i++) {
            $this->recordRevenue(100.0, date('Y-m-d', strtotime("-{$i} days")));
        }
        $this->recordRevenue(101.0, date('Y-m-d'));

        $result = $this->scaler->execute(1);

        $this->assertSame('no_change', $result['action']);
    }

    // ── run() ─────────────────────────────────────────────────────────────────

    public function testRunSkipsWhenScalingDisabled(): void
    {
        $this->seedGlobalBudget(100.0, 0.0, 1000.0, 25.0, false);

        $result = $this->scaler->run(1);

        $this->assertTrue($result['skipped'] ?? false);
    }

    public function testRunExecutesWhenScalingEnabled(): void
    {
        $this->seedGlobalBudget(100.0, 0.0, 1000.0, 50.0, true);
        $this->seedBudget('google', 100.0);

        for ($i = 1; $i <= 7; $i++) {
            $this->recordRevenue(100.0, date('Y-m-d', strtotime("-{$i} days")));
        }
        $this->recordRevenue(130.0, date('Y-m-d'));

        $result = $this->scaler->run(1);

        $this->assertFalse($result['skipped'] ?? false);
        $this->assertArrayHasKey('action', $result);
    }

    public function testRunSkipsWhenNoGlobalBudget(): void
    {
        $result = $this->scaler->run(99);

        $this->assertTrue($result['skipped'] ?? false);
    }
}
