<?php

namespace AdManager\Dashboard;

use AdManager\DB;

/**
 * Aggregated performance queries. Returns decision-ready data via Metrics::compute().
 */
class PerformanceQuery
{
    /**
     * Project-level KPIs for a date range, with comparison to prior period.
     */
    public static function projectSummary(int $projectId, int $days = 7): array
    {
        $current = self::aggregateProject($projectId, $days, 0);
        $prior = self::aggregateProject($projectId, $days, $days);

        $cm = Metrics::compute($current);
        $pm = Metrics::compute($prior);

        return [
            'current' => $cm,
            'prior'   => $pm,
            'deltas'  => [
                'cost'        => Metrics::delta($cm['cost'], $pm['cost']),
                'conversions' => Metrics::delta($cm['conversions'], $pm['conversions']),
                'cpa'         => Metrics::delta($cm['cpa'], $pm['cpa']),
                'roas'        => Metrics::delta($cm['roas'], $pm['roas']),
                'ctr'         => Metrics::delta($cm['ctr'], $pm['ctr']),
            ],
        ];
    }

    /**
     * Campaign-level breakdown for a project.
     */
    public static function campaignBreakdown(int $projectId, int $days = 14): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'SELECT
                c.id, c.name, c.platform, c.type, c.status, c.daily_budget_aud,
                SUM(p.impressions) AS impressions,
                SUM(p.clicks) AS clicks,
                SUM(p.cost_micros) AS cost_micros,
                SUM(p.conversions) AS conversions,
                SUM(p.conversion_value) AS conversion_value,
                COUNT(DISTINCT p.date) AS days_with_data
             FROM campaigns c
             LEFT JOIN performance p ON p.campaign_id = c.id
                AND p.date >= date(\'now\', ? || \' days\')
                AND p.ad_group_id IS NULL AND p.ad_id IS NULL
             WHERE c.project_id = ?
             GROUP BY c.id
             ORDER BY SUM(COALESCE(p.cost_micros, 0)) DESC'
        );
        $stmt->execute(["-{$days}", $projectId]);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $metrics = Metrics::compute($row);
            $dailyBudget = (float) $row['daily_budget_aud'];
            $daysWithData = (int) $row['days_with_data'];
            $avgDailySpend = $daysWithData > 0 ? $metrics['cost'] / $daysWithData : 0;

