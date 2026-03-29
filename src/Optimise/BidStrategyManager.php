<?php

namespace AdManager\Optimise;

use AdManager\DB;
use AdManager\Google\Campaign\Search as GoogleSearchCampaign;

/**
 * Bid strategy transition logic based on conversion volume.
 *
 * Thresholds (conversions in last 30 days):
 *   <  15  — stay on manual_cpc or maximize_clicks
 *   15–30  — switch to maximize_conversions (no target)
 *   30–50  — maximize_conversions with loose tCPA (2x actual CPA)
 *   50+    — tighten tCPA to 1.2x actual CPA
 */
class BidStrategyManager
{
    /**
     * Evaluate bid strategy recommendations for all Google campaigns in a project.
     *
     * @param int $projectId
     * @return array  Each row: campaign_id, campaign_name, external_id, current_strategy,
     *                          recommended_strategy, target_cpa, conversion_count, actual_cpa, reason
     */
    public function evaluate(int $projectId): array
    {
        $db = DB::get();

        // Fetch Google campaigns with bid_strategy and external_id
        $stmt = $db->prepare(
            "SELECT id, name, external_id, bid_strategy
             FROM campaigns
             WHERE project_id = ?
               AND platform = 'google'
               AND status NOT IN ('removed', 'draft')
             ORDER BY name"
        );
        $stmt->execute([$projectId]);
        $campaigns = $stmt->fetchAll();

        $recommendations = [];

        foreach ($campaigns as $campaign) {
            $campaignId      = (int) $campaign['id'];
            $currentStrategy = $campaign['bid_strategy'] ?? 'manual_cpc';

            // Count conversions and cost over last 30 days
            $perfStmt = $db->prepare(
                "SELECT
                    SUM(p.conversions)    AS conversions,
                    SUM(p.cost_micros)    AS cost_micros
                 FROM performance p
                 WHERE p.campaign_id = ?
                   AND p.date >= date('now', '-30 days')"
            );
            $perfStmt->execute([$campaignId]);
            $perf = $perfStmt->fetch();

            $conversionCount = (float) ($perf['conversions'] ?? 0);
            $costMicros      = (int)   ($perf['cost_micros'] ?? 0);
            $cost            = $costMicros / 1_000_000;

            $actualCpa = ($conversionCount > 0 && $cost > 0)
                ? $cost / $conversionCount
                : null;

            [$recommendedStrategy, $targetCpa, $reason] = $this->recommend(
                $currentStrategy,
                $conversionCount,
                $actualCpa
            );

            $recommendations[] = [
                'campaign_id'        => $campaignId,
                'campaign_name'      => $campaign['name'],
                'external_id'        => $campaign['external_id'],
                'current_strategy'   => $currentStrategy,
                'recommended_strategy' => $recommendedStrategy,
                'target_cpa'         => $targetCpa,
                'conversion_count'   => $conversionCount,
                'actual_cpa'         => $actualCpa !== null ? round($actualCpa, 2) : null,
                'reason'             => $reason,
            ];
        }

        return $recommendations;
    }

    /**
     * Execute a set of recommendations, updating the Google Ads API and local DB.
     *
     * Only recommendations where recommended_strategy !== current_strategy are applied.
     *
     * @param  array $recommendations  Output from evaluate()
     * @return array{applied: array, errors: array}
     */
    public function apply(array $recommendations): array
    {
        $db      = DB::get();
        $applied = [];
        $errors  = [];

        foreach ($recommendations as $rec) {
            if ($rec['recommended_strategy'] === $rec['current_strategy']) {
                continue; // No change needed
            }

            $campaignId   = (int) $rec['campaign_id'];
            $newStrategy  = $rec['recommended_strategy'];
            $targetCpa    = $rec['target_cpa'];
            $externalId   = $rec['external_id'];

            // Update local DB first
            $stmt = $db->prepare(
                "UPDATE campaigns SET bid_strategy = ?, updated_at = datetime('now') WHERE id = ?"
            );
            $stmt->execute([$newStrategy, $campaignId]);

            // Attempt Google Ads API update if campaign has a resource name
            if ($externalId) {
                try {
                    $google = new GoogleSearchCampaign();

                    // Map BidStrategyManager strategy names to Search::updateBidStrategy() names
                    $apiStrategy = match ($newStrategy) {
                        'maximize_conversions_with_tcpa',
                        'maximize_conversions_tcpa_tightened' => 'target_cpa',
                        'maximize_conversions'                 => 'maximize_conversions',
                        default                                => $newStrategy,
                    };

                    $google->updateBidStrategy($externalId, $apiStrategy, $targetCpa);
                } catch (\Throwable $e) {
                    $errors[] = [
                        'campaign_id'   => $campaignId,
                        'campaign_name' => $rec['campaign_name'],
                        'error'         => $e->getMessage(),
                    ];
                    // DB already updated — platform failure is non-fatal
                }
            }

            $applied[] = [
                'campaign_id'      => $campaignId,
                'campaign_name'    => $rec['campaign_name'],
                'old_strategy'     => $rec['current_strategy'],
                'new_strategy'     => $newStrategy,
                'target_cpa'       => $targetCpa,
            ];
        }

        return ['applied' => $applied, 'errors' => $errors];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Determine the recommended strategy, target CPA, and reason.
     *
     * @param string     $currentStrategy  Current bid strategy name
     * @param float      $conversions      Conversions in last 30 days
     * @param float|null $actualCpa        Actual CPA (cost / conversions) or null if no conversions
     * @return array  [recommended_strategy, target_cpa (float|null), reason (string)]
     */
    private function recommend(
        string $currentStrategy,
        float $conversions,
        ?float $actualCpa
    ): array {
        if ($conversions < 15) {
            // Insufficient data for smart bidding — stay on manual or clicks-focused strategy
            $recommendedStrategy = in_array($currentStrategy, ['manual_cpc', 'maximize_clicks'], true)
                ? $currentStrategy
                : 'manual_cpc';
            return [
                $recommendedStrategy,
                null,
                "Only {$conversions} conversions in 30 days (< 15). Keep on {$recommendedStrategy} until enough data is available for smart bidding.",
            ];
        }

        if ($conversions < 30) {
            // Enough signal to let Google learn, but not enough to set a target
            return [
                'maximize_conversions',
                null,
                "{$conversions} conversions in 30 days (15–30 threshold). Switch to Maximize Conversions (no target) to let Google learn.",
            ];
        }

        if ($conversions <= 50) {
            // Set a loose tCPA at 2x actual CPA to give the algorithm headroom
            $looseCpa = $actualCpa !== null ? round($actualCpa * 2.0, 2) : null;
            return [
                'maximize_conversions_with_tcpa',
                $looseCpa,
                "{$conversions} conversions in 30 days (30–50 threshold). Use Maximize Conversions with loose tCPA"
                    . ($looseCpa !== null ? " of \${$looseCpa} (2x actual CPA \${$actualCpa})" : '')
                    . '.',
            ];
        }

        // 50+ conversions — tighten tCPA to 1.2x actual CPA
        $tightCpa = $actualCpa !== null ? round($actualCpa * 1.2, 2) : null;
        return [
            'maximize_conversions_tcpa_tightened',
            $tightCpa,
            "{$conversions} conversions in 30 days (50+ threshold). Tighten tCPA"
                . ($tightCpa !== null ? " to \${$tightCpa} (1.2x actual CPA \${$actualCpa})" : '')
                . '.',
        ];
    }
}
