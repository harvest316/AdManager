#!/usr/bin/env php
<?php
/**
 * Pull performance reports from Google Ads.
 *
 * Usage:
 *   php bin/report.php campaigns [date_range]
 *   php bin/report.php keywords  [date_range]
 *   php bin/report.php ads       [date_range]
 *   php bin/report.php terms     [date_range]   ← search terms (what people typed)
 *
 * Date ranges: LAST_7_DAYS (default), LAST_30_DAYS, THIS_MONTH, LAST_MONTH
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use AdManager\Google\Reports;

$type      = $argv[1] ?? 'campaigns';
$dateRange = $argv[2] ?? 'LAST_7_DAYS';

$reports = new Reports();

echo "\n=== {$type} — {$dateRange} ===\n\n";

switch ($type) {
    case 'campaigns':
        $rows = $reports->campaigns($dateRange);
        printTable($rows, ['Campaign', 'Status', 'Impr', 'Clicks', 'CTR', 'Avg CPC', 'Cost', 'Conv', 'CPA'], function ($row) {
            $c = $row->getCampaign();
            $m = $row->getMetrics();
            return [
                $c->getName(),
                statusLabel($c->getStatus()),
                number_format($m->getImpressions()),
                number_format($m->getClicks()),
                round($m->getCtr() * 100, 2) . '%',
                '$' . round($m->getAverageCpc() / 1_000_000, 2),
                '$' . round($m->getCostMicros() / 1_000_000, 2),
                round($m->getConversions(), 1),
                $m->getConversions() > 0
                    ? '$' . round($m->getCostPerConversion() / 1_000_000, 2)
                    : '-',
            ];
        });
        break;

    case 'keywords':
        $rows = $reports->keywords($dateRange);
        printTable($rows, ['Campaign', 'Ad Group', 'Keyword', 'Match', 'Impr', 'Clicks', 'CTR', 'Cost', 'Conv'], function ($row) {
            $kw = $row->getAdGroupCriterion()->getKeyword();
            $m  = $row->getMetrics();
            return [
                $row->getCampaign()->getName(),
                $row->getAdGroup()->getName(),
                $kw->getText(),
                matchLabel($kw->getMatchType()),
                number_format($m->getImpressions()),
                number_format($m->getClicks()),
                round($m->getCtr() * 100, 2) . '%',
                '$' . round($m->getCostMicros() / 1_000_000, 2),
                round($m->getConversions(), 1),
            ];
        });
        break;

    case 'terms':
        $rows = $reports->searchTerms($dateRange);
        printTable($rows, ['Campaign', 'Ad Group', 'Search Term', 'Status', 'Impr', 'Clicks', 'Cost', 'Conv'], function ($row) {
            $m = $row->getMetrics();
            return [
                $row->getCampaign()->getName(),
                $row->getAdGroup()->getName(),
                $row->getSearchTermView()->getSearchTerm(),
                $row->getSearchTermView()->getStatus(),
                number_format($m->getImpressions()),
                number_format($m->getClicks()),
                '$' . round($m->getCostMicros() / 1_000_000, 2),
                round($m->getConversions(), 1),
            ];
        });
        break;

    case 'ads':
        $rows = $reports->ads($dateRange);
        printTable($rows, ['Campaign', 'Ad Group', 'Ad ID', 'Type', 'Strength', 'Impr', 'Clicks', 'CTR', 'Cost', 'Conv'], function ($row) {
            $ad = $row->getAdGroupAd();
            $m  = $row->getMetrics();
            return [
                $row->getCampaign()->getName(),
                $row->getAdGroup()->getName(),
                $ad->getAd()->getId(),
                $ad->getAd()->getType(),
                $ad->getAdStrength(),
                number_format($m->getImpressions()),
                number_format($m->getClicks()),
                round($m->getCtr() * 100, 2) . '%',
                '$' . round($m->getCostMicros() / 1_000_000, 2),
                round($m->getConversions(), 1),
            ];
        });
        break;

    default:
        echo "Unknown report type: {$type}\n";
        echo "Valid types: campaigns, keywords, ads, terms\n";
        exit(1);
}

function printTable(array $rows, array $headers, callable $mapper): void
{
    if (empty($rows)) {
        echo "No data.\n";
        return;
    }

    $data = array_map($mapper, $rows);
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
    echo "\nTotal rows: " . count($rows) . "\n";
}

function statusLabel(int $status): string
{
    return match ($status) {
        2 => 'ENABLED', 3 => 'PAUSED', 4 => 'REMOVED', default => $status
    };
}

function matchLabel(int $match): string
{
    return match ($match) {
        2 => 'BROAD', 3 => 'PHRASE', 4 => 'EXACT', default => $match
    };
}
