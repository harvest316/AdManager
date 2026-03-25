<?php

namespace AdManager\Optimise;

use AdManager\DB;
use RuntimeException;

class KeywordMiner
{
    private float $highCtrThreshold = 3.0;   // % — terms with CTR above this are candidates
    private float $lowCtrThreshold  = 0.5;   // % — terms with CTR below this are negative candidates
    private float $highSpendFactor  = 2.0;   // cost above 2x average with no conversions = negative candidate
    private int   $minImpressions   = 10;    // ignore terms with fewer impressions

    /**
     * Mine search terms for keyword and negative keyword candidates.
     *
     * @return array{add_keywords: array, add_negatives: array}
     */
    public function mineSearchTerms(int $projectId): array
    {
        $db = DB::get();

        // Get search term performance data from the performance table
        // We look at ad-level performance and join to get keyword context
        $stmt = $db->prepare(
            'SELECT
                p.ad_id,
                p.ad_group_id,
                p.campaign_id,
                SUM(p.impressions) AS impressions,
                SUM(p.clicks) AS clicks,
                SUM(p.cost_micros) AS cost_micros,
                SUM(p.conversions) AS conversions,
                SUM(p.conversion_value) AS conversion_value
             FROM performance p
             JOIN campaigns c ON c.id = p.campaign_id
             WHERE c.project_id = ?
             GROUP BY p.ad_group_id
             HAVING SUM(p.impressions) >= ?'
        );
        $stmt->execute([$projectId, $this->minImpressions]);
        $termData = $stmt->fetchAll();

        // Get existing keywords for comparison
        $kwStmt = $db->prepare(
            'SELECT k.keyword, k.match_type, k.is_negative, k.ad_group_id
             FROM keywords k
             JOIN campaigns c ON c.id = k.campaign_id
             WHERE c.project_id = ?'
        );
        $kwStmt->execute([$projectId]);
        $existingKeywords = $kwStmt->fetchAll();

        $existingTerms = array_map(
            fn($kw) => strtolower($kw['keyword']),
            $existingKeywords
        );

        // Calculate average cost per ad group
        $totalCost = array_sum(array_column($termData, 'cost_micros'));
        $avgCostPerGroup = count($termData) > 0 ? $totalCost / count($termData) : 0;

        $addKeywords = [];
        $addNegatives = [];

        foreach ($termData as $term) {
            $impressions = (int) $term['impressions'];
            $clicks = (int) $term['clicks'];
            $costMicros = (int) $term['cost_micros'];
            $conversions = (float) $term['conversions'];
            $cost = $costMicros / 1_000_000;
            $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;

            // High-CTR terms with conversions — potential positive keywords
            if ($ctr >= $this->highCtrThreshold && $conversions > 0) {
                $addKeywords[] = [
                    'ad_group_id' => (int) $term['ad_group_id'],
                    'campaign_id' => (int) $term['campaign_id'],
                    'impressions' => $impressions,
                    'clicks'      => $clicks,
                    'ctr'         => round($ctr, 2),
                    'conversions' => $conversions,
                    'cost'        => round($cost, 2),
                    'reason'      => 'High CTR with conversions',
                ];
            }

            // Low-CTR + high spend + no conversions — negative candidates
            if ($ctr < $this->lowCtrThreshold
                && $costMicros > $avgCostPerGroup * $this->highSpendFactor
                && $conversions == 0
            ) {
                $addNegatives[] = [
                    'ad_group_id' => (int) $term['ad_group_id'],
                    'campaign_id' => (int) $term['campaign_id'],
                    'impressions' => $impressions,
                    'clicks'      => $clicks,
                    'ctr'         => round($ctr, 2),
                    'cost'        => round($cost, 2),
                    'reason'      => 'Low CTR, high spend, zero conversions',
                ];
            }
        }

        return [
            'add_keywords'  => $addKeywords,
            'add_negatives' => $addNegatives,
            'total_terms'   => count($termData),
        ];
    }

    /**
     * Generate keyword suggestions using Claude.
     */
    public function suggest(int $projectId): string
    {
        $mined = $this->mineSearchTerms($projectId);
        $db = DB::get();

        $projStmt = $db->prepare('SELECT * FROM projects WHERE id = ?');
        $projStmt->execute([$projectId]);
        $project = $projStmt->fetch();

        if (!$project) {
            throw new RuntimeException("Project #{$projectId} not found.");
        }

        // Get existing keywords
        $kwStmt = $db->prepare(
            'SELECT k.keyword, k.match_type, k.is_negative
             FROM keywords k
             JOIN campaigns c ON c.id = k.campaign_id
             WHERE c.project_id = ?'
        );
        $kwStmt->execute([$projectId]);
        $existingKeywords = $kwStmt->fetchAll();

        $prompt = <<<PROMPT
You are a Google Ads keyword optimisation expert.

**Project:** {$project['display_name']} ({$project['website_url']})

## Current Keywords
{$this->formatKeywords($existingKeywords)}

## Search Term Analysis
Total ad groups analysed: {$mined['total_terms']}
High-performing ad groups (keyword candidates): {$this->formatJson($mined['add_keywords'])}
Low-performing ad groups (negative candidates): {$this->formatJson($mined['add_negatives'])}

## Instructions
1. Suggest 10-20 new keywords to add (with match type recommendations)
2. Suggest 5-10 negative keywords to add
3. Recommend match type changes for existing keywords
4. Identify any keyword gaps or opportunities

Format as actionable markdown with specific keywords and reasoning.
PROMPT;

        return $this->runClaude($prompt);
    }

    private function formatKeywords(array $keywords): string
    {
        if (empty($keywords)) {
            return 'No keywords configured yet.';
        }

        $lines = [];
        foreach ($keywords as $kw) {
            $type = $kw['is_negative'] ? 'NEGATIVE' : strtoupper($kw['match_type']);
            $lines[] = "- [{$type}] {$kw['keyword']}";
        }
        return implode("\n", $lines);
    }

    private function formatJson(array $data): string
    {
        if (empty($data)) {
            return 'None found.';
        }
        return json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * Execute Claude CLI and return stdout.
     */
    private function runClaude(string $prompt): string
    {
        $escapedPrompt = escapeshellarg($prompt);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cmd = "claude -p {$escapedPrompt} --output-format text";
        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start Claude CLI process.');
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();
        $timeout = 120;

        while (true) {
            $status = proc_get_status($process);

            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            if ($out) $stdout .= $out;
            if ($err) $stderr .= $err;

            if (!$status['running']) break;

            if ((time() - $startTime) > $timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException("Claude CLI timed out after {$timeout}s.");
            }

            usleep(100_000);
        }

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        if ($out) $stdout .= $out;
        if ($err) $stderr .= $err;

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException("Claude CLI exited with code {$exitCode}. Stderr: {$stderr}");
        }

        return trim($stdout);
    }
}
