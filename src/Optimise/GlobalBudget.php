<?php

declare(strict_types=1);

namespace AdManager\Optimise;

use AdManager\DB;

class GlobalBudget
{
    /**
     * Upsert a global budget record for a project.
     *
     * Only non-null arguments update their respective column — existing values
     * are preserved for any argument left as null.
     */
    public function set(
        int $projectId,
        float $dailyAud,
        ?float $min = null,
        ?float $max = null,
        ?float $maxVariancePct = null,
        ?bool $scalingEnabled = null
    ): void {
        $db = DB::get();

        // Attempt INSERT first; if the row already exists, fall through to UPDATE.
        $insertStmt = $db->prepare(
            "INSERT OR IGNORE INTO global_budgets
                 (project_id, daily_budget_aud, min_daily_budget_aud, max_daily_budget_aud,
                  max_variance_pct, scaling_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))"
        );
        $insertStmt->execute([
            $projectId,
            $dailyAud,
            $min    ?? 0.0,
            $max    ?? 1000.0,
            $maxVariancePct ?? 25.0,
            $scalingEnabled !== null ? (int) $scalingEnabled : 0,
        ]);

        // Build a targeted UPDATE so only explicitly-supplied fields are changed.
        $setClauses = ["daily_budget_aud = ?", "updated_at = datetime('now')"];
        $params     = [$dailyAud];

        if ($min !== null) {
            $setClauses[] = 'min_daily_budget_aud = ?';
            $params[]     = $min;
        }
        if ($max !== null) {
            $setClauses[] = 'max_daily_budget_aud = ?';
            $params[]     = $max;
        }
        if ($maxVariancePct !== null) {
            $setClauses[] = 'max_variance_pct = ?';
            $params[]     = $maxVariancePct;
        }
        if ($scalingEnabled !== null) {
            $setClauses[] = 'scaling_enabled = ?';
            $params[]     = (int) $scalingEnabled;
        }

        $params[] = $projectId;

