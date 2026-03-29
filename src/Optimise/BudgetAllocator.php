<?php

namespace AdManager\Optimise;

use AdManager\DB;
use AdManager\Google\Campaign\Search as GoogleSearchCampaign;

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
     * Execute budget recommendations: update the DB and attempt platform-level updates.
     *
     * Each recommendation is the output shape from recommend():
     *   ['campaign_id', 'platform', 'recommended_budget', ...]
     *
     * @param  array $recommendations  Output from recommend()
     * @param  int   $projectId        Owning project (used to update budgets table)
     * @return array{updated: int[], errors: string[]}
     */
    public function execute(array $recommendations, int $projectId): array
    {
        $db      = DB::get();
        $updated = [];
        $errors  = [];

        foreach ($recommendations as $rec) {
            $campaignId      = (int) $rec['campaign_id'];
            $recommendedAud  = (float) $rec['recommended_budget'];
            $platform        = $rec['platform'];

            // 1. Update the campaign's daily_budget_aud in the DB
            $stmt = $db->prepare(
                'UPDATE campaigns SET daily_budget_aud = ?, updated_at = datetime(\'now\') WHERE id = ?'
            );
            $stmt->execute([$recommendedAud, $campaignId]);

            // 2. Update the platform budget table (budgets row for this project+platform)
            //    We recalculate total from all active campaigns to keep it consistent.
            $totalStmt = $db->prepare(
                "SELECT SUM(daily_budget_aud) AS total
                 FROM campaigns
                 WHERE project_id = ? AND platform = ? AND status IN ('enabled', 'active')"
            );
            $totalStmt->execute([$projectId, $platform]);
            $total = (float) ($totalStmt->fetch()['total'] ?? 0);

            $budgetStmt = $db->prepare(
                'INSERT INTO budgets (project_id, platform, daily_budget_aud, updated_at)
                 VALUES (?, ?, ?, datetime(\'now\'))
                 ON CONFLICT(project_id, platform) DO UPDATE
                 SET daily_budget_aud = excluded.daily_budget_aud,
                     updated_at = excluded.updated_at'
            );
            $budgetStmt->execute([$projectId, $platform, $total]);

            // 3. Attempt platform-level update if the campaign has an external_id
            $extStmt = $db->prepare('SELECT external_id FROM campaigns WHERE id = ?');
            $extStmt->execute([$campaignId]);
            $externalId = $extStmt->fetch()['external_id'] ?? null;

            if ($externalId) {
                try {
                    if ($platform === 'google') {
                        // Google budget is in micros; external_id is the budget resource name
                        $google = new GoogleSearchCampaign();
                        $google->updateBudget($externalId, $recommendedAud * 1_000_000);
                    } elseif ($platform === 'meta') {
                        // Meta daily_budget is in cents; use the Graph API directly via client
                        \AdManager\Meta\Client::get()->post($externalId, [
                            'daily_budget' => (int) round($recommendedAud * 100),
                        ]);
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Failed to update campaign {$campaignId} (external: {$externalId}) on {$platform}: {$e->getMessage()}";
                    // DB already updated — platform sync failure is non-fatal
                }
            }

            $updated[] = $campaignId;
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    /**
     * Recommend budget reallocation across ALL platforms for a project.
     *
     * Uses the same ROAS/CTR scoring logic as recommend(), but allocates against
     * the project's total budget across all platforms rather than within a single
     * platform.
     *
     * Exclusion rules:
     *   - Platforms live < 14 days (based on earliest campaign created_at) are excluded.
     *   - Platforms with fewer than 50 total conversions (last 14 days) are excluded.
     *
     * Floor: no platform may drop below 30% of total project budget.
     *
     * @return array of ['platform', 'current_budget', 'recommended_budget', 'reason', ...]
     */
    public function recommendCrossPlatform(int $projectId): array
    {
        $db = DB::get();

        // Aggregate performance and budget per platform
        $stmt = $db->prepare(
            "SELECT
                c.platform,
                MIN(c.created_at) AS earliest_created,
                SUM(p.impressions) AS impressions,
                SUM(p.clicks) AS clicks,
                SUM(p.cost_micros) AS cost_micros,
                SUM(p.conversions) AS conversions,
                SUM(p.conversion_value) AS conversion_value
             FROM campaigns c
             LEFT JOIN performance p ON p.campaign_id = c.id
                AND p.date >= date('now', '-14 days')
             WHERE c.project_id = ?
               AND c.status IN ('enabled', 'active')
             GROUP BY c.platform"
        );
        $stmt->execute([$projectId]);
        $platformRows = $stmt->fetchAll();

        if (empty($platformRows)) {
            return [];
        }

        // Current budget per platform (from budgets table)
        $budgetStmt = $db->prepare(
            'SELECT platform, daily_budget_aud FROM budgets WHERE project_id = ?'
        );
        $budgetStmt->execute([$projectId]);
        $budgetMap = [];
        foreach ($budgetStmt->fetchAll() as $b) {
            $budgetMap[$b['platform']] = (float) $b['daily_budget_aud'];
        }

        $totalBudget = array_sum($budgetMap);
        if ($totalBudget <= 0) {
            return [];
        }

        $now          = time();
        $minAgeDays   = 14;
        $minConversions = 50;

        $eligible    = [];
        $ineligible  = [];

        foreach ($platformRows as $r) {
            $platform  = $r['platform'];
            $agedays   = $r['earliest_created']
                ? (int) (($now - strtotime($r['earliest_created'])) / 86400)
                : 0;
            $convs     = (float) ($r['conversions'] ?? 0);

            $current  = $budgetMap[$platform] ?? 0.0;
            $cost     = (int) ($r['cost_micros'] ?? 0) / 1_000_000;
            $revenue  = (float) ($r['conversion_value'] ?? 0);
            $clicks   = (int) ($r['clicks'] ?? 0);
            $imps     = (int) ($r['impressions'] ?? 0);
            $roas     = $cost > 0 ? $revenue / $cost : 0.0;
            $ctr      = $imps > 0 ? ($clicks / $imps) * 100 : 0.0;

            if ($agedays < $minAgeDays) {
                $ineligible[] = [
                    'platform'           => $platform,
                    'current_budget'     => round($current, 2),
                    'recommended_budget' => round($current, 2),
                    'reason'             => "Platform live only {$agedays} days — excluded from rebalancing (min {$minAgeDays}).",
                    'excluded'           => true,
                ];
                continue;
            }

            if ($convs < $minConversions) {
                $ineligible[] = [
                    'platform'           => $platform,
                    'current_budget'     => round($current, 2),
                    'recommended_budget' => round($current, 2),
                    'reason'             => "Only {$convs} conversions in last 14 days — excluded from rebalancing (min {$minConversions}).",
                    'excluded'           => true,
                ];
                continue;
            }

            $score = 0.0;
            if ($convs > 0) {
                $score = $roas * 0.7 + $ctr * 0.3;
            } elseif ($clicks > 0) {
                $score = $ctr * 0.3;
            }

            $eligible[] = [
                'platform'        => $platform,
                'current_budget'  => round($current, 2),
                'conversions'     => $convs,
                'cost'            => round($cost, 2),
                'revenue'         => round($revenue, 2),
                'roas'            => round($roas, 2),
                'ctr'             => round($ctr, 2),
                'impressions'     => $imps,
                'score'           => $score,
            ];
        }

        if (count($eligible) < 2) {
            // Cannot rebalance with fewer than 2 eligible platforms — return ineligible notices only
            return $ineligible;
        }

        // Budget to distribute = sum of eligible platforms' current budgets
        $eligibleBudget = array_sum(array_column($eligible, 'current_budget'));
        $floor          = $eligibleBudget * 0.30;
        $totalScore     = array_sum(array_column($eligible, 'score'));

        $recommendations = [];
        foreach ($eligible as $p) {
            $current = $p['current_budget'];

            $recommended = $current; // fallback: no data → keep current
            if ($totalScore > 0) {
                $idealShare  = ($p['score'] / $totalScore) * $eligibleBudget;
                // Apply 30% floor and ±50% per-step guard
                $maxIncrease = $current * 1.5;
                $minDecrease = max($current * 0.5, $floor);
                $recommended = max($minDecrease, min($maxIncrease, $idealShare));
            }

            $recommended = round($recommended, 2);
            $change      = $recommended - $current;
            $changePct   = $current > 0 ? ($change / $current) * 100 : 0.0;

            $direction = $change > 0 ? 'Increase' : ($change < 0 ? 'Decrease' : 'Maintain');
            $parts = ["{$direction} by " . abs(round($changePct, 0)) . "% (cross-platform reallocation)"];
            if ($p['roas'] > 0) {
                $parts[] = "ROAS: {$p['roas']}x";
            }
            if ($p['conversions'] > 0) {
                $parts[] = "Conversions: {$p['conversions']}";
            }

            if (abs($changePct) > 5) {
                $recommendations[] = [
                    'platform'           => $p['platform'],
                    'current_budget'     => $current,
                    'recommended_budget' => $recommended,
                    'change'             => round($change, 2),
                    'change_pct'         => round($changePct, 1),
                    'roas'               => $p['roas'],
                    'conversions'        => $p['conversions'],
                    'reason'             => implode('. ', $parts) . '.',
                    'excluded'           => false,
                ];
            }
        }

        // Sort by absolute change descending, then append ineligible notices
        usort($recommendations, fn($a, $b) => abs($b['change']) <=> abs($a['change']));

        return array_merge($recommendations, $ineligible);
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
