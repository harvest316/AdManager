#!/usr/bin/env php
<?php
/**
 * Meta (Facebook/Instagram) Ads performance reports.
 *
 * Usage:
 *   php bin/meta-report.php campaigns [--range last_7d]
 *   php bin/meta-report.php account   [--range last_30d]
 *   php bin/meta-report.php campaign  <campaign_id> [--range last_7d]
 *   php bin/meta-report.php adset     <adset_id>    [--range last_7d]
 *   php bin/meta-report.php ad        <ad_id>       [--range last_7d]
 *
 * Date ranges: last_7d (default), last_14d, last_30d, this_month, last_month
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use AdManager\Meta\Reports;

$command = $argv[1] ?? 'campaigns';
$args    = parseArgs(array_slice($argv, 2));
$range   = $args['range'] ?? 'last_7d';

$reports = new Reports();

echo "\n=== Meta Ads — {$command} — {$range} ===\n\n";

try {
    switch ($command) {
        case 'campaigns':
            $rows = $reports->allCampaignInsights($range);
            if (empty($rows)) {
                echo "No data for this period.\n";
                break;
            }

            $headers = ['Campaign', 'Impr', 'Clicks', 'CTR', 'CPC', 'CPM', 'Spend', 'Results'];
            $data = array_map(function ($row) {
                return [
                    truncate($row['campaign_name'] ?? '-', 30),
                    number_format($row['impressions'] ?? 0),
                    number_format($row['clicks'] ?? 0),
                    ($row['ctr'] ?? '0') . '%',
                    '$' . number_format((float) ($row['cpc'] ?? 0), 2),
                    '$' . number_format((float) ($row['cpm'] ?? 0), 2),
                    '$' . number_format((float) ($row['spend'] ?? 0), 2),
                    formatActions($row['actions'] ?? []),
                ];
            }, $rows);

            printTable($headers, $data);

            // Summary
            $totalSpend = array_sum(array_column($rows, 'spend'));
            $totalClicks = array_sum(array_column($rows, 'clicks'));
            $totalImpr = array_sum(array_column($rows, 'impressions'));
            echo "\nSummary: {$totalImpr} impressions, {$totalClicks} clicks, \$" . number_format($totalSpend, 2) . " spend\n";
            break;

        case 'account':
            $rows = $reports->accountInsights($range);
            if (empty($rows)) {
                echo "No data for this period.\n";
                break;
            }

            echo "Account Performance:\n";
            foreach ($rows as $row) {
                echo "  Impressions:  " . number_format($row['impressions'] ?? 0) . "\n";
                echo "  Clicks:       " . number_format($row['clicks'] ?? 0) . "\n";
                echo "  CTR:          " . ($row['ctr'] ?? '0') . "%\n";
                echo "  CPC:          \$" . number_format((float) ($row['cpc'] ?? 0), 2) . "\n";
                echo "  CPM:          \$" . number_format((float) ($row['cpm'] ?? 0), 2) . "\n";
                echo "  Spend:        \$" . number_format((float) ($row['spend'] ?? 0), 2) . "\n";

                if (!empty($row['actions'])) {
                    echo "\n  Actions:\n";
                    foreach ($row['actions'] as $action) {
                        echo "    {$action['action_type']}: {$action['value']}\n";
                    }
                }

                if (!empty($row['cost_per_action_type'])) {
                    echo "\n  Cost per action:\n";
                    foreach ($row['cost_per_action_type'] as $cpa) {
                        echo "    {$cpa['action_type']}: \$" . number_format((float) $cpa['value'], 2) . "\n";
                    }
                }
            }
            break;

        case 'campaign':
            $id = findPositionalId($argv, 2);
            if (!$id) {
                echo "Error: campaign_id required.\n";
                exit(1);
            }
            $rows = $reports->campaignInsights($id, $range);
            printInsightRows($rows, 'Campaign');
            break;

        case 'adset':
            $id = findPositionalId($argv, 2);
            if (!$id) {
                echo "Error: adset_id required.\n";
                exit(1);
            }
            $rows = $reports->adSetInsights($id, $range);
            printInsightRows($rows, 'Ad Set');
            break;

        case 'ad':
            $id = findPositionalId($argv, 2);
            if (!$id) {
                echo "Error: ad_id required.\n";
                exit(1);
            }
            $rows = $reports->adInsights($id, $range);
            printInsightRows($rows, 'Ad');
            break;

        default:
            echo "Unknown report type: {$command}\n";
            echo "Valid types: campaigns, account, campaign, adset, ad\n";
            exit(1);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

function parseArgs(array $args): array
{
    $result = [];
    for ($i = 0; $i < count($args); $i++) {
        if (str_starts_with($args[$i], '--')) {
            $key = substr($args[$i], 2);
            $value = $args[$i + 1] ?? '';
            $result[$key] = $value;
            $i++;
        }
    }
    return $result;
}

function findPositionalId(array $argv, int $startIndex): ?string
{
    for ($i = $startIndex; $i < count($argv); $i++) {
        if (!str_starts_with($argv[$i], '--')) {
            return $argv[$i];
        }
        $i++; // skip the value after --flag
    }
    return null;
}

function printTable(array $headers, array $data): void
{
    if (empty($data)) {
        echo "No data.\n";
        return;
    }

    $widths = array_map('strlen', $headers);
    foreach ($data as $row) {
        foreach ($row as $i => $cell) {
            $widths[$i] = max($widths[$i], strlen((string) $cell));
        }
    }

    $line = implode(' | ', array_map(fn($h, $w) => str_pad($h, $w), $headers, $widths));
    echo $line . "\n";
    echo str_repeat('-', strlen($line)) . "\n";
    foreach ($data as $row) {
        echo implode(' | ', array_map(fn($c, $w) => str_pad((string) $c, $w), $row, $widths)) . "\n";
    }
    echo "\nTotal rows: " . count($data) . "\n";
}

function printInsightRows(array $rows, string $label): void
{
    if (empty($rows)) {
        echo "No data for this period.\n";
        return;
    }

    foreach ($rows as $row) {
        echo "{$label} Insights:\n";
        echo "  Impressions:  " . number_format($row['impressions'] ?? 0) . "\n";
        echo "  Clicks:       " . number_format($row['clicks'] ?? 0) . "\n";
        echo "  CTR:          " . ($row['ctr'] ?? '0') . "%\n";
        echo "  CPC:          \$" . number_format((float) ($row['cpc'] ?? 0), 2) . "\n";
        echo "  CPM:          \$" . number_format((float) ($row['cpm'] ?? 0), 2) . "\n";
        echo "  Spend:        \$" . number_format((float) ($row['spend'] ?? 0), 2) . "\n";

        if (!empty($row['actions'])) {
            echo "\n  Actions:\n";
            foreach ($row['actions'] as $action) {
                echo "    {$action['action_type']}: {$action['value']}\n";
            }
        }

        if (!empty($row['cost_per_action_type'])) {
            echo "\n  Cost per action:\n";
            foreach ($row['cost_per_action_type'] as $cpa) {
                echo "    {$cpa['action_type']}: \$" . number_format((float) $cpa['value'], 2) . "\n";
            }
        }
    }
}

function formatActions(array $actions): string
{
    if (empty($actions)) return '-';

    // Show the most interesting action types
    $priority = ['lead', 'purchase', 'complete_registration', 'link_click', 'landing_page_view'];

    foreach ($priority as $type) {
        foreach ($actions as $action) {
            if ($action['action_type'] === $type) {
                return "{$action['value']} {$type}";
            }
        }
    }

    // Fall back to first action
    $first = $actions[0];
    return "{$first['value']} {$first['action_type']}";
}

function truncate(string $str, int $max): string
{
    return strlen($str) > $max ? substr($str, 0, $max - 1) . '~' : $str;
}
