<?php

namespace AdManager\Optimise;

use AdManager\DB;

class BudgetAllocator
{
    /**
     * Recommend budget reallocation based on campaign performance.
     *
     * @return array of ['campaign_id', 'campaign_name', 'current_budget', 'recommended_budget', 'reason']
     */
    public function recommend(int $projectId): array
    {
        $db = DB::get();

        // Get active campaigns with their budgets and recent performance
        $stmt = $db->prepare(
            'SELECT
                c.id,
                c.name,
                c.platform,
                c.daily_budget_aud,
                SUM(p.impressions) AS impressions,
                SUM(p.clicks) AS clicks,
                SUM(p.cost_micros) AS cost_micros,
                SUM(p.conversions) AS conversions,
                SUM(p.conversion_value) AS conversion_value
             FROM campaigns c
             LEFT JOIN performance p ON p.campaign_id = c.id
                AND p.date >= date(\'now\', \'-14 days\')
             WHERE c.project_id = ?
               AND c.status IN (\'enabled\', \'active\')
             GROUP BY c.id
             ORDER BY c.name'
        );
        $stmt->execute([$projectId]);
        $campaigns = $stmt->fetchAll();

        if (count($campaigns) < 2) {
            return []; // Need at least 2 campaigns to reallocate
        }

        // Get the project's total budget for this platform
        $budgetStmt = $db->prepare(
            'SELECT platform, SUM(daily_budget_aud) AS total
             FROM budgets
             WHERE project_id = ?
             GROUP BY platform'
        );
        $budgetStmt->execute([$projectId]);
        $totalBudgets = [];
        foreach ($budgetStmt->fetchAll() as $b) {
            $totalBudgets[$b['platform']] = (float) $b['total'];
        }

        // Calculate performance scores for each campaign
        $scored = [];
        foreach ($campaigns as $c) {
            $costMicros = (int) ($c['cost_micros'] ?? 0);
            $cost = $costMicros / 1_000_000;
            $conversions = (float) ($c['conversions'] ?? 0);
            $conversionValue = (float) ($c['conversion_value'] ?? 0);
            $clicks = (int) ($c['clicks'] ?? 0);
            $impressions = (int) ($c['impressions'] ?? 0);

            // Calculate ROAS (return on ad spend)
            $roas = $cost > 0 ? $conversionValue / $cost : 0;

            // Calculate CPA
            $cpa = $conversions > 0 ? $cost / $conversions : PHP_FLOAT_MAX;

            // Calculate CTR
            $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;

            // Composite score: weighted by ROAS (primary) and CTR (secondary)
            // Higher score = better performance = deserves more budget
            $score = 0;
            if ($conversions > 0) {
                $score = $roas * 0.7 + $ctr * 0.3;
            } elseif ($clicks > 0) {
                $score = $ctr * 0.3; // No conversions yet, score on engagement only
            }

            $scored[] = [
                'id'               => (int) $c['id'],
                'name'             => $c['name'],
                'platform'         => $c['platform'],
                'current_budget'   => (float) ($c['daily_budget_aud'] ?? 0),
                'cost'             => round($cost, 2),
                'conversions'      => $conversions,
                'conversion_value' => $conversionValue,
                'roas'             => round($roas, 2),
                'cpa'              => $cpa < PHP_FLOAT_MAX ? round($cpa, 2) : null,
                'ctr'              => round($ctr, 2),
                'impressions'      => $impressions,
                'score'            => round($score, 4),
            ];
        }

        // Calculate total score and proportional allocation
        $totalScore = array_sum(array_column($scored, 'score'));

        $recommendations = [];
        foreach ($scored as $campaign) {
            $currentBudget = $campaign['current_budget'];
            $platform = $campaign['platform'];
            $platformTotal = $totalBudgets[$platform] ?? $currentBudget;

            if ($totalScore > 0 && $platformTotal > 0) {
                // Allocate budget proportional to score, but cap changes at +/- 50%
                $idealShare = ($campaign['score'] / $totalScore) * $platformTotal;
                $maxIncrease = $currentBudget * 1.5;
                $minDecrease = $currentBudget * 0.5;
                $recommended = max($minDecrease, min($maxIncrease, $idealShare));
            } else {
                $recommended = $currentBudget; // No data, keep current
            }

            $recommended = round($recommended, 2);
            $change = $recommended - $currentBudget;
            $changePct = $currentBudget > 0 ? ($change / $currentBudget) * 100 : 0;

            // Generate reason
            $reason = $this->buildReason($campaign, $change, $changePct);

            // Only include if there's a meaningful change (> 5%)
            if (abs($changePct) > 5) {
                $recommendations[] = [
                    'campaign_id'        => $campaign['id'],
                    'campaign_name'      => $campaign['name'],
                    'platform'           => $platform,
                    'current_budget'     => $currentBudget,
                    'recommended_budget' => $recommended,
                    'change'             => round($change, 2),
                    'change_pct'         => round($changePct, 1),
                    'roas'               => $campaign['roas'],
                    'cpa'                => $campaign['cpa'],
                    'reason'             => $reason,
                ];
            }
        }

        // Sort by absolute change descending
        usort($recommendations, fn($a, $b) => abs($b['change']) <=> abs($a['change']));

        return $recommendations;
    }

    /**
     * Build a human-readable reason for the budget recommendation.
     */
    private function buildReason(array $campaign, float $change, float $changePct): string
    {
        if (abs($changePct) < 5) {
            return 'Performance in line with expectations — maintain current budget.';
        }

        $direction = $change > 0 ? 'Increase' : 'Decrease';
        $parts = ["{$direction} by " . abs(round($changePct, 0)) . "%"];

        if ($campaign['roas'] > 0) {
            $parts[] = "ROAS: {$campaign['roas']}x";
        }

        if ($campaign['cpa'] !== null) {
            $parts[] = "CPA: \${$campaign['cpa']}";
        }

        if ($campaign['conversions'] == 0 && $campaign['cost'] > 0) {
            $parts[] = "Spent \${$campaign['cost']} with zero conversions";
        }

        if ($campaign['ctr'] > 5) {
            $parts[] = "Strong CTR: {$campaign['ctr']}%";
        } elseif ($campaign['ctr'] < 1 && $campaign['impressions'] > 100) {
            $parts[] = "Low CTR: {$campaign['ctr']}%";
        }

        return implode('. ', $parts) . '.';
    }
}
