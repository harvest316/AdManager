<?php

declare(strict_types=1);

namespace AdManager\Optimise;

use AdManager\DB;

class RevenueScaler
{
    /**
     * Record a single revenue event.
     */
    public function recordRevenue(
        int $projectId,
        float $amountAud,
        string $date,
        string $source = 'manual'
    ): void {
        DB::get()->prepare(
            "INSERT INTO revenue_events (project_id, source, revenue_aud, date, created_at)
             VALUES (?, ?, ?, ?, datetime('now'))"
        )->execute([$projectId, $source, $amountAud, $date]);
    }

    /**
     * Import revenue from ga4_performance rows into revenue_events.
     *
     * Sums revenue per date and inserts as source='ga4'.
     * Skips dates already present in revenue_events for this project+source.
     *
     * @return int  Number of date rows inserted.
     */
    public function importFromGA4(int $projectId, int $days = 7): int
    {
        $db = DB::get();

        // Dates already imported from GA4.
        $existingStmt = $db->prepare(
            "SELECT DISTINCT date FROM revenue_events
             WHERE project_id = ? AND source = 'ga4'"
        );
        $existingStmt->execute([$projectId]);
        $existing = array_column($existingStmt->fetchAll(), 'date');

        // Aggregate GA4 revenue per date for the last N days.
        $ga4Stmt = $db->prepare(
            "SELECT date, SUM(revenue) AS total_revenue
             FROM ga4_performance
             WHERE project_id = ?
               AND date >= date('now', ? || ' days')
             GROUP BY date"
        );
        $ga4Stmt->execute([$projectId, "-{$days}"]);
        $rows = $ga4Stmt->fetchAll();

        $inserted = 0;
        $insertStmt = $db->prepare(
            "INSERT INTO revenue_events (project_id, source, revenue_aud, date, created_at)
             VALUES (?, 'ga4', ?, ?, datetime('now'))"
        );

        foreach ($rows as $row) {
            if (in_array($row['date'], $existing, true)) {
                continue;
            }
            $insertStmt->execute([$projectId, (float) $row['total_revenue'], $row['date']]);
            $inserted++;
        }

        return $inserted;
    }

    /**
     * Calculate the rolling N-day average of daily revenue.
     *
     * Groups events by date, sums within each date, then averages across dates.
     * Returns 0.0 if no data exists.
     */
    public function baseline(int $projectId, int $days = 7): float
    {
        $stmt = DB::get()->prepare(
            "SELECT AVG(daily_total) AS baseline
             FROM (
                 SELECT date, SUM(revenue_aud) AS daily_total
                 FROM revenue_events
                 WHERE project_id = ?
                   AND date >= date('now', ? || ' days')
                   AND date < date('now')
                 GROUP BY date
             ) t"
        );
        $stmt->execute([$projectId, "-{$days}"]);
        $row = $stmt->fetch();

        return (float) ($row['baseline'] ?? 0.0);
    }

    /**
     * Return today's total revenue (or the most recent date's total if today has none).
     */
    public function currentRevenue(int $projectId): float
    {
        $db = DB::get();

        // Try today first.
        $todayStmt = $db->prepare(
            "SELECT SUM(revenue_aud) AS total
             FROM revenue_events
             WHERE project_id = ? AND date = date('now')"
        );
        $todayStmt->execute([$projectId]);
        $today = (float) ($todayStmt->fetch()['total'] ?? 0.0);

        if ($today > 0.0) {
            return $today;
        }

        // Fall back to most recent date with data.
        $recentStmt = $db->prepare(
            "SELECT SUM(revenue_aud) AS total
             FROM revenue_events
             WHERE project_id = ?
               AND date = (
                   SELECT MAX(date) FROM revenue_events WHERE project_id = ?
               )"
        );
        $recentStmt->execute([$projectId, $projectId]);

        return (float) ($recentStmt->fetch()['total'] ?? 0.0);
    }

