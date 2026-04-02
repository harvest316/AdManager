#!/usr/bin/env php
<?php
/**
 * Automated copy refresh cycle.
 *
 * Identifies underperforming ad copy, generates replacements via Opus,
 * proofreads, and auto-approves. Run periodically (weekly/bi-weekly)
 * as part of campaign optimisation.
 *
 * Usage:
 *   php bin/refresh-copy.php --project <name> [--campaign <name>] [--market AU] [--max N]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Optimise\CopyRefresher;

function parseArgs(array $argv): array
{
    $named = [];
    for ($i = 1; $i < count($argv); $i++) {
        if (str_starts_with($argv[$i], '--')) {
            $key = substr($argv[$i], 2);
            if (!isset($argv[$i + 1]) || str_starts_with($argv[$i + 1], '--')) {
                $named[$key] = true;
            } else {
                $named[$key] = $argv[$i + 1];
                $i++;
            }
        }
    }
    return $named;
}

$args = parseArgs($argv);

if (!isset($args['project'])) {
    echo <<<USAGE
Usage:
  php bin/refresh-copy.php --project <name> [--campaign <name>] [--market AU] [--max N]

Options:
  --project    Project name
  --campaign   Specific campaign (default: all campaigns)
  --market     Target market for locale (AU, US, GB)
  --max        Max replacements per campaign (default: all weak)
  --strategy   Strategy ID for context

Identifies weak headlines (QA score < 70 or Google Ads "Low" label),
generates replacements via Opus, proofreads, and auto-approves >= 70.

USAGE;
    exit(1);
}

DB::init();
$db = DB::get();

$refresher = new CopyRefresher();

$options = [
    'market' => $args['market'] ?? 'all',
];
if (isset($args['max'])) $options['max_replacements'] = (int) $args['max'];
if (isset($args['strategy'])) $options['strategy_id'] = (int) $args['strategy'];

// Resolve project(s)
$projectArg = $args['project'];
if ($projectArg === 'all') {
    $projects = $db->query('SELECT * FROM projects ORDER BY id')->fetchAll();
    if (empty($projects)) {
        echo "No projects found.\n";
        exit(0);
    }
} else {
    $stmt = $db->prepare('SELECT * FROM projects WHERE name = ?');
    $stmt->execute([$projectArg]);
    $project = $stmt->fetch();
    if (!$project) {
        echo "Error: project '{$projectArg}' not found.\n";
        exit(1);
    }
    $projects = [$project];
}

$grandTotalWeak = 0;
$grandTotalGenerated = 0;
$grandTotalApproved = 0;

foreach ($projects as $project) {
    $projectId = (int) $project['id'];
    $projectLabel = $project['display_name'] ?? $project['name'];

    echo "Project: {$projectLabel}\n";

    if (isset($args['campaign'])) {
        $campaignName = $args['campaign'];
        echo "  Refreshing campaign: {$campaignName}\n";
        $result = $refresher->refresh($projectId, $campaignName, $options);
        printResult($campaignName, $result);
        $grandTotalWeak += $result['weak_found'];
        $grandTotalGenerated += $result['generated'];
        $grandTotalApproved += $result['approved'];
    } else {
        $results = $refresher->refreshAll($projectId, $options);

        if (empty($results)) {
            echo "  No campaigns with approved copy found.\n";
        } else {
            foreach ($results as $campaignName => $result) {
                printResult($campaignName, $result);
                $grandTotalWeak += $result['weak_found'];
                $grandTotalGenerated += $result['generated'];
                $grandTotalApproved += $result['approved'];
            }
        }
    }

    echo "\n";
}

if (count($projects) > 1 || $projectArg === 'all') {
    echo "=== Grand Total ===\n";
    echo "  Weak found: {$grandTotalWeak}  Generated: {$grandTotalGenerated}  Auto-approved: {$grandTotalApproved}\n";
}

function printResult(string $campaignName, array $r): void
{
    if ($r['weak_found'] === 0) {
        echo "  {$campaignName}: no weak headlines found\n";
    } else {
        echo "  {$campaignName}: {$r['weak_found']} weak → {$r['generated']} generated → {$r['approved']} approved, {$r['review']} review\n";
    }
}
