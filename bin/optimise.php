#!/usr/bin/env php
<?php

/**
 * Optimisation CLI — analyse performance, run split tests, mine keywords, allocate budget.
 *
 * Usage:
 *   php bin/optimise.php report --project <name> [--days 7]
 *   php bin/optimise.php split-tests --project <name>
 *   php bin/optimise.php keywords --project <name>
 *   php bin/optimise.php budget --project <name>
 *   php bin/optimise.php fatigue --project <name> [--days 30]
 *   php bin/optimise.php copy-refresh --project <name> [--campaign <name>]
 *   php bin/optimise.php full --project <name>   (runs everything)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Optimise\Analyser;
use AdManager\Optimise\SplitTest;
use AdManager\Optimise\KeywordMiner;
use AdManager\Optimise\BudgetAllocator;
use AdManager\Optimise\CreativeFatigue;
use AdManager\Optimise\CopyRefresher;

// ── Arg parsing ──────────────────────────────────────────────────────────────

function parseArgs(array $argv): array
{
    $positional = [];
    $named = [];

    $i = 1;
    while ($i < count($argv)) {
        $arg = $argv[$i];

        if (str_starts_with($arg, '--')) {
            if (str_contains($arg, '=')) {
                [$key, $val] = explode('=', substr($arg, 2), 2);
                $named[$key] = $val;
            } elseif (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                $named[substr($arg, 2)] = $argv[$i + 1];
                $i++;
            } else {
                $named[substr($arg, 2)] = true;
            }
        } else {
            $positional[] = $arg;
        }
        $i++;
    }

    return [$positional, $named];
}

function usage(): void
{
    $script = 'php bin/optimise.php';
    echo <<<USAGE
AdManager Optimisation CLI

Usage:
  {$script} report --project <name> [--days 7]
  {$script} split-tests --project <name>
  {$script} keywords --project <name>
  {$script} budget --project <name>
  {$script} fatigue --project <name> [--days 30]
  {$script} copy-refresh --project <name> [--campaign <name>]
  {$script} full --project <name> [--days 7]   (runs everything)

USAGE;
    exit(1);
}

function resolveProject(string $name): array
{
    $db = DB::get();
    $stmt = $db->prepare('SELECT * FROM projects WHERE name = ?');
    $stmt->execute([$name]);
    $project = $stmt->fetch();

    if (!$project) {
        echo "Error: project '{$name}' not found.\n";
        exit(1);
    }

    return $project;
}

// ── Commands ─────────────────────────────────────────────────────────────────

function cmdReport(array $named): void
{
    $projectName = $named['project'] ?? null;
    if (!$projectName) {
        echo "Error: --project is required.\n";
        usage();
    }

    $project = resolveProject($projectName);
    $days = (int) ($named['days'] ?? 7);

    echo "Generating optimisation report for {$project['display_name']}...\n";
    echo "(This may take up to 2 minutes)\n\n";

    try {
        $analyser = new Analyser();
        $report = $analyser->generateReport($project['id'], $days);
        echo $report . "\n";
    } catch (\Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        exit(1);
    }
}

function cmdSplitTests(array $named): void
{
    $projectName = $named['project'] ?? null;
    if (!$projectName) {
        echo "Error: --project is required.\n";
        usage();
    }

    $project = resolveProject($projectName);
    $splitTest = new SplitTest();

    $activeTests = $splitTest->listActive($project['id']);

    if (empty($activeTests)) {
        echo "No active split tests for '{$projectName}'.\n\n";

        $allTests = $splitTest->listAll($project['id']);
        if (!empty($allTests)) {
            echo "Concluded tests:\n";
            printf("%-4s %-30s %-20s %-10s %-8s %s\n", 'ID', 'Name', 'Ad Group', 'Metric', 'Status', 'Winner');
            echo str_repeat('-', 110) . "\n";

            foreach ($allTests as $t) {
                printf(
                    "%-4d %-30s %-20s %-10s %-8s %s\n",
                    $t['id'],
                    mb_substr($t['name'], 0, 30),
                    mb_substr($t['ad_group_name'], 0, 20),
                    $t['metric'],
                    $t['status'],
                    $t['winner_ad_id'] ?? '-'
                );
            }
        }
        return;
    }

    echo "=== Active Split Tests ===\n\n";

    foreach ($activeTests as $test) {
        $result = $splitTest->evaluate($test['id']);

        echo "Test #{$test['id']}: {$test['name']}\n";
        echo "  Campaign: {$test['campaign_name']} > {$test['ad_group_name']}\n";
        echo "  Metric: {$test['metric']} | Min impressions: {$test['min_impressions']}\n";
        echo "  Status: {$result['status']}";
        if ($result['confidence'] > 0) {
            echo " (confidence: " . round($result['confidence'] * 100, 1) . "%)";
        }
        echo "\n";

        if (!empty($result['variants'])) {
            printf("  %-8s %-12s %-8s %-8s %-10s %s\n", 'Ad ID', 'Impressions', 'Clicks', 'Conv', 'Metric', 'Value');
            echo "  " . str_repeat('-', 70) . "\n";

            foreach ($result['variants'] as $v) {
                $marker = ($result['winner'] === $v['ad_id']) ? ' *WINNER*' : '';
                printf(
                    "  %-8d %-12s %-8d %-8s %-10s %.4f%s\n",
                    $v['ad_id'],
                    number_format($v['impressions']),
                    $v['clicks'],
                    round($v['conversions'], 1),
                    $v['metric'],
                    $v['metric_value'],
                    $marker
                );
            }
        }

        echo "\n";
    }
}

function cmdKeywords(array $named): void
{
    $projectName = $named['project'] ?? null;
    if (!$projectName) {
        echo "Error: --project is required.\n";
        usage();
    }

    $project = resolveProject($projectName);

    echo "Mining search terms for {$project['display_name']}...\n\n";

    $miner = new KeywordMiner();
    $results = $miner->mineSearchTerms($project['id']);

    echo "Total ad groups analysed: {$results['total_terms']}\n\n";

    if (!empty($results['add_keywords'])) {
        echo "=== Keyword Candidates (high CTR + conversions) ===\n";
        printf("%-12s %-12s %-8s %-8s %-8s %s\n", 'Ad Group', 'Impressions', 'Clicks', 'CTR', 'Conv', 'Reason');
        echo str_repeat('-', 80) . "\n";

        foreach ($results['add_keywords'] as $kw) {
            printf(
                "%-12d %-12s %-8d %-7s%% %-8s %s\n",
                $kw['ad_group_id'],
                number_format($kw['impressions']),
                $kw['clicks'],
                $kw['ctr'],
                round($kw['conversions'], 1),
                $kw['reason']
            );
        }
        echo "\n";
    } else {
        echo "No keyword candidates found.\n\n";
    }

    if (!empty($results['add_negatives'])) {
        echo "=== Negative Keyword Candidates (low CTR + high spend) ===\n";
        printf("%-12s %-12s %-8s %-8s %-8s %s\n", 'Ad Group', 'Impressions', 'Clicks', 'CTR', 'Cost', 'Reason');
        echo str_repeat('-', 80) . "\n";

        foreach ($results['add_negatives'] as $neg) {
            printf(
                "%-12d %-12s %-8d %-7s%% $%-7s %s\n",
                $neg['ad_group_id'],
                number_format($neg['impressions']),
                $neg['clicks'],
                $neg['ctr'],
                $neg['cost'],
                $neg['reason']
            );
        }
        echo "\n";
    } else {
        echo "No negative keyword candidates found.\n\n";
    }
}

function cmdBudget(array $named): void
{
    $projectName = $named['project'] ?? null;
    if (!$projectName) {
        echo "Error: --project is required.\n";
        usage();
    }

    $project = resolveProject($projectName);

    echo "Analysing budget allocation for {$project['display_name']}...\n\n";

    $allocator = new BudgetAllocator();
    $recommendations = $allocator->recommend($project['id']);

    if (empty($recommendations)) {
        echo "No budget changes recommended (need 2+ active campaigns with performance data).\n";
        return;
    }

    echo "=== Budget Recommendations ===\n\n";
    printf(
        "%-4s %-30s %-10s %-12s %-12s %-8s %s\n",
        'ID', 'Campaign', 'Platform', 'Current', 'Recommended', 'Change', 'Reason'
    );
    echo str_repeat('-', 120) . "\n";

    foreach ($recommendations as $r) {
        $changeSign = $r['change'] >= 0 ? '+' : '';
        printf(
            "%-4d %-30s %-10s $%-11s $%-11s %s%-7s %s\n",
            $r['campaign_id'],
            mb_substr($r['campaign_name'], 0, 30),
            $r['platform'],
            number_format($r['current_budget'], 2),
            number_format($r['recommended_budget'], 2),
            $changeSign,
            round($r['change_pct'], 0) . '%',
            mb_substr($r['reason'], 0, 60)
        );
    }

    echo "\n";
}

function cmdFatigue(array $named): void
{
    $projectName = $named['project'] ?? null;
    if (!$projectName) {
        echo "Error: --project is required.\n";
        usage();
    }

    $project = resolveProject($projectName);
    $days = (int) ($named['days'] ?? 30);

    echo "Checking creative fatigue for {$project['display_name']} (last {$days} days)...\n\n";

    $fatigue = new CreativeFatigue();
    $results = $fatigue->detect($project['id'], $days);

    if (empty($results)) {
        echo "No creative fatigue detected. All ads are performing consistently.\n";
        return;
    }

    echo "=== Creative Fatigue Alerts ===\n\n";

    foreach ($results as $f) {
        $severityLabel = strtoupper($f['severity']);
        echo "[{$severityLabel}] Ad #{$f['ad_id']}\n";
        echo "  Current CTR: {$f['current_ctr']}%\n";
        echo "  Trend slope: {$f['trend_slope']}% per day\n";
        echo "  Days declining: {$f['days_declining']}\n";
        echo "  Data points: {$f['data_points']}\n";
        echo "  Recommendation: {$f['recommendation']}\n\n";
    }

    echo "Total fatigued ads: " . count($results) . "\n";
}

function cmdCopyRefresh(array $named): void
{
    $projectName = $named['project'] ?? null;
    if (!$projectName) {
        echo "Error: --project is required.\n";
        usage();
    }

    $project = resolveProject($projectName);
    $refresher = new CopyRefresher();

    $options = [
        'market' => $named['market'] ?? 'all',
    ];
    if (isset($named['max'])) $options['max_replacements'] = (int) $named['max'];
    if (isset($named['strategy'])) $options['strategy_id'] = (int) $named['strategy'];

    if (isset($named['campaign'])) {
        echo "Refreshing copy for campaign: {$named['campaign']}\n";
        $result = $refresher->refresh($project['id'], $named['campaign'], $options);
        printCopyRefreshResult($named['campaign'], $result);
    } else {
        echo "Refreshing copy for all campaigns...\n\n";
        $results = $refresher->refreshAll($project['id'], $options);

        if (empty($results)) {
            echo "No campaigns with approved copy found.\n";
            return;
        }

        $totalWeak = 0;
        $totalGenerated = 0;
        $totalApproved = 0;

        foreach ($results as $campaignName => $result) {
            printCopyRefreshResult($campaignName, $result);
            $totalWeak += $result['weak_found'];
            $totalGenerated += $result['generated'];
            $totalApproved += $result['approved'];
        }

        echo "\n  Total: {$totalWeak} weak → {$totalGenerated} generated → {$totalApproved} approved\n";
    }
}

function printCopyRefreshResult(string $campaignName, array $r): void
{
    if ($r['weak_found'] === 0) {
        echo "  {$campaignName}: no weak headlines found\n";
    } else {
        echo "  {$campaignName}: {$r['weak_found']} weak → {$r['generated']} generated → {$r['approved']} approved, {$r['review']} review\n";
    }
}

function cmdFull(array $named): void
{
    $projectName = $named['project'] ?? null;
    if (!$projectName) {
        echo "Error: --project is required.\n";
        usage();
    }

    $project = resolveProject($projectName);
    $days = (int) ($named['days'] ?? 7);
    $fatigueDays = 30;

    echo "=================================================================\n";
    echo "  Full Optimisation Report: {$project['display_name']}\n";
    echo "  Period: last {$days} days | Fatigue lookback: {$fatigueDays} days\n";
    echo "=================================================================\n\n";

    // 1. Performance analysis
    echo "--- Performance Analysis ---\n\n";
    try {
        $analyser = new Analyser();
        $analysis = $analyser->analyse($project['id'], $days);

        // Performance summary
        echo "Performance (last {$days} days):\n";
        foreach ($analysis['performance'] as $metric => $value) {
            $formatted = is_float($value) ? round($value, 4) : $value;
            echo "  {$metric}: {$formatted}\n";
        }
        echo "\n";

        // Goals
        if (!empty($analysis['goals'])) {
            echo "Goal Status:\n";
            foreach ($analysis['goals'] as $g) {
                $statusIcon = match ($g['status']) {
                    'on_track' => '[OK]',
                    'behind'   => '[!!]',
                    'critical' => '[XX]',
                    default    => '[??]',
                };
                $actual = $g['actual'] !== null ? round($g['actual'], 2) : 'N/A';
                echo "  {$statusIcon} {$g['metric']}: {$actual} / {$g['target']} ({$g['status']})\n";
            }
            echo "\n";
        }

        // Alerts
        if (!empty($analysis['alerts'])) {
            echo "Alerts:\n";
            foreach ($analysis['alerts'] as $alert) {
                echo "  ! {$alert}\n";
            }
            echo "\n";
        }

        // Recommendations
        if (!empty($analysis['recommendations'])) {
            echo "Recommendations:\n";
            foreach ($analysis['recommendations'] as $rec) {
                echo "  > {$rec}\n";
            }
            echo "\n";
        }
    } catch (\Exception $e) {
        echo "Error in analysis: {$e->getMessage()}\n\n";
    }

    // 2. Split tests
    echo "--- Split Tests ---\n\n";
    cmdSplitTests($named);

    // 3. Keyword mining
    echo "--- Keyword Mining ---\n\n";
    cmdKeywords($named);

    // 4. Budget allocation
    echo "--- Budget Allocation ---\n\n";
    cmdBudget($named);

    // 5. Creative fatigue
    echo "--- Creative Fatigue ---\n\n";
    $named['days'] = $fatigueDays;
    cmdFatigue($named);

    // 6. Copy refresh (replace weak headlines)
    echo "\n--- Copy Refresh ---\n\n";
    cmdCopyRefresh($named);

    echo "\n=================================================================\n";
    echo "  Report complete. Run 'php bin/optimise.php report' for AI analysis.\n";
    echo "=================================================================\n";
}

// ── Main ─────────────────────────────────────────────────────────────────────

DB::init();

[$positional, $named] = parseArgs($argv);

if (empty($positional)) {
    usage();
}

$command = array_shift($positional);

match ($command) {
    'report'       => cmdReport($named),
    'split-tests'  => cmdSplitTests($named),
    'keywords'     => cmdKeywords($named),
    'budget'       => cmdBudget($named),
    'fatigue'      => cmdFatigue($named),
    'copy-refresh' => cmdCopyRefresh($named),
    'full'         => cmdFull($named),
    default        => usage(),
};
