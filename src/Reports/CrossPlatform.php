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
     * Compute a normalised 0-100 effectiveness score per platform.
     *
     * Components and weights:
     *   ROAS     → 0.40  (higher is better)
     *   CPA      → 0.30  (lower is better — inverted before normalising)
     *   CTR      → 0.15  (higher is better)
     *   Volume   → 0.15  (conversion count, higher is better)
     *
     * Each component is min-max normalised across all platforms, then weighted
     * and summed to produce a 0-100 score.
     *
     * @return array  [{platform, score, components: {roas_score, cpa_score, ctr_score, volume_score}}]
     */
    public function effectivenessScore(int $projectId, string $startDate, string $endDate): array
    {
        $summary = $this->summary($projectId, $startDate, $endDate);
        $rows    = $summary['rows'];

        if (empty($rows)) {
            return [];
        }

        // Extract raw metric values per platform.
        $roasValues   = array_column($rows, 'roas');
        $cpaValues    = array_map(fn($r) => $r['cpa'] ?? 0.0, $rows);
        $ctrValues    = array_column($rows, 'ctr');
        $volValues    = array_column($rows, 'conversions');

        // For CPA: invert so that lower CPA → higher score.
        // Use 1/(cpa+1) to avoid division by zero and keep it in [0,1] shape.
        $invCpaValues = array_map(fn($v) => $v > 0 ? 1.0 / $v : 0.0, $cpaValues);

        $normalise = static function (array $values): array {
            $min = min($values);
            $max = max($values);
            $range = $max - $min;

            if ($range == 0.0) {
                return array_fill(0, count($values), 1.0); // all equal → all score 1
            }

            return array_map(fn($v) => ($v - $min) / $range, $values);
        };

        $normRoas   = $normalise($roasValues);
        $normInvCpa = $normalise($invCpaValues);
        $normCtr    = $normalise($ctrValues);
        $normVol    = $normalise($volValues);

        $result = [];
        foreach ($rows as $i => $row) {
            $roasScore   = $normRoas[$i];
            $cpaScore    = $normInvCpa[$i];
            $ctrScore    = $normCtr[$i];
            $volumeScore = $normVol[$i];

            $weighted = $roasScore * 0.40
                      + $cpaScore  * 0.30
                      + $ctrScore  * 0.15
                      + $volumeScore * 0.15;

            $result[] = [
                'platform' => $row['platform'],
                'score'    => round($weighted * 100.0, 1),
                'components' => [
                    'roas_score'   => round($roasScore   * 100.0, 1),
                    'cpa_score'    => round($cpaScore    * 100.0, 1),
                    'ctr_score'    => round($ctrScore    * 100.0, 1),
                    'volume_score' => round($volumeScore * 100.0, 1),
                ],
            ];
        }

        // Sort descending by score.
        usort($result, fn($a, $b) => $b['score'] <=> $a['score']);

        return $result;
    }

    /**
     * Return per-platform time-series performance data.
     *
     * @param  string $granularity  'day' (default) or 'week'
     * @return array{
     *   dates: string[],
     *   platforms: array<string, array<array{date: string, spend: float, clicks: int,
     *     impressions: int, conversions: float, cpa: float|null, roas: float}>>
     * }
     */
    public function timeSeries(
        int $projectId,
        string $startDate,
        string $endDate,
        string $granularity = 'day'
    ): array {
        $db = DB::get();

        if ($granularity === 'week') {
            // SQLite: strftime('%Y-%W', date) groups by ISO year-week.
            $dateBucket = "strftime('%Y-W%W', p.date)";
        } else {
            $dateBucket = 'p.date';
        }

        $stmt = $db->prepare(<<<SQL
            SELECT
                c.platform,
                {$dateBucket}                AS bucket,
                SUM(p.impressions)           AS impressions,
                SUM(p.clicks)                AS clicks,
                SUM(p.cost_micros)           AS cost_micros,
                SUM(p.conversions)           AS conversions,
                SUM(p.conversion_value)      AS conversion_value
            FROM performance p
            JOIN campaigns c ON c.id = p.campaign_id
            WHERE c.project_id = :project_id
              AND p.date BETWEEN :start_date AND :end_date
            GROUP BY c.platform, {$dateBucket}
            ORDER BY c.platform, {$dateBucket}
        SQL);

        $stmt->execute([
            ':project_id' => $projectId,
            ':start_date' => $startDate,
            ':end_date'   => $endDate,
        ]);

        $rawRows  = $stmt->fetchAll();
        $dateSet  = [];
        $byPlatform = [];

        foreach ($rawRows as $r) {
            $bucket      = $r['bucket'];
            $platform    = $r['platform'];
            $spend       = (int) ($r['cost_micros'] ?? 0) / 1_000_000;
            $clicks      = (int) ($r['clicks'] ?? 0);
            $impressions = (int) ($r['impressions'] ?? 0);
            $conversions = (float) ($r['conversions'] ?? 0);
            $revenue     = (float) ($r['conversion_value'] ?? 0);

            $dateSet[$bucket] = true;

            $byPlatform[$platform][] = [
                'date'        => $bucket,
                'spend'       => round($spend, 2),
                'clicks'      => $clicks,
                'impressions' => $impressions,
                'conversions' => $conversions,
                'cpa'         => $conversions > 0 ? round($spend / $conversions, 2) : null,
                'roas'        => $spend > 0 ? round($revenue / $spend, 4) : 0.0,
            ];
        }

        $dates = array_keys($dateSet);
        sort($dates);

        return ['dates' => $dates, 'platforms' => $byPlatform];
    }

    /**
     * Recommend budget rebalancing based on effectiveness vs budget share gaps.
     *
     * If a platform's effectiveness share exceeds its budget share by more than 10%,
     * recommend shifting 50% of the gap from the under-performer to the over-performer.
     *
     * @return array  [{from_platform, to_platform, shift_amount_aud, reason}]
     */
    public function rebalanceRecommendations(int $projectId, string $startDate, string $endDate): array
    {
        $db = DB::get();

        $scores = $this->effectivenessScore($projectId, $startDate, $endDate);

        if (count($scores) < 2) {
            return [];
        }

        // Current budget per platform.
        $budgetStmt = $db->prepare(
            'SELECT platform, daily_budget_aud FROM budgets WHERE project_id = ?'
        );
        $budgetStmt->execute([$projectId]);
        $budgetMap = [];
        foreach ($budgetStmt->fetchAll() as $b) {
            $budgetMap[$b['platform']] = (float) $b['daily_budget_aud'];
        }

        $totalBudget = array_sum($budgetMap);
        if ($totalBudget <= 0.0) {
            return [];
        }

        $totalEffectiveness = array_sum(array_column($scores, 'score'));
        if ($totalEffectiveness <= 0.0) {
            return [];
        }

        // Build per-platform share maps.
        $effectivenessShare = [];
        foreach ($scores as $s) {
            $effectivenessShare[$s['platform']] = $s['score'] / $totalEffectiveness;
        }

        $budgetShare = [];
        foreach ($scores as $s) {
            $p = $s['platform'];
            $budgetShare[$p] = isset($budgetMap[$p]) ? $budgetMap[$p] / $totalBudget : 0.0;
        }

        // Find pairs where the gap > 10%.
        $platforms    = array_column($scores, 'platform');
        $recommendations = [];

        for ($i = 0; $i < count($platforms); $i++) {
            for ($j = $i + 1; $j < count($platforms); $j++) {
                $pA   = $platforms[$i];
                $pB   = $platforms[$j];

                $effGap = ($effectivenessShare[$pA] ?? 0.0) - ($budgetShare[$pA] ?? 0.0)
                        - (($effectivenessShare[$pB] ?? 0.0) - ($budgetShare[$pB] ?? 0.0));

                if (abs($effGap) <= 0.10) {
                    continue;
                }

                // pA has higher effectiveness-share surplus → should receive budget from pB.
                $from = $effGap > 0 ? $pB : $pA;
                $to   = $effGap > 0 ? $pA : $pB;

                $gapAud      = abs($effGap) * $totalBudget;
                $shiftAmount = round($gapAud * 0.50, 2); // conservative 50% of gap

                $fromScore = $effectivenessShare[$from] ?? 0.0;
                $toScore   = $effectivenessShare[$to]   ?? 0.0;

                $recommendations[] = [
                    'from_platform'    => $from,
                    'to_platform'      => $to,
                    'shift_amount_aud' => $shiftAmount,
                    'reason'           => sprintf(
                        '%s effectiveness share %.1f%% vs budget share %.1f%% (gap %.1f%%). %s underperforming at %.1f%% effectiveness.',
                        ucfirst($to),
                        ($effectivenessShare[$to] ?? 0.0) * 100.0,
                        ($budgetShare[$to] ?? 0.0) * 100.0,
                        abs($effGap) * 100.0,
                        ucfirst($from),
                        $fromScore * 100.0
                    ),
                ];
            }
        }

        // Sort by shift amount descending.
        usort($recommendations, fn($a, $b) => $b['shift_amount_aud'] <=> $a['shift_amount_aud']);

        return $recommendations;
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
