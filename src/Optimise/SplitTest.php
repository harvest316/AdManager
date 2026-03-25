<?php

namespace AdManager\Optimise;

use AdManager\DB;

class SplitTest
{
    /**
     * Create a new split test.
     *
     * @return int The split test ID
     */
    public function create(
        int $projectId,
        int $campaignId,
        int $adGroupId,
        string $name,
        string $metric = 'ctr',
        int $minImpressions = 1000
    ): int {
        $db = DB::get();
        $stmt = $db->prepare(
            'INSERT INTO split_tests (project_id, campaign_id, ad_group_id, name, metric, min_impressions, started_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime(\'now\'))'
        );
        $stmt->execute([$projectId, $campaignId, $adGroupId, $name, $metric, $minImpressions]);

        return (int) $db->lastInsertId();
    }

    /**
     * Evaluate a running split test.
     *
     * @return array{status: string, variants: array, winner: ?int, confidence: float}
     */
    public function evaluate(int $splitTestId): array
    {
        $db = DB::get();

        // Get the split test
        $testStmt = $db->prepare('SELECT * FROM split_tests WHERE id = ?');
        $testStmt->execute([$splitTestId]);
        $test = $testStmt->fetch();

        if (!$test) {
            return ['status' => 'not_found', 'variants' => [], 'winner' => null, 'confidence' => 0.0];
        }

        if ($test['status'] === 'concluded') {
            return [
                'status'     => 'concluded',
                'variants'   => [],
                'winner'     => (int) $test['winner_ad_id'],
                'confidence' => (float) $test['confidence_level'],
            ];
        }

        // Get all ads in this ad group
        $adsStmt = $db->prepare(
            'SELECT id, status FROM ads WHERE ad_group_id = ? AND status != \'removed\''
        );
        $adsStmt->execute([$test['ad_group_id']]);
        $ads = $adsStmt->fetchAll();

        if (count($ads) < 2) {
            return ['status' => 'insufficient_variants', 'variants' => [], 'winner' => null, 'confidence' => 0.0];
        }

        // Get aggregated performance for each ad
        $perfStmt = $db->prepare(
            'SELECT ad_id,
                    SUM(impressions) AS impressions,
                    SUM(clicks) AS clicks,
                    SUM(conversions) AS conversions,
                    SUM(conversion_value) AS conversion_value,
                    SUM(cost_micros) AS cost_micros
             FROM performance
             WHERE ad_id = ?'
        );

        $variants = [];
        foreach ($ads as $ad) {
            $perfStmt->execute([$ad['id']]);
            $perf = $perfStmt->fetch();

            $impressions = (int) ($perf['impressions'] ?? 0);
            $clicks = (int) ($perf['clicks'] ?? 0);
            $conversions = (float) ($perf['conversions'] ?? 0);
            $conversionValue = (float) ($perf['conversion_value'] ?? 0);
            $costMicros = (int) ($perf['cost_micros'] ?? 0);

            $metricValue = $this->calculateMetric(
                $test['metric'],
                $impressions,
                $clicks,
                $conversions,
                $conversionValue,
                $costMicros
            );

            $variants[] = [
                'ad_id'            => (int) $ad['id'],
                'impressions'      => $impressions,
                'clicks'           => $clicks,
                'conversions'      => $conversions,
                'conversion_value' => $conversionValue,
                'cost'             => round($costMicros / 1_000_000, 2),
                'metric'           => $test['metric'],
                'metric_value'     => $metricValue,
            ];
        }

        // Check minimum impressions
        $minImpressions = (int) $test['min_impressions'];
        $allMeetMin = true;
        foreach ($variants as $v) {
            if ($v['impressions'] < $minImpressions) {
                $allMeetMin = false;
                break;
            }
        }

        if (!$allMeetMin) {
            return [
                'status'     => 'insufficient_data',
                'variants'   => $variants,
                'winner'     => null,
                'confidence' => 0.0,
            ];
        }

        // Find the best and second-best variants
        usort($variants, fn($a, $b) => $b['metric_value'] <=> $a['metric_value']);
        $best = $variants[0];
        $second = $variants[1];

        // Run significance test based on metric type
        $pValue = $this->significanceTest(
            $test['metric'],
            $best,
            $second
        );

        $confidence = 1.0 - $pValue;
        $hasWinner = $confidence >= (float) $test['confidence_level'];

        return [
            'status'     => $hasWinner ? 'winner' : 'running',
            'variants'   => $variants,
            'winner'     => $hasWinner ? $best['ad_id'] : null,
            'confidence' => round($confidence, 4),
        ];
    }

