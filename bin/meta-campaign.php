#!/usr/bin/env php
<?php
/**
 * Meta (Facebook/Instagram) campaign management CLI.
 *
 * Usage:
 *   php bin/meta-campaign.php create --name "..." --objective OUTCOME_SALES [--status PAUSED]
 *   php bin/meta-campaign.php list
 *   php bin/meta-campaign.php get <campaign_id>
 *   php bin/meta-campaign.php pause <campaign_id>
 *   php bin/meta-campaign.php enable <campaign_id>
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use AdManager\Meta\Campaign;

$command = $argv[1] ?? null;

if (!$command) {
    usage();
    exit(1);
}

$campaign = new Campaign();

try {
    switch ($command) {
        case 'create':
            $args = parseArgs(array_slice($argv, 2));
            if (empty($args['name']) || empty($args['objective'])) {
                echo "Error: --name and --objective are required.\n\n";
                usage();
                exit(1);
            }

            $validObjectives = [
                'OUTCOME_AWARENESS', 'OUTCOME_TRAFFIC', 'OUTCOME_ENGAGEMENT',
                'OUTCOME_LEADS', 'OUTCOME_SALES', 'OUTCOME_APP_PROMOTION',
            ];
            if (!in_array($args['objective'], $validObjectives)) {
                echo "Error: invalid objective '{$args['objective']}'.\n";
                echo "Valid: " . implode(', ', $validObjectives) . "\n";
                exit(1);
            }

            $config = [
                'name'      => $args['name'],
                'objective' => $args['objective'],
                'status'    => $args['status'] ?? 'PAUSED',
            ];

            if (!empty($args['daily-budget'])) {
                $config['daily_budget'] = (int) $args['daily-budget'];
            }

            $id = $campaign->create($config);
            echo "Campaign created: {$id}\n";
            echo "Status: {$config['status']}\n";
            break;

        case 'list':
            $rows = $campaign->list();
            if (empty($rows)) {
                echo "No campaigns found.\n";
                break;
            }

            $headers = ['ID', 'Name', 'Status', 'Objective', 'Daily Budget', 'Created'];
            $data = array_map(function ($c) {
                return [
                    $c['id'],
                    $c['name'],
                    $c['status'] ?? '-',
                    $c['objective'] ?? '-',
                    isset($c['daily_budget']) ? '$' . number_format($c['daily_budget'] / 100, 2) : '-',
                    isset($c['created_time']) ? substr($c['created_time'], 0, 10) : '-',
                ];
            }, $rows);

            printTable($headers, $data);
            echo "\nTotal: " . count($rows) . " campaigns\n";
            break;

        case 'get':
            $id = $argv[2] ?? null;
            if (!$id) {
                echo "Error: campaign_id required.\n";
                exit(1);
            }
            $detail = $campaign->get($id);
            printKeyValue($detail);
            break;

        case 'pause':
            $id = $argv[2] ?? null;
            if (!$id) {
                echo "Error: campaign_id required.\n";
                exit(1);
            }
            $campaign->pause($id);
            echo "Campaign {$id} paused.\n";
            break;

        case 'enable':
            $id = $argv[2] ?? null;
            if (!$id) {
                echo "Error: campaign_id required.\n";
                exit(1);
            }
            $campaign->enable($id);
            echo "Campaign {$id} enabled.\n";
            break;

        default:
            echo "Unknown command: {$command}\n\n";
            usage();
            exit(1);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

function usage(): void
{
    echo "Usage:\n";
    echo "  php bin/meta-campaign.php create --name \"...\" --objective OUTCOME_SALES [--status PAUSED] [--daily-budget 2000]\n";
    echo "  php bin/meta-campaign.php list\n";
    echo "  php bin/meta-campaign.php get <campaign_id>\n";
    echo "  php bin/meta-campaign.php pause <campaign_id>\n";
    echo "  php bin/meta-campaign.php enable <campaign_id>\n";
    echo "\nObjectives: OUTCOME_AWARENESS, OUTCOME_TRAFFIC, OUTCOME_ENGAGEMENT, OUTCOME_LEADS, OUTCOME_SALES\n";
}

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

function printTable(array $headers, array $data): void
{
    $widths = array_map('strlen', $headers);
    foreach ($data as $row) {
        foreach ($row as $i => $cell) {
            $widths[$i] = max($widths[$i], strlen((string) $cell));
        }
    }

    $line = implode(' | ', array_map(fn($h, $w) => str_pad($h, $w), $headers, $widths));
    echo "\n{$line}\n";
    echo str_repeat('-', strlen($line)) . "\n";
    foreach ($data as $row) {
        echo implode(' | ', array_map(fn($c, $w) => str_pad((string) $c, $w), $row, $widths)) . "\n";
    }
}

function printKeyValue(array $data, string $indent = ''): void
{
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            echo "{$indent}{$key}:\n";
            printKeyValue($value, $indent . '  ');
        } else {
            echo "{$indent}{$key}: {$value}\n";
        }
    }
}
