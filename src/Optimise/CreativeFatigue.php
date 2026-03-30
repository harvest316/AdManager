<?php

namespace AdManager\Optimise;

use AdManager\DB;

class CreativeFatigue
{
    private float $slopeThreshold = -0.1; // decline of 0.1% CTR per day or worse

    /**
     * Detect ads showing signs of creative fatigue (declining CTR).
     *
     * @return array of ['ad_id', 'current_ctr', 'trend_slope', 'days_declining', 'recommendation']
     */
    public function detect(int $projectId, int $lookbackDays = 30): array
    {
        $db = DB::get();

        // Get all ads for this project with daily performance
        $stmt = $db->prepare(
            'SELECT
                p.ad_id,
                p.date,
                SUM(p.impressions) AS impressions,
                SUM(p.clicks) AS clicks
             FROM performance p
             JOIN campaigns c ON c.id = p.campaign_id
             WHERE c.project_id = ?
               AND p.ad_id IS NOT NULL
               AND p.date >= date(\'now\', ? || \' days\')
             GROUP BY p.ad_id, p.date
             HAVING SUM(p.impressions) > 0
             ORDER BY p.ad_id, p.date'
        );
        $stmt->execute([$projectId, "-{$lookbackDays}"]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return [];
        }

        // Group by ad_id
        $adData = [];
        foreach ($rows as $row) {
            $adId = (int) $row['ad_id'];
            if (!isset($adData[$adId])) {
                $adData[$adId] = [];
            }

            $impressions = (int) $row['impressions'];
            $clicks = (int) $row['clicks'];
            $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;

            $adData[$adId][] = [
                'date' => $row['date'],
                'ctr'  => $ctr,
            ];
        }

        $fatigued = [];

        foreach ($adData as $adId => $dailyCtr) {
            // Need at least 7 data points for meaningful trend
            if (count($dailyCtr) < 7) {
                continue;
            }

            $slope = $this->linearRegressionSlope($dailyCtr);
            $currentCtr = end($dailyCtr)['ctr'];

            // Count consecutive declining days from the end
            $daysDeclining = $this->countDecliningDays($dailyCtr);

            if ($slope <= $this->slopeThreshold) {
                $severity = $slope <= ($this->slopeThreshold * 3) ? 'high' : 'moderate';

                $recommendation = $this->buildRecommendation($slope, $currentCtr, $daysDeclining, $severity);

                $fatigued[] = [
                    'ad_id'          => $adId,
                    'current_ctr'    => round($currentCtr, 2),
                    'trend_slope'    => round($slope, 4),
                    'days_declining' => $daysDeclining,
                    'data_points'    => count($dailyCtr),
                    'severity'       => $severity,
                    'recommendation' => $recommendation,
                ];
            }
        }

        // Sort by severity (most negative slope first)
        usort($fatigued, fn($a, $b) => $a['trend_slope'] <=> $b['trend_slope']);

        // Log each fatigued ad to changelog
        foreach ($fatigued as $f) {
            \AdManager\Dashboard\Changelog::log(
                $projectId, 'creative', 'fatigue_detected',
                "Ad #{$f['ad_id']} flagged for creative fatigue ({$f['severity']}): CTR slope {$f['trend_slope']}%/day over {$f['days_declining']}d. {$f['recommendation']}",
                $f, 'ad', $f['ad_id'], 'optimiser'
            );
        }

        return $fatigued;
    }

    /**
     * Calculate the slope of a simple linear regression on daily CTR values.
     *
     * @param array $dailyCtr Array of ['date' => string, 'ctr' => float]
     * @return float Slope (change in CTR percentage points per day)
     */
    private function linearRegressionSlope(array $dailyCtr): float
    {
        $n = count($dailyCtr);
        if ($n < 2) {
            return 0.0;
        }

        // Use day index as x, CTR as y
        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumXX = 0;

        foreach ($dailyCtr as $i => $point) {
            $x = $i;
            $y = $point['ctr'];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }

        $denominator = ($n * $sumXX - $sumX * $sumX);
        if ($denominator == 0) {
            return 0.0;
        }

        return ($n * $sumXY - $sumX * $sumY) / $denominator;
    }

    /**
     * Count consecutive days of declining CTR from the most recent data.
     */
    private function countDecliningDays(array $dailyCtr): int
    {
        $count = 0;
        $data = array_values($dailyCtr);

        for ($i = count($data) - 1; $i >= 1; $i--) {
            if ($data[$i]['ctr'] < $data[$i - 1]['ctr']) {
                $count++;
            } else {
                break;
            }
        }

        return $count;
    }

    /**
     * Build a recommendation string based on fatigue severity.
     */
    private function buildRecommendation(float $slope, float $currentCtr, int $daysDeclining, string $severity): string
    {
        $parts = [];

        if ($severity === 'high') {
            $parts[] = 'URGENT: This ad shows significant creative fatigue.';
            $parts[] = 'Pause and replace with fresh creative immediately.';
        } else {
            $parts[] = 'This ad is showing early signs of creative fatigue.';
            $parts[] = 'Prepare replacement creative and plan rotation.';
        }

        $parts[] = sprintf(
            'CTR declining at %.2f%% per day over the last %d days.',
            abs($slope),
            $daysDeclining > 0 ? $daysDeclining : 'multiple'
        );

        if ($currentCtr < 1.0) {
            $parts[] = 'Current CTR is already below 1% — immediate action recommended.';
        }

        return implode(' ', $parts);
    }
}