    /**
     * Conclude a split test with a declared winner.
     */
    public function conclude(int $splitTestId, int $winnerAdId): void
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'UPDATE split_tests
             SET winner_ad_id = ?, status = \'concluded\', concluded_at = datetime(\'now\')
             WHERE id = ?'
        );
        $stmt->execute([$winnerAdId, $splitTestId]);
    }

    /**
     * List active (running) split tests for a project.
     */
    public function listActive(int $projectId): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'SELECT st.*, c.name AS campaign_name, ag.name AS ad_group_name
             FROM split_tests st
             JOIN campaigns c ON c.id = st.campaign_id
             JOIN ad_groups ag ON ag.id = st.ad_group_id
             WHERE st.project_id = ? AND st.status = \'running\'
             ORDER BY st.created_at DESC'
        );
        $stmt->execute([$projectId]);

        return $stmt->fetchAll();
    }

    /**
     * List all split tests for a project.
     */
    public function listAll(int $projectId): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'SELECT st.*, c.name AS campaign_name, ag.name AS ad_group_name
             FROM split_tests st
             JOIN campaigns c ON c.id = st.campaign_id
             JOIN ad_groups ag ON ag.id = st.ad_group_id
             WHERE st.project_id = ?
             ORDER BY st.created_at DESC'
        );
        $stmt->execute([$projectId]);

        return $stmt->fetchAll();
    }

    /**
     * Calculate a metric value from raw performance data.
     */
    private function calculateMetric(
        string $metric,
        int $impressions,
        int $clicks,
        float $conversions,
        float $conversionValue,
        int $costMicros
    ): float {
        return match ($metric) {
            'ctr'             => $impressions > 0 ? ($clicks / $impressions) * 100 : 0.0,
            'conversion_rate' => $clicks > 0 ? ($conversions / $clicks) * 100 : 0.0,
            'cpa'             => $conversions > 0 ? ($costMicros / 1_000_000) / $conversions : PHP_FLOAT_MAX,
            'roas'            => $costMicros > 0 ? $conversionValue / ($costMicros / 1_000_000) : 0.0,
            default           => $impressions > 0 ? ($clicks / $impressions) * 100 : 0.0,
        };
    }

    /**
     * Run the appropriate significance test based on metric type.
     */
    private function significanceTest(string $metric, array $best, array $second): float
    {
        return match ($metric) {
            'ctr' => $this->zTest(
                $best['clicks'] / max($best['impressions'], 1),
                $best['impressions'],
                $second['clicks'] / max($second['impressions'], 1),
                $second['impressions']
            ),
            'conversion_rate' => $this->zTest(
                $best['conversions'] / max($best['clicks'], 1),
                $best['clicks'],
                $second['conversions'] / max($second['clicks'], 1),
                $second['clicks']
            ),
            default => $this->zTest(
                $best['clicks'] / max($best['impressions'], 1),
                $best['impressions'],
                $second['clicks'] / max($second['impressions'], 1),
                $second['impressions']
            ),
        };
    }

    /**
     * Two-proportion z-test. Returns p-value.
     *
     * Tests whether two proportions p1 and p2 are significantly different.
     */
    private function zTest(float $p1, int $n1, float $p2, int $n2): float
    {
        if ($n1 === 0 || $n2 === 0) {
            return 1.0;
        }

        // Pooled proportion
        $pPool = ($p1 * $n1 + $p2 * $n2) / ($n1 + $n2);

        if ($pPool <= 0 || $pPool >= 1) {
            return 1.0;
        }

        // Standard error
        $se = sqrt($pPool * (1 - $pPool) * (1 / $n1 + 1 / $n2));

        if ($se == 0) {
            return 1.0;
        }

        // Z-score
        $z = abs($p1 - $p2) / $se;

        // Two-tailed p-value using normal CDF approximation (Abramowitz & Stegun)
        return 2 * $this->normalCdfComplement($z);
    }

    /**
     * Complement of the standard normal CDF: P(Z > z).
     * Uses the rational approximation from Abramowitz & Stegun (equation 26.2.17).
     */
    private function normalCdfComplement(float $z): float
    {
        if ($z < 0) {
            return 1.0 - $this->normalCdfComplement(-$z);
        }

        $b1 = 0.319381530;
        $b2 = -0.356563782;
        $b3 = 1.781477937;
        $b4 = -1.821255978;
        $b5 = 1.330274429;
        $p  = 0.2316419;

        $t = 1.0 / (1.0 + $p * $z);
        $phi = (1.0 / sqrt(2 * M_PI)) * exp(-0.5 * $z * $z);

        return $phi * ($b1 * $t + $b2 * $t ** 2 + $b3 * $t ** 3 + $b4 * $t ** 4 + $b5 * $t ** 5);
    }
}
