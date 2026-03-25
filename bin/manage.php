#!/usr/bin/env php
<?php
/**
 * Campaign status and budget management.
 *
 * Usage:
 *   php bin/manage.php enable <campaign_id>
 *   php bin/manage.php pause <campaign_id>
 *   php bin/manage.php budget <campaign_id> <daily_aud>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\Google\Client;
use AdManager\Google\Campaign\Manager;

Client::boot();

$command    = $argv[1] ?? null;
$campaignId = $argv[2] ?? null;

if (!$command || !$campaignId) {
    echo "Usage:\n";
    echo "  php bin/manage.php enable <campaign_id>\n";
    echo "  php bin/manage.php pause <campaign_id>\n";
    echo "  php bin/manage.php budget <campaign_id> <daily_aud>\n";
    exit(1);
}

$manager = new Manager();

try {
    switch ($command) {
        case 'enable':
            $manager->enable($campaignId);
            echo "Campaign {$campaignId} enabled.\n";
            break;

        case 'pause':
            $manager->pause($campaignId);
            echo "Campaign {$campaignId} paused.\n";
            break;

        case 'budget':
            $amountAud = isset($argv[3]) ? (float) $argv[3] : null;
            if ($amountAud === null) {
                echo "Error: daily_aud amount required.\n";
                exit(1);
            }
            $budgetId = $manager->getBudgetId($campaignId);
            if ($budgetId === null) {
                echo "Error: could not find budget for campaign {$campaignId}.\n";
                exit(1);
            }
            $manager->setDailyBudget($budgetId, $amountAud);
            echo "Campaign {$campaignId} budget set to \${$amountAud}/day (budget ID: {$budgetId}).\n";
            break;

        default:
            echo "Unknown command: {$command}\n";
            echo "Valid commands: enable, pause, budget\n";
            exit(1);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
