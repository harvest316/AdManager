<?php

namespace AdManager\Reports;

use AdManager\DB;

/**
 * Cross-platform reporting — aggregates Google + Meta + GA4 into unified views.
 */
class CrossPlatform
{
    /**
     * Per-platform performance summary plus a totals row.
     *
     * Pulls from the performance table (joined to campaigns) grouped by platform.
     * Returns spend in AUD (cost_micros / 1_000_000).
     *
     * @return array{
     *   rows: array<array{
     *     platform: string,
     *     spend: float,
     *     clicks: int,
     *     impressions: int,
     *     conversions: float,
     *     cpa: float|null,
     *     roas: float,
     *     ctr: float
     *   }>,
     *   totals: array{
     *     spend: float,
     *     clicks: int,
     *     impressions: int,
     *     conversions: float,
     *     cpa: float|null,
     *     roas: float,
     *     ctr: float
     *   }
     * }
     */
    public function summary(int $projectId, string $startDate, string $endDate): array
    {
        $db = DB::get();

        $stmt = $db->prepare(<<<'SQL'
            SELECT
                c.platform,
                SUM(p.impressions)      AS impressions,
                SUM(p.clicks)           AS clicks,
                SUM(p.cost_micros)      AS cost_micros,
                SUM(p.conversions)      AS conversions,
                SUM(p.conversion_value) AS conversion_value
            FROM performance p
            JOIN campaigns c ON c.id = p.campaign_id
            WHERE c.project_id = :project_id
              AND p.date BETWEEN :start_date AND :end_date
            GROUP BY c.platform
            ORDER BY c.platform
        SQL);

        $stmt->execute([
            ':project_id' => $projectId,
            ':start_date' => $startDate,
            ':end_date'   => $endDate,
        ]);

        $platformRows = $stmt->fetchAll();

        $rows   = [];
        $totals = [
            'spend'       => 0.0,
            'clicks'      => 0,
            'impressions' => 0,
            'conversions' => 0.0,
            'revenue'     => 0.0,
        ];

        foreach ($platformRows as $r) {
            $spend       = (int) ($r['cost_micros'] ?? 0) / 1_000_000;
            $clicks      = (int) ($r['clicks'] ?? 0);
            $impressions = (int) ($r['impressions'] ?? 0);
            $conversions = (float) ($r['conversions'] ?? 0);
            $revenue     = (float) ($r['conversion_value'] ?? 0);

            $rows[] = [
                'platform'    => $r['platform'],
                'spend'       => round($spend, 2),
                'clicks'      => $clicks,
                'impressions' => $impressions,
                'conversions' => $conversions,
                'cpa'         => $conversions > 0 ? round($spend / $conversions, 2) : null,
                'roas'        => $spend > 0 ? round($revenue / $spend, 4) : 0.0,
                'ctr'         => $impressions > 0 ? round(($clicks / $impressions) * 100, 4) : 0.0,
            ];

            $totals['spend']       += $spend;
            $totals['clicks']      += $clicks;
            $totals['impressions'] += $impressions;
            $totals['conversions'] += $conversions;
            $totals['revenue']     += $revenue;
        }

        $totals['spend']       = round($totals['spend'], 2);
        $totals['cpa']         = $totals['conversions'] > 0
            ? round($totals['spend'] / $totals['conversions'], 2)
            : null;
        $totals['roas']        = $totals['spend'] > 0
            ? round($totals['revenue'] / $totals['spend'], 4)
            : 0.0;
        $totals['ctr']         = $totals['impressions'] > 0
            ? round(($totals['clicks'] / $totals['impressions']) * 100, 4)
            : 0.0;

        unset($totals['revenue']); // internal only — not in public totals shape

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Compare GA4 conversions against platform-reported conversions per platform.
     *
     * Returns one row per platform with:
     *   - platform_conversions  (sum from performance table)
     *   - ga4_conversions       (total from ga4_performance for same project/period)
     *   - adjustment_factor     (ga4 / platform, or null if platform = 0)
     *   - discrepancy_pct       (|platform - ga4| / ga4 * 100, or null)
     *   - flagged               (true if discrepancy > 15%)
     *
     * Note: GA4 conversions are not broken down by platform, so the same GA4
     * total is compared against each platform individually. The adjustment_factor
     * tells you how much to scale a given platform's conversion count to match GA4.
     */
    public function conversionReconciliation(int $projectId, string $startDate, string $endDate): array
    {
        $db = DB::get();

        // Per-platform conversion totals from ad platforms
        $perfStmt = $db->prepare(<<<'SQL'
            SELECT
                c.platform,
                SUM(p.conversions) AS conversions
            FROM performance p
            JOIN campaigns c ON c.id = p.campaign_id
            WHERE c.project_id = :project_id
              AND p.date BETWEEN :start_date AND :end_date
            GROUP BY c.platform
        SQL);
        $perfStmt->execute([
            ':project_id' => $projectId,
            ':start_date' => $startDate,
            ':end_date'   => $endDate,
        ]);
        $platformData = $perfStmt->fetchAll();

        // GA4 total conversions for the same period
        $ga4Stmt = $db->prepare(<<<'SQL'
            SELECT SUM(conversions) AS total_conversions
            FROM ga4_performance
            WHERE project_id = :project_id
              AND date BETWEEN :start_date AND :end_date
        SQL);
        $ga4Stmt->execute([
            ':project_id' => $projectId,
            ':start_date' => $startDate,
            ':end_date'   => $endDate,
        ]);
        $ga4Total = (float) ($ga4Stmt->fetch()['total_conversions'] ?? 0);

        $result = [];
        foreach ($platformData as $r) {
            $platformConversions = (float) ($r['conversions'] ?? 0);

            $adjustmentFactor = null;
            $discrepancyPct   = null;
            $flagged          = false;

            if ($platformConversions > 0) {
                $adjustmentFactor = $ga4Total > 0 ? round($ga4Total / $platformConversions, 4) : null;
            }

            if ($ga4Total > 0) {
                $discrepancyPct = round(abs($platformConversions - $ga4Total) / $ga4Total * 100, 1);
                $flagged        = $discrepancyPct > 15.0;
            }

            $result[] = [
                'platform'             => $r['platform'],
                'platform_conversions' => $platformConversions,
                'ga4_conversions'      => $ga4Total,
                'adjustment_factor'    => $adjustmentFactor,
                'discrepancy_pct'      => $discrepancyPct,
                'flagged'              => $flagged,
            ];
        }

        return $result;
    }

    /**
     * Side-by-side platform comparison with winner annotations.
     *
     * Returns one row per platform with CPA, ROAS, CTR plus:
     *   - best_cpa      (bool) — this platform has the lowest CPA
     *   - best_roas     (bool) — this platform has the highest ROAS
     *   - best_ctr      (bool) — this platform has the highest CTR
     *   - cpa_vs_best   (float|null) — % worse than best CPA (null if this IS best)
     *   - roas_vs_best  (float|null) — % worse than best ROAS (null if this IS best)
     *   - ctr_vs_best   (float|null) — % worse than best CTR (null if this IS best)
     */
    public function platformComparison(int $projectId, string $startDate, string $endDate): array
    {
        $summary = $this->summary($projectId, $startDate, $endDate);
        $rows    = $summary['rows'];

        if (empty($rows)) {
            return [];
        }

        // Find best (min CPA, max ROAS, max CTR)
        $cpas  = array_filter(array_column($rows, 'cpa'), fn($v) => $v !== null);
        $roass = array_column($rows, 'roas');
        $ctrs  = array_column($rows, 'ctr');

        $bestCpa  = !empty($cpas)  ? min($cpas)  : null;
        $bestRoas = !empty($roass) ? max($roass) : null;
        $bestCtr  = !empty($ctrs)  ? max($ctrs)  : null;

        $result = [];
        foreach ($rows as $row) {
            $cpa  = $row['cpa'];
            $roas = $row['roas'];
            $ctr  = $row['ctr'];

            $isBestCpa  = $bestCpa  !== null && $cpa  !== null && abs($cpa  - $bestCpa)  < 0.001;
            $isBestRoas = $bestRoas !== null && abs($roas - $bestRoas) < 0.0001;
            $isBestCtr  = $bestCtr  !== null && abs($ctr  - $bestCtr)  < 0.0001;

            // % difference versus best (positive = worse)
            $cpaVsBest  = (!$isBestCpa  && $bestCpa  !== null && $cpa  !== null && $bestCpa > 0)
                ? round(($cpa  - $bestCpa)  / $bestCpa  * 100, 1) : null;
            $roasVsBest = (!$isBestRoas && $bestRoas !== null && $bestRoas > 0)
                ? round(($bestRoas - $roas) / $bestRoas * 100, 1) : null;
            $ctrVsBest  = (!$isBestCtr  && $bestCtr  !== null && $bestCtr > 0)
                ? round(($bestCtr  - $ctr)  / $bestCtr  * 100, 1) : null;

            $result[] = array_merge($row, [
                'best_cpa'     => $isBestCpa,
                'best_roas'    => $isBestRoas,
                'best_ctr'     => $isBestCtr,
                'cpa_vs_best'  => $cpaVsBest,
                'roas_vs_best' => $roasVsBest,
                'ctr_vs_best'  => $ctrVsBest,
            ]);
        }

        return $result;
    }
}