    /**
     * Calculate the proposed budget scaling based on revenue movement.
     *
     * Revenue delta drives a proportional budget change, clamped to the
     * project's min/max budget and max_variance_pct guard.
     *
     * @return array{
     *   current_global: float,
     *   proposed_global: float,
     *   revenue_baseline: float,
     *   revenue_current: float,
     *   revenue_delta_pct: float,
     *   budget_delta_pct: float,
     *   clamped: bool,
     *   reason: string
     * }
     */
    public function calculateScaling(int $projectId): array
    {
        $globalBudget = new GlobalBudget();
        $gbRow        = $globalBudget->get($projectId);

        if (!$gbRow) {
            return [
                'current_global'   => 0.0,
                'proposed_global'  => 0.0,
                'revenue_baseline' => 0.0,
                'revenue_current'  => 0.0,
                'revenue_delta_pct' => 0.0,
                'budget_delta_pct' => 0.0,
                'clamped'          => false,
                'reason'           => 'No global budget configured.',
            ];
        }

        $currentGlobal  = (float) $gbRow['daily_budget_aud'];
        $minBudget      = (float) ($gbRow['min_daily_budget_aud'] ?? 0.0);
        $maxBudget      = (float) ($gbRow['max_daily_budget_aud'] ?? PHP_FLOAT_MAX);
        $maxVariancePct = (float) ($gbRow['max_variance_pct'] ?? 25.0);

        $baseline = $this->baseline($projectId);
        $current  = $this->currentRevenue($projectId);

        if ($baseline <= 0.0) {
            return [
                'current_global'    => $currentGlobal,
                'proposed_global'   => $currentGlobal,
                'revenue_baseline'  => $baseline,
                'revenue_current'   => $current,
                'revenue_delta_pct' => 0.0,
                'budget_delta_pct'  => 0.0,
                'clamped'           => false,
                'reason'            => 'No revenue baseline available — no change.',
            ];
        }

        // Revenue delta as a fraction.
        $revenueDeltaPct = (($current - $baseline) / $baseline) * 100.0;

        // Proposed budget scales by the same percentage.
        $proposed = $currentGlobal * (1.0 + $revenueDeltaPct / 100.0);

        $clamped = false;
        $reasons = [];

        // Clamp to min/max.
        if ($proposed < $minBudget) {
            $proposed = $minBudget;
            $clamped  = true;
            $reasons[] = "clamped to min \${$minBudget}";
        } elseif ($proposed > $maxBudget) {
            $proposed = $maxBudget;
            $clamped  = true;
            $reasons[] = "clamped to max \${$maxBudget}";
        }

        // Clamp change to max_variance_pct.
        $rawChangePct = $currentGlobal > 0
            ? (($proposed - $currentGlobal) / $currentGlobal) * 100.0
            : 0.0;

        if (abs($rawChangePct) > $maxVariancePct) {
            $cappedPct = ($rawChangePct > 0 ? 1 : -1) * $maxVariancePct;
            $proposed  = $currentGlobal * (1.0 + $cappedPct / 100.0);
            $clamped   = true;
            $reasons[] = "change capped at {$maxVariancePct}%";
        }

        $proposed = round($proposed, 2);
        $budgetDeltaPct = $currentGlobal > 0
            ? round((($proposed - $currentGlobal) / $currentGlobal) * 100.0, 2)
            : 0.0;

        $reason = sprintf(
            'Revenue %.1f%% vs 7-day avg ($%.2f → $%.2f); budget %s%.1f%%.%s',
            $revenueDeltaPct,
            $baseline,
            $current,
            $budgetDeltaPct >= 0 ? '+' : '',
            $budgetDeltaPct,
            $clamped ? ' ' . implode(', ', $reasons) . '.' : ''
        );

        return [
            'current_global'    => $currentGlobal,
            'proposed_global'   => $proposed,
            'revenue_baseline'  => round($baseline, 4),
            'revenue_current'   => round($current, 4),
            'revenue_delta_pct' => round($revenueDeltaPct, 2),
            'budget_delta_pct'  => $budgetDeltaPct,
            'clamped'           => $clamped,
            'reason'            => $reason,
        ];
    }

    /**
     * Execute scaling: update global_budgets and redistribute across platforms.
     *
     * Only acts if the proposed change exceeds 2%.
     *
     * @return array  Scaling result merged with distribution result, or reason for no-op.
     */
    public function execute(int $projectId): array
    {
        $scaling = $this->calculateScaling($projectId);

        if (abs($scaling['budget_delta_pct']) <= 2.0) {
            return array_merge($scaling, [
                'action'       => 'no_change',
                'distribution' => [],
            ]);
        }

        $db           = DB::get();
        $globalBudget = new GlobalBudget();

        // Update global_budgets.daily_budget_aud.
        $db->prepare(
            "UPDATE global_budgets
             SET daily_budget_aud = ?, updated_at = datetime('now')
             WHERE project_id = ?"
        )->execute([$scaling['proposed_global'], $projectId]);

        // Redistribute across platforms.
        $distribution       = $globalBudget->distribute($projectId);
        $distributionResult = $globalBudget->executeDistribution($projectId, $distribution);

        // Log to budget_adjustments.
        $db->prepare(
            "INSERT INTO budget_adjustments
                 (project_id, trigger_type, old_global_budget, new_global_budget,
                  revenue_baseline, revenue_current, detail_json, created_at)
             VALUES (?, 'revenue_scaling', ?, ?, ?, ?, ?, datetime('now'))"
        )->execute([
            $projectId,
            $scaling['current_global'],
            $scaling['proposed_global'],
            $scaling['revenue_baseline'],
            $scaling['revenue_current'],
            json_encode(array_merge($scaling, ['distribution' => $distribution])),
        ]);

        return array_merge($scaling, [
            'action'       => 'scaled',
            'distribution' => $distributionResult,
        ]);
    }

    /**
     * Check the scaling_enabled flag; call execute() only if enabled.
     *
     * @return array  {skipped: true} when disabled, otherwise the execute() result.
     */
    public function run(int $projectId): array
    {
        $gbRow = (new GlobalBudget())->get($projectId);

        if (!$gbRow || !(bool) $gbRow['scaling_enabled']) {
            return ['skipped' => true, 'reason' => 'Revenue scaling is disabled for this project.'];
        }

        return $this->execute($projectId);
    }
}
