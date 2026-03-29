<?php

namespace AdManager\Optimise;

use AdManager\DB;
use RuntimeException;

class Analyser
{
    /**
     * Analyse project performance against goals.
     *
     * @return array{goals: array, recommendations: array, alerts: array}
     */
    public function analyse(int $projectId, int $days = 7): array
    {
        $db = DB::get();

        // Get project
        $projStmt = $db->prepare('SELECT * FROM projects WHERE id = ?');
        $projStmt->execute([$projectId]);
        $project = $projStmt->fetch();

        if (!$project) {
            throw new RuntimeException("Project #{$projectId} not found.");
        }

        // Get goals
        $goalsStmt = $db->prepare('SELECT * FROM goals WHERE project_id = ?');
        $goalsStmt->execute([$projectId]);
        $goals = $goalsStmt->fetchAll();

        // Get aggregated performance for the period
        $perfStmt = $db->prepare(
            'SELECT
                SUM(p.impressions) AS impressions,
                SUM(p.clicks) AS clicks,
                SUM(p.cost_micros) AS cost_micros,
                SUM(p.conversions) AS conversions,
                SUM(p.conversion_value) AS conversion_value,
                COUNT(DISTINCT p.date) AS days_with_data
             FROM performance p
             JOIN campaigns c ON c.id = p.campaign_id
             WHERE c.project_id = ?
               AND p.date >= date(\'now\', ? || \' days\')'
        );
        $perfStmt->execute([$projectId, "-{$days}"]);
        $perf = $perfStmt->fetch();

        $impressions = (int) ($perf['impressions'] ?? 0);
        $clicks = (int) ($perf['clicks'] ?? 0);
        $costMicros = (int) ($perf['cost_micros'] ?? 0);
        $conversions = (float) ($perf['conversions'] ?? 0);
        $conversionValue = (float) ($perf['conversion_value'] ?? 0);
        $cost = $costMicros / 1_000_000;

        // Calculate actual metrics
        $actualMetrics = [
            'impressions'     => $impressions,
            'clicks'          => $clicks,
            'ctr'             => $impressions > 0 ? ($clicks / $impressions) * 100 : 0,
            'cost'            => $cost,
            'conversions'     => $conversions,
            'conversion_rate' => $clicks > 0 ? ($conversions / $clicks) * 100 : 0,
            'cpa'             => $conversions > 0 ? $cost / $conversions : 0,
            'roas'            => $cost > 0 ? $conversionValue / $cost : 0,
            'conversion_value' => $conversionValue,
        ];

        // Compare to goals
        $goalStatuses = [];
        $alerts = [];
        $recommendations = [];

        foreach ($goals as $goal) {
            $metric = $goal['metric'];
            $target = (float) $goal['target_value'];
            $actual = $actualMetrics[$metric] ?? null;

            if ($actual === null) {
                $goalStatuses[] = [
                    'metric'  => $metric,
                    'target'  => $target,
                    'actual'  => null,
                    'status'  => 'unknown',
                    'delta'   => null,
                    'pct_off' => null,
                ];
                continue;
            }

            // For CPA, lower is better; for everything else, higher is better
            $lowerIsBetter = in_array($metric, ['cpa'], true);
            $onTrack = $lowerIsBetter ? ($actual <= $target) : ($actual >= $target);

            $delta = $actual - $target;
            $pctOff = $target != 0 ? (($actual - $target) / $target) * 100 : 0;

            $status = 'on_track';
            if (!$onTrack) {
                $status = abs($pctOff) > 25 ? 'critical' : 'behind';
            }

            $goalStatuses[] = [
                'metric'  => $metric,
                'target'  => $target,
                'actual'  => round($actual, 4),
                'status'  => $status,
                'delta'   => round($delta, 4),
                'pct_off' => round($pctOff, 1),
            ];

            if ($status === 'critical') {
                $alerts[] = "CRITICAL: {$metric} is " . abs(round($pctOff, 1)) . "% "
                    . ($lowerIsBetter ? 'above' : 'below')
                    . " target (actual: " . round($actual, 2) . ", target: {$target})";
            }

            if ($status === 'behind') {
                $recommendations[] = "Optimise for {$metric}: currently " . round($actual, 2)
                    . " vs target {$target} (" . abs(round($pctOff, 1)) . "% off)";
            }
        }

        // Auto-generate general recommendations
        if ($impressions > 0 && $clicks === 0) {
            $alerts[] = 'ALERT: Zero clicks despite impressions — check ad relevance and quality score.';
        }

        if ($impressions === 0) {
            $alerts[] = 'ALERT: Zero impressions — check campaign status, budget, and targeting.';
        }

        if ($actualMetrics['ctr'] > 0 && $actualMetrics['ctr'] < 1.0) {
            $recommendations[] = 'CTR is below 1% — consider testing new ad copy and tightening keyword targeting.';
        }

        // Update current_value in goals table
        $updateStmt = $db->prepare(
            'UPDATE goals SET current_value = ?, last_checked = datetime(\'now\') WHERE id = ?'
        );
        foreach ($goalStatuses as $i => $gs) {
            if ($gs['actual'] !== null && isset($goals[$i])) {
                $updateStmt->execute([$gs['actual'], $goals[$i]['id']]);
            }
        }

        return [
            'project'         => $project['name'],
            'period_days'     => $days,
            'performance'     => $actualMetrics,
            'goals'           => $goalStatuses,
            'recommendations' => $recommendations,
            'alerts'          => $alerts,
        ];
    }

    /**
     * Enrich per-campaign analysis with GA4 bounce rate data.
     *
     * Joins the performance table (campaign-level) with ga4_performance on
     * campaign_name + date for the last 14 days.
     *
     * For campaigns where CPA > 2× the project target AND landing-page bounce rate
     * > 70%, the recommendation is overridden to "fix landing page" instead of the
     * default "add negatives".
     *
     * @return array  Per-campaign rows with added ga4_bounce_rate and recommendation keys.
     */
    public function enrichWithGA4(int $projectId): array
    {
        $db = DB::get();

        // Get project target CPA (if defined)
        $targetStmt = $db->prepare(
            "SELECT target_value FROM goals WHERE project_id = ? AND metric = 'cpa' LIMIT 1"
        );
        $targetStmt->execute([$projectId]);
        $targetCpa = (float) ($targetStmt->fetch()['target_value'] ?? 0);

        // Campaign-level performance for last 14 days
        $perfStmt = $db->prepare(
            "SELECT
                c.id,
                c.name,
                c.platform,
                SUM(p.clicks) AS clicks,
                SUM(p.cost_micros) AS cost_micros,
                SUM(p.conversions) AS conversions
             FROM performance p
             JOIN campaigns c ON c.id = p.campaign_id
             WHERE c.project_id = ?
               AND p.date >= date('now', '-14 days')
             GROUP BY c.id"
        );
        $perfStmt->execute([$projectId]);
        $campaigns = $perfStmt->fetchAll();

        if (empty($campaigns)) {
            return [];
        }

        // GA4 bounce rate aggregated by campaign_name for last 14 days
        // (average weighted by sessions)
        $ga4Stmt = $db->prepare(
            "SELECT
                campaign_name,
                SUM(sessions * bounce_rate) / NULLIF(SUM(sessions), 0) AS weighted_bounce_rate
             FROM ga4_performance
             WHERE project_id = ?
               AND date >= date('now', '-14 days')
               AND bounce_rate IS NOT NULL
             GROUP BY campaign_name"
        );
        $ga4Stmt->execute([$projectId]);
        $ga4Map = [];
        foreach ($ga4Stmt->fetchAll() as $g) {
            $ga4Map[$g['campaign_name']] = (float) $g['weighted_bounce_rate'];
        }

        $result = [];
        foreach ($campaigns as $c) {
            $costMicros  = (int) ($c['cost_micros'] ?? 0);
            $cost        = $costMicros / 1_000_000;
            $conversions = (float) ($c['conversions'] ?? 0);
            $cpa         = $conversions > 0 ? $cost / $conversions : null;

            $bounceRate    = $ga4Map[$c['name']] ?? null;
            $recommendation = null;

            if ($cpa !== null && $targetCpa > 0 && $cpa > ($targetCpa * 2.0)) {
                // CPA is more than 2× target
                if ($bounceRate !== null && $bounceRate > 70.0) {
                    $recommendation = 'fix landing page';
                } else {
                    $recommendation = 'add negatives';
                }
            }

            $result[] = [
                'campaign_id'      => (int) $c['id'],
                'campaign_name'    => $c['name'],
                'platform'         => $c['platform'],
                'cost'             => round($cost, 2),
                'conversions'      => $conversions,
                'cpa'              => $cpa !== null ? round($cpa, 2) : null,
                'target_cpa'       => $targetCpa > 0 ? $targetCpa : null,
                'ga4_bounce_rate'  => $bounceRate !== null ? round($bounceRate, 2) : null,
                'recommendation'   => $recommendation,
            ];
        }

        return $result;
    }

    /**
     * Generate a full optimisation report using Claude.
     */
    public function generateReport(int $projectId, int $days = 7): string
    {
        $analysis = $this->analyse($projectId, $days);
        $db = DB::get();

        // Get project
        $projStmt = $db->prepare('SELECT * FROM projects WHERE id = ?');
        $projStmt->execute([$projectId]);
        $project = $projStmt->fetch();

        // Get split test results
        $splitTest = new SplitTest();
        $activeTests = $splitTest->listActive($projectId);
        $splitResults = [];
        foreach ($activeTests as $test) {
            $splitResults[] = $splitTest->evaluate($test['id']);
        }

        // Get keyword mining
        $keywordMiner = new KeywordMiner();
        $keywordData = $keywordMiner->mineSearchTerms($projectId);

        // Get creative fatigue
        $fatigue = new CreativeFatigue();
        $fatigueAlerts = $fatigue->detect($projectId);

        // Get budget data
        $budgetAllocator = new BudgetAllocator();
        $budgetData = $budgetAllocator->recommend($projectId);

        // Build prompt
        $templatePath = dirname(__DIR__, 2) . '/prompts/OPTIMISE.md';
        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Prompt template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);

        // Format goals status
        $goalsStatusText = '';
        foreach ($analysis['goals'] as $g) {
            $goalsStatusText .= sprintf(
                "- %s: target=%.2f, actual=%s, status=%s\n",
                $g['metric'],
                $g['target'],
                $g['actual'] !== null ? round($g['actual'], 2) : 'N/A',
                $g['status']
            );
        }

        // Format performance data
        $perfText = '';
        foreach ($analysis['performance'] as $metric => $value) {
            $perfText .= "- {$metric}: " . (is_float($value) ? round($value, 4) : $value) . "\n";
        }

        // Format split test results
        $splitText = empty($splitResults) ? 'No active split tests.' : json_encode($splitResults, JSON_PRETTY_PRINT);

        // Format keyword mining
        $keywordText = json_encode($keywordData, JSON_PRETTY_PRINT);

        // Format fatigue alerts
        $fatigueText = empty($fatigueAlerts) ? 'No creative fatigue detected.' : json_encode($fatigueAlerts, JSON_PRETTY_PRINT);

        // Format budget data
        $budgetText = empty($budgetData) ? 'No budget recommendations.' : json_encode($budgetData, JSON_PRETTY_PRINT);

        $replacements = [
            '{{PROJECT_NAME}}'       => $project['display_name'] ?: $project['name'],
            '{{DAYS}}'               => (string) $days,
            '{{PLATFORM}}'           => 'All',
            '{{GOALS_STATUS}}'       => trim($goalsStatusText) ?: 'No goals defined.',
            '{{PERFORMANCE_DATA}}'   => trim($perfText),
            '{{SPLIT_TEST_RESULTS}}' => $splitText,
            '{{KEYWORD_MINING}}'     => $keywordText,
            '{{FATIGUE_ALERTS}}'     => $fatigueText,
            '{{BUDGET_DATA}}'        => $budgetText,
            '{{DATE}}'               => date('Y-m-d'),
        ];

        $prompt = str_replace(array_keys($replacements), array_values($replacements), $template);

        // Shell to Claude
        $report = $this->runClaude($prompt);

        // Save to file
        $dir = dirname(__DIR__, 2) . '/reports';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = sprintf('optimise-%s.md', date('Y-m-d'));
        file_put_contents("{$dir}/{$filename}", $report);

        return $report;
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

            if (!$status['running']) {
                break;
            }

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
