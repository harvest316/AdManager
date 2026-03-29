<?php

namespace AdManager\Optimise;

use AdManager\DB;
use RuntimeException;

class KeywordMiner
{
    private float $highCtrThreshold  = 3.0;   // % — terms with CTR above this are keyword candidates
    private float $lowCtrThreshold   = 1.0;   // % — terms with CTR below this are negative candidates
    private float $negativeCtrCap    = 0.5;   // % — suggestNegatives() hard cap
    private int   $minImpressions    = 10;    // ignore terms with fewer impressions
    private int   $negativeMinImpr   = 100;   // suggestNegatives() minimum impressions
    private float $highSpendThreshold = 5.0;  // USD — suggestNegatives() spend threshold

    /**
     * Mine search terms for keyword and negative keyword candidates.
     *
     * Queries the search_terms table (populated by bin/sync-search-terms.php).
     *
     * Three candidate types are returned:
     *   - add_keywords:   high-CTR terms (>3%) with sufficient impressions
     *   - add_negatives:  low-CTR terms (<1%) with sufficient impressions
     *   - expansion:      terms with conversions > 0 not already in the keywords table
     *
     * @return array{add_keywords: array, add_negatives: array, expansion: array, total_terms: int}
     */
    public function mineSearchTerms(int $projectId): array
    {
        $db = DB::get();

        // Fetch search term rows with sufficient impressions for this project
        $stmt = $db->prepare(
            'SELECT
                st.search_term,
                st.match_type,
                st.ad_group_id,
                st.campaign_id,
                SUM(st.impressions)       AS impressions,
                SUM(st.clicks)            AS clicks,
                SUM(st.cost_micros)       AS cost_micros,
                SUM(st.conversions)       AS conversions,
                SUM(st.conversion_value)  AS conversion_value
             FROM search_terms st
             WHERE st.project_id = ?
             GROUP BY st.ad_group_id, st.search_term
             HAVING impressions >= ?'
        );
        $stmt->execute([$projectId, $this->minImpressions]);
        $termData = $stmt->fetchAll();

        // Load existing (non-negative) keyword texts for expansion comparison
        $kwStmt = $db->prepare(
            'SELECT k.keyword
             FROM keywords k
             JOIN campaigns c ON c.id = k.campaign_id
             WHERE c.project_id = ? AND k.is_negative = 0'
        );
        $kwStmt->execute([$projectId]);
        $existingTerms = array_map(
            fn($kw) => strtolower($kw['keyword']),
            $kwStmt->fetchAll()
        );

        $addKeywords  = [];
        $addNegatives = [];
        $expansion    = [];

        foreach ($termData as $term) {
            $impressions = (int)   $term['impressions'];
            $clicks      = (int)   $term['clicks'];
            $costMicros  = (int)   $term['cost_micros'];
            $conversions = (float) $term['conversions'];
            $cost        = $costMicros / 1_000_000;
            $ctr         = $impressions > 0 ? ($clicks / $impressions) * 100 : 0.0;
            $searchText  = $term['search_term'];

            $base = [
                'search_term' => $searchText,
                'match_type'  => $term['match_type'],
                'ad_group_id' => (int) $term['ad_group_id'],
                'campaign_id' => (int) $term['campaign_id'],
                'impressions' => $impressions,
                'clicks'      => $clicks,
                'ctr'         => round($ctr, 2),
                'conversions' => $conversions,
                'cost'        => round($cost, 2),
            ];

            // High-CTR terms — positive keyword candidates
            if ($ctr >= $this->highCtrThreshold) {
                $addKeywords[] = array_merge($base, [
                    'recommendation' => 'add_keyword',
                    'reason'         => 'High CTR with conversions',
                ]);
            }

            // Low-CTR terms — negative keyword candidates
            if ($ctr < $this->lowCtrThreshold) {
                $addNegatives[] = array_merge($base, [
                    'recommendation' => 'add_negative',
                    'reason'         => 'Low CTR — likely irrelevant traffic',
                ]);
            }

            // Terms with conversions not already in keywords table — expansion candidates
            if ($conversions > 0 && !in_array(strtolower($searchText), $existingTerms, true)) {
                $expansion[] = array_merge($base, [
                    'recommendation' => 'expand_keyword',
                    'reason'         => 'Converting search term not yet in keyword list',
                ]);
            }
        }

        return [
            'add_keywords'  => $addKeywords,
            'add_negatives' => $addNegatives,
            'expansion'     => $expansion,
            'total_terms'   => count($termData),
        ];
    }

    /**
     * Find search terms that are strong negative keyword candidates based on
     * spend efficiency: high spend with zero conversions, or very low CTR at scale.
     *
     * @return array  Each entry has search_term, metrics, and reason.
     */
    public function suggestNegatives(int $projectId): array
    {
        $db = DB::get();

        $stmt = $db->prepare(
            'SELECT
                st.search_term,
                st.match_type,
                st.ad_group_id,
                st.campaign_id,
                SUM(st.impressions)  AS impressions,
                SUM(st.clicks)       AS clicks,
                SUM(st.cost_micros)  AS cost_micros,
                SUM(st.conversions)  AS conversions
             FROM search_terms st
             WHERE st.project_id = ?
             GROUP BY st.ad_group_id, st.search_term'
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll();

        $negatives = [];

        foreach ($rows as $row) {
            $impressions = (int)   $row['impressions'];
            $clicks      = (int)   $row['clicks'];
            $costMicros  = (int)   $row['cost_micros'];
            $conversions = (float) $row['conversions'];
            $cost        = $costMicros / 1_000_000;
            $ctr         = $impressions > 0 ? ($clicks / $impressions) * 100 : 0.0;

            $reasons = [];

            // High spend, zero conversions
            if ($cost >= $this->highSpendThreshold && $conversions == 0) {
                $reasons[] = sprintf('High spend ($%.2f) with zero conversions', $cost);
            }

            // Very low CTR at meaningful scale
            if ($ctr < $this->negativeCtrCap && $impressions >= $this->negativeMinImpr) {
                $reasons[] = sprintf('Very low CTR (%.2f%%) with %d impressions', $ctr, $impressions);
            }

            if (empty($reasons)) {
                continue;
            }

            $negatives[] = [
                'search_term'    => $row['search_term'],
                'match_type'     => $row['match_type'],
                'ad_group_id'    => (int) $row['ad_group_id'],
                'campaign_id'    => (int) $row['campaign_id'],
                'impressions'    => $impressions,
                'clicks'         => $clicks,
                'ctr'            => round($ctr, 2),
                'cost'           => round($cost, 2),
                'conversions'    => $conversions,
                'reasons'        => $reasons,
                'recommendation' => 'add_negative',
            ];
        }

        return $negatives;
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
Total search terms analysed: {$mined['total_terms']}
High-CTR terms (keyword candidates): {$this->formatJson($mined['add_keywords'])}
Low-CTR terms (negative candidates): {$this->formatJson($mined['add_negatives'])}
Converting terms not yet in keyword list (expansion): {$this->formatJson($mined['expansion'])}

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