            $result[] = array_merge($metrics, [
                'id'                => (int) $row['id'],
                'name'              => $row['name'],
                'platform'          => $row['platform'],
                'type'              => $row['type'],
                'status'            => $row['status'],
                'daily_budget'      => round($dailyBudget, 2),
                'budget_utilisation' => $dailyBudget > 0 ? round(($avgDailySpend / $dailyBudget) * 100, 1) : null,
                'days_with_data'    => $daysWithData,
            ]);
        }
        return $result;
    }

    /**
     * Ad group breakdown within a campaign.
     */
    public static function adGroupBreakdown(int $campaignId, int $days = 14): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'SELECT
                ag.id, ag.name, ag.status,
                SUM(p.impressions) AS impressions,
                SUM(p.clicks) AS clicks,
                SUM(p.cost_micros) AS cost_micros,
                SUM(p.conversions) AS conversions,
                SUM(p.conversion_value) AS conversion_value,
                (SELECT COUNT(*) FROM ads WHERE ad_group_id = ag.id AND status != \'removed\') AS active_ads,
                (SELECT COUNT(*) FROM keywords WHERE ad_group_id = ag.id AND is_negative = 0) AS active_keywords,
                (SELECT COUNT(*) FROM split_tests WHERE ad_group_id = ag.id AND status = \'running\') AS running_tests
             FROM ad_groups ag
             LEFT JOIN performance p ON p.ad_group_id = ag.id
                AND p.date >= date(\'now\', ? || \' days\')
                AND p.ad_id IS NULL
             WHERE ag.campaign_id = ?
             GROUP BY ag.id
             ORDER BY SUM(COALESCE(p.cost_micros, 0)) DESC'
        );
        $stmt->execute(["-{$days}", $campaignId]);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $metrics = Metrics::compute($row);
            $result[] = array_merge($metrics, [
                'id'              => (int) $row['id'],
                'name'            => $row['name'],
                'status'          => $row['status'],
                'active_ads'      => (int) $row['active_ads'],
                'active_keywords' => (int) $row['active_keywords'],
                'running_tests'   => (int) $row['running_tests'],
            ]);
        }
        return $result;
    }

    /**
     * Ad-level breakdown within an ad group.
     */
    public static function adBreakdown(int $adGroupId, int $days = 14): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'SELECT
                a.id, a.type, a.status, a.final_url,
                SUM(p.impressions) AS impressions,
                SUM(p.clicks) AS clicks,
                SUM(p.cost_micros) AS cost_micros,
                SUM(p.conversions) AS conversions,
                SUM(p.conversion_value) AS conversion_value
             FROM ads a
             LEFT JOIN performance p ON p.ad_id = a.id
                AND p.date >= date(\'now\', ? || \' days\')
             WHERE a.ad_group_id = ?
               AND a.status != \'removed\'
             GROUP BY a.id
             ORDER BY SUM(COALESCE(p.cost_micros, 0)) DESC'
        );
        $stmt->execute(["-{$days}", $adGroupId]);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $metrics = Metrics::compute($row);
            $result[] = array_merge($metrics, [
                'id'        => (int) $row['id'],
                'type'      => $row['type'],
                'status'    => $row['status'],
                'final_url' => $row['final_url'],
            ]);
        }
        return $result;
    }

    /**
     * Daily performance for sparkline/chart rendering.
     */
    public static function dailySeries(int $projectId, int $days = 7): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'SELECT
                p.date,
                SUM(p.impressions) AS impressions,
                SUM(p.clicks) AS clicks,
                SUM(p.cost_micros) AS cost_micros,
                SUM(p.conversions) AS conversions,
                SUM(p.conversion_value) AS conversion_value
             FROM performance p
             JOIN campaigns c ON c.id = p.campaign_id
             WHERE c.project_id = ?
               AND p.date >= date(\'now\', ? || \' days\')
             GROUP BY p.date
             ORDER BY p.date'
        );
        $stmt->execute([$projectId, "-{$days}"]);
        $rows = $stmt->fetchAll();

        return array_map(function ($row) {
            $m = Metrics::compute($row);
            $m['date'] = $row['date'];
            return $m;
        }, $rows);
    }

    /**
     * Get goals with actual vs target comparison.
     */
    public static function goalsStatus(int $projectId, int $days = 14): array
    {
        $db = DB::get();
        $goalsStmt = $db->prepare('SELECT * FROM goals WHERE project_id = ?');
        $goalsStmt->execute([$projectId]);
        $goals = $goalsStmt->fetchAll();

        if (empty($goals)) return [];

        $summary = self::projectSummary($projectId, $days);
        $current = $summary['current'];

        $result = [];
        foreach ($goals as $g) {
            $metric = $g['metric'];
            $target = (float) $g['target_value'];
            $actual = $current[$metric] ?? null;

            $result[] = [
                'metric'   => $metric,
                'platform' => $g['platform'],
                'target'   => $target,
                'actual'   => $actual,
                'on_track' => self::isOnTrack($metric, $actual, $target),
            ];
        }
        return $result;
    }

    /**
     * Get sync status for a project.
     */
    public static function syncStatus(int $projectId): array
    {
        $db = DB::get();

        // Last successful sync from sync_jobs
        $stmt = $db->prepare(
            "SELECT completed_at FROM sync_jobs
             WHERE project_id = ? AND status = 'complete'
             ORDER BY completed_at DESC LIMIT 1"
        );
        $stmt->execute([$projectId]);
        $lastJob = $stmt->fetchColumn();

        // Fallback: last performance data date
        $stmt2 = $db->prepare(
            'SELECT MAX(p.created_at) FROM performance p
             JOIN campaigns c ON c.id = p.campaign_id
             WHERE c.project_id = ?'
        );
        $stmt2->execute([$projectId]);
        $lastPerf = $stmt2->fetchColumn();

        $lastSync = $lastJob ?: $lastPerf;

        // Check for running sync
        $running = $db->prepare(
            "SELECT id FROM sync_jobs WHERE project_id = ? AND status IN ('pending', 'running') LIMIT 1"
        );
        $running->execute([$projectId]);
        $isRunning = (bool) $running->fetch();

        return [
            'last_sync_at' => $lastSync,
            'seconds_ago'  => $lastSync ? time() - strtotime($lastSync) : null,
            'is_running'   => $isRunning,
        ];
    }

    // ── Private helpers ─────────────────────────────────────────

    private static function aggregateProject(int $projectId, int $days, int $offsetDays): array
    {
        $db = DB::get();
        $from = "-" . ($days + $offsetDays);
        $to = $offsetDays > 0 ? "-{$offsetDays}" : '0';

        $stmt = $db->prepare(
            "SELECT
                SUM(p.impressions) AS impressions,
                SUM(p.clicks) AS clicks,
                SUM(p.cost_micros) AS cost_micros,
                SUM(p.conversions) AS conversions,
                SUM(p.conversion_value) AS conversion_value
             FROM performance p
             JOIN campaigns c ON c.id = p.campaign_id
             WHERE c.project_id = ?
               AND p.date >= date('now', ? || ' days')
               AND p.date < date('now', ? || ' days')"
        );
        $stmt->execute([$projectId, $from, $to]);
        return $stmt->fetch() ?: [];
    }

    /**
     * Determine if a metric is on track relative to its target.
     * CPA: lower is better. ROAS: higher is better. CTR: higher is better.
     */
    private static function isOnTrack(string $metric, ?float $actual, float $target): ?bool
    {
        if ($actual === null) return null;
        return match ($metric) {
            'cpa'  => $actual <= $target,
            'roas' => $actual >= $target,
            'ctr'  => $actual >= $target,
            default => $actual >= $target,
        };
    }
}
