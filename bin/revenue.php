#!/usr/bin/env php
<?php

/**
 * Revenue CLI — record revenue events and trigger budget scaling.
 *
 * Usage:
 *   php bin/revenue.php record <project> <amount> [--date YYYY-MM-DD] [--source manual|webhook]
 *   php bin/revenue.php import-ga4 <project> [--days 7]
 *   php bin/revenue.php check <project>
 *   php bin/revenue.php scale <project>
 *   php bin/revenue.php scale-all
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Optimise\RevenueScaler;
use AdManager\Optimise\GlobalBudget;

// ── Helpers ───────────────────────────────────────────────────────────────────

function parseArgs(array $argv): array
{
    $positional = [];
    $named      = [];

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
    $s = 'php bin/revenue.php';
    echo <<<USAGE
AdManager Revenue CLI

Usage:
  {$s} record <project> <amount> [--date YYYY-MM-DD] [--source manual|webhook]
  {$s} import-ga4 <project> [--days 7]
  {$s} check <project>       Show baseline, current revenue and proposed scaling
  {$s} scale <project>       Execute revenue-based budget scaling
  {$s} scale-all             Run scaling for all projects with scaling_enabled=1

USAGE;
    exit(1);
}

function resolveProject(string $name): array
{
    $db   = DB::get();
    $stmt = $db->prepare('SELECT * FROM projects WHERE name = ?');
    $stmt->execute([$name]);
    $project = $stmt->fetch();

    if (!$project) {
        echo "Error: project '{$name}' not found.\n";
        exit(1);
    }

    return $project;
}

// ── Commands ──────────────────────────────────────────────────────────────────

function cmdRecord(array $pos, array $named): void
{
    if (count($pos) < 2) {
        echo "Error: record requires <project> <amount>.\n";
        usage();
    }

    [$projectName, $amountRaw] = $pos;
    $amount = (float) $amountRaw;
    $date   = $named['date']   ?? date('Y-m-d');
    $source = $named['source'] ?? 'manual';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo "Error: --date must be YYYY-MM-DD.\n";
        exit(1);
    }

    $project = resolveProject($projectName);
    $scaler  = new RevenueScaler();
    $scaler->recordRevenue((int) $project['id'], $amount, $date, $source);

    printf(
        "Recorded: %s / \$%.2f AUD on %s (source: %s)\n",
        $projectName,
        $amount,
        $date,
        $source
    );
}

function cmdImportGA4(array $pos, array $named): void
{
    if (empty($pos[0])) {
        echo "Error: import-ga4 requires <project>.\n";
        usage();
    }

    $project = resolveProject($pos[0]);
    $days    = (int) ($named['days'] ?? 7);
    $scaler  = new RevenueScaler();

    $inserted = $scaler->importFromGA4((int) $project['id'], $days);

    printf("Imported %d revenue date row(s) from GA4 for '%s' (last %d days).\n",
        $inserted, $pos[0], $days);
}

function cmdCheck(array $pos): void
{
    if (empty($pos[0])) {
        echo "Error: check requires <project>.\n";
        usage();
    }

    $project = resolveProject($pos[0]);
    $scaler  = new RevenueScaler();
    $gb      = new GlobalBudget();

    $gbRow   = $gb->get((int) $project['id']);
    $scaling = $scaler->calculateScaling((int) $project['id']);

    echo "Revenue Scaling Check: {$project['display_name']}\n";
    echo str_repeat('-', 60) . "\n";

    if ($gbRow) {
        printf("  Global budget:    \$%.2f/day AUD\n", $gbRow['daily_budget_aud']);
        printf("  Min / Max:        \$%.2f / \$%.2f\n",
            $gbRow['min_daily_budget_aud'], $gbRow['max_daily_budget_aud']);
        printf("  Max variance:     %.1f%%\n", $gbRow['max_variance_pct']);
        printf("  Scaling enabled:  %s\n", $gbRow['scaling_enabled'] ? 'yes' : 'no');
        echo "\n";
    } else {
        echo "  No global budget configured.\n\n";
    }

    printf("  Revenue baseline (7-day avg): \$%.4f\n", $scaling['revenue_baseline']);
    printf("  Revenue current:              \$%.4f\n", $scaling['revenue_current']);
    printf("  Revenue delta:                %+.2f%%\n", $scaling['revenue_delta_pct']);
    printf("  Proposed budget:              \$%.2f/day AUD\n", $scaling['proposed_global']);
    printf("  Budget delta:                 %+.2f%%%s\n",
        $scaling['budget_delta_pct'],
        $scaling['clamped'] ? ' (clamped)' : '');
    echo "\n  Reason: {$scaling['reason']}\n";
}

function cmdScale(array $pos): void
{
    if (empty($pos[0])) {
        echo "Error: scale requires <project>.\n";
        usage();
    }

    $project = resolveProject($pos[0]);
    $scaler  = new RevenueScaler();
    $result  = $scaler->execute((int) $project['id']);

    echo "Revenue Scaling: {$project['display_name']}\n";
    echo str_repeat('-', 60) . "\n";

    if ($result['action'] === 'no_change') {
        printf("  No change — budget delta %.2f%% is within 2%% threshold.\n",
            $result['budget_delta_pct']);
        echo "  Reason: {$result['reason']}\n";
        return;
    }

    printf("  Old budget: \$%.2f/day\n", $result['current_global']);
    printf("  New budget: \$%.2f/day (%+.2f%%)\n",
        $result['proposed_global'], $result['budget_delta_pct']);
    echo "  Reason: {$result['reason']}\n";

    if (!empty($result['distribution']['updated'])) {
        echo "\n  Platforms updated: " . implode(', ', $result['distribution']['updated']) . "\n";
    }

    if (!empty($result['distribution']['errors'])) {
        echo "\n  Errors:\n";
        foreach ($result['distribution']['errors'] as $err) {
            echo "    ! {$err}\n";
        }
    }
}

function cmdScaleAll(): void
{
    $db      = DB::get();
    $stmt    = $db->query(
        "SELECT p.id, p.name, p.display_name
         FROM projects p
         JOIN global_budgets gb ON gb.project_id = p.id
         WHERE gb.scaling_enabled = 1"
    );
    $projects = $stmt->fetchAll();

    if (empty($projects)) {
        echo "No projects with revenue scaling enabled.\n";
        return;
    }

    $scaler = new RevenueScaler();

    foreach ($projects as $project) {
        $name   = $project['display_name'] ?: $project['name'];
        $result = $scaler->run((int) $project['id']);

        if ($result['skipped'] ?? false) {
            echo "  {$name}: skipped\n";
            continue;
        }

        if ($result['action'] === 'no_change') {
            printf("  %s: no change (delta %.2f%%)\n", $name, $result['budget_delta_pct']);
        } else {
            printf("  %s: \$%.2f → \$%.2f (%+.2f%%)\n",
                $name,
                $result['current_global'],
                $result['proposed_global'],
                $result['budget_delta_pct']
            );
        }
    }
}

// ── Main ──────────────────────────────────────────────────────────────────────

DB::init();

[$positional, $named] = parseArgs($argv);

if (empty($positional)) {
    usage();
}

$command = array_shift($positional);

match ($command) {
    'record'     => cmdRecord($positional, $named),
    'import-ga4' => cmdImportGA4($positional, $named),
    'check'      => cmdCheck($positional),
    'scale'      => cmdScale($positional),
    'scale-all'  => cmdScaleAll(),
    default      => usage(),
};