        $db->prepare(
            'UPDATE global_budgets SET ' . implode(', ', $setClauses) . ' WHERE project_id = ?'
        )->execute($params);
    }

    /**
     * Fetch the global_budgets row for a project, or null if none.
     */
    public function get(int $projectId): ?array
    {
        $stmt = DB::get()->prepare('SELECT * FROM global_budgets WHERE project_id = ?');
        $stmt->execute([$projectId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Compute a proportional distribution of the global budget across platforms.
     *
     * If no performance data exists, the budget is split evenly.
     * Otherwise BudgetAllocator::recommendCrossPlatform() scores are used as weights.
     * The max_variance_pct cap prevents any single platform moving more than that
     * percentage per call.
     *
     * @return array  [{platform, current_daily, recommended_daily, change_pct}]
     */
    public function distribute(int $projectId): array
    {
        $db         = DB::get();
        $globalRow  = $this->get($projectId);

        if (!$globalRow) {
            return [];
        }

        $globalTotal   = (float) $globalRow['daily_budget_aud'];
        $maxVariancePct = (float) ($globalRow['max_variance_pct'] ?? 25.0);

        // Current per-platform budgets from the budgets table.
        $budgetStmt = $db->prepare(
            "SELECT platform, daily_budget_aud FROM budgets WHERE project_id = ?"
        );
        $budgetStmt->execute([$projectId]);
        $budgetMap = [];
        foreach ($budgetStmt->fetchAll() as $b) {
            $budgetMap[$b['platform']] = (float) $b['daily_budget_aud'];
        }

        if (empty($budgetMap)) {
            return [];
        }

        $platforms = array_keys($budgetMap);
        $count     = count($platforms);

        // Try to get performance-based weights via the cross-platform recommender.
        $allocator = new BudgetAllocator();
        $crossRecs = $allocator->recommendCrossPlatform($projectId);

        // Build score map from recommendations (eligible platforms only).
        $scoreMap = [];
        foreach ($crossRecs as $rec) {
            if (!($rec['excluded'] ?? false)) {
                // Use recommended_budget as the proportional weight proxy.
                $scoreMap[$rec['platform']] = max(0.0, (float) $rec['recommended_budget']);
            }
        }

        // Determine ideal per-platform share.
        if (array_sum($scoreMap) > 0) {
            // Assign each unscored platform a synthetic score equal to the average
            // of the scored platforms, then distribute proportionally across all.
            $scoredCount  = count($scoreMap);
            $avgScore     = $scoredCount > 0 ? array_sum($scoreMap) / $scoredCount : 0.0;

            $fullScoreMap = [];
            foreach ($platforms as $p) {
                $fullScoreMap[$p] = $scoreMap[$p] ?? $avgScore;
            }

            $totalScore = array_sum($fullScoreMap);
            $idealMap   = [];
            foreach ($platforms as $p) {
                $idealMap[$p] = $totalScore > 0
                    ? ($fullScoreMap[$p] / $totalScore) * $globalTotal
                    : $globalTotal / $count;
            }
        } else {
            // No performance data — distribute evenly.
            $even     = $count > 0 ? $globalTotal / $count : 0.0;
            $idealMap = array_fill_keys($platforms, $even);
        }

        // Apply max_variance_pct cap and build result.
        $result = [];
        foreach ($platforms as $platform) {
            $current = $budgetMap[$platform];
            $ideal   = $idealMap[$platform];

            if ($current > 0) {
                $rawChangePct = (($ideal - $current) / $current) * 100.0;
                $cappedPct    = max(-$maxVariancePct, min($maxVariancePct, $rawChangePct));
                $recommended  = round($current * (1.0 + $cappedPct / 100.0), 2);
            } else {
                $recommended = round($ideal, 2);
            }

            $changePct = $current > 0 ? round((($recommended - $current) / $current) * 100.0, 2) : 0.0;

            $result[] = [
                'platform'         => $platform,
                'current_daily'    => round($current, 2),
                'recommended_daily' => $recommended,
                'change_pct'       => $changePct,
            ];
        }

        return $result;
    }

    /**
     * Execute a distribution plan: write to the budgets table, attempt platform API
     * updates for campaigns with external_id, and log to budget_adjustments.
     *
     * @param  array $distribution  Output from distribute()
     * @return array{updated: string[], errors: string[]}
     */
    public function executeDistribution(int $projectId, array $distribution): array
    {
        $db      = DB::get();
        $updated = [];
        $errors  = [];

        foreach ($distribution as $d) {
            $platform    = $d['platform'];
            $recommended = (float) $d['recommended_daily'];

            // 1. Update the budgets table.
            $db->prepare(
                "INSERT INTO budgets (project_id, platform, daily_budget_aud, updated_at)
                 VALUES (?, ?, ?, datetime('now'))
                 ON CONFLICT(project_id, platform) DO UPDATE
                 SET daily_budget_aud = excluded.daily_budget_aud,
                     updated_at = excluded.updated_at"
            )->execute([$projectId, $platform, $recommended]);

            // 2. Attempt platform API updates for campaigns with external_id.
            $extStmt = $db->prepare(
                "SELECT id, external_id FROM campaigns
                 WHERE project_id = ? AND platform = ? AND external_id IS NOT NULL
                   AND status IN ('enabled', 'active')"
            );
            $extStmt->execute([$projectId, $platform]);
            $campaigns = $extStmt->fetchAll();

            foreach ($campaigns as $campaign) {
                $campaignId = (int) $campaign['id'];
                $externalId = $campaign['external_id'];

                try {
                    $this->dispatchPlatformBudgetUpdate($platform, $externalId, $recommended);
                } catch (\Throwable $e) {
                    $errors[] = "Failed to update campaign {$campaignId} ({$platform}/{$externalId}): {$e->getMessage()}";
                }
            }

            $updated[] = $platform;
        }

        // 3. Log to budget_adjustments.
        if (!empty($distribution)) {
            $db->prepare(
                "INSERT INTO budget_adjustments
                     (project_id, trigger_type, detail_json, created_at)
                 VALUES (?, 'distribute', ?, datetime('now'))"
            )->execute([
                $projectId,
                json_encode(['distribution' => $distribution, 'errors' => $errors]),
            ]);
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    /**
     * Dispatch a budget update to the appropriate platform API.
     * Throws on API failure; caller catches and records errors as non-fatal.
     */
    private function dispatchPlatformBudgetUpdate(string $platform, string $externalId, float $dailyAud): void
    {
        switch ($platform) {
            case 'google':
                $google = new \AdManager\Google\Campaign\Search();
                $google->updateBudget($externalId, $dailyAud * 1_000_000);
                break;

            case 'meta':
                \AdManager\Meta\Client::get()->post($externalId, [
                    'daily_budget' => (int) round($dailyAud * 100),
                ]);
                break;

            case 'tiktok':
                \AdManager\Http\Client::post("/campaign/update/", [
                    'campaign_id'  => $externalId,
                    'daily_budget' => (int) round($dailyAud * 100), // cents
                ]);
                break;

            case 'x':
                \AdManager\Http\Client::put("/campaigns/{$externalId}", [
                    'daily_budget_amount_local_micro' => (int) round($dailyAud * 1_000_000),
                ]);
                break;

            case 'linkedin':
                \AdManager\Http\Client::post("/adCampaigns/{$externalId}", [
                    'dailyBudget' => [
                        'amount'       => (string) round($dailyAud, 2),
                        'currencyCode' => 'AUD',
                    ],
                ]);
                break;

            case 'exoclick':
                \AdManager\Http\Client::put("/campaigns/{$externalId}", [
                    'daily_budget' => (int) round($dailyAud * 100), // cents
                ]);
                break;

            case 'adsterra':
                \AdManager\Http\Client::patch("/advertising/campaigns/{$externalId}", [
                    'daily_budget' => (int) round($dailyAud * 100), // cents
                ]);
                break;

            case 'banner':
                // No API call — DB-only update (already done above).
                break;

            default:
                // Unknown platform — skip silently.
                break;
        }
    }
}
