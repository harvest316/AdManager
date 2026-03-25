#!/usr/bin/env php
<?php
/**
 * Sitelink and callout asset management.
 *
 * Usage:
 *   php bin/assets.php sitelink <campaign_id> <link_text> <url> [desc1] [desc2]
 *   php bin/assets.php callout <campaign_id> <text>
 *   php bin/assets.php list <campaign_id>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\Client;
use AdManager\Assets;

Client::boot();

$command    = $argv[1] ?? null;
$campaignId = $argv[2] ?? null;

if (!$command || !$campaignId) {
    echo "Usage:\n";
    echo "  php bin/assets.php sitelink <campaign_id> <link_text> <url> [desc1] [desc2]\n";
    echo "  php bin/assets.php callout <campaign_id> <text>\n";
    echo "  php bin/assets.php list <campaign_id>\n";
    exit(1);
}

$assets = new Assets();

try {
    switch ($command) {
        case 'sitelink':
            $linkText = $argv[3] ?? null;
            $url      = $argv[4] ?? null;
            if (!$linkText || !$url) {
                echo "Error: sitelink requires <link_text> and <url>.\n";
                echo "Usage: php bin/assets.php sitelink <campaign_id> <link_text> <url> [desc1] [desc2]\n";
                exit(1);
            }
            $desc1    = $argv[5] ?? '';
            $desc2    = $argv[6] ?? '';
            $assetRn  = $assets->addSitelink($campaignId, $linkText, $url, $desc1, $desc2);
            echo "Sitelink created and attached to campaign {$campaignId}.\n";
            echo "Asset: {$assetRn}\n";
            break;

        case 'callout':
            $text = $argv[3] ?? null;
            if (!$text) {
                echo "Error: callout requires <text>.\n";
                echo "Usage: php bin/assets.php callout <campaign_id> <text>\n";
                exit(1);
            }
            $assetRn = $assets->addCallout($campaignId, $text);
            echo "Callout created and attached to campaign {$campaignId}.\n";
            echo "Asset: {$assetRn}\n";
            break;

        case 'list':
            $rows = $assets->listCampaignAssets($campaignId);
            if (empty($rows)) {
                echo "No assets found for campaign {$campaignId}.\n";
                break;
            }
            echo "Assets for campaign {$campaignId} (" . count($rows) . " total):\n\n";
            foreach ($rows as $row) {
                echo "  [{$row['field_type']}] type={$row['type']}  text=\"{$row['text']}\"\n";
                echo "         {$row['resource_name']}\n";
            }
            break;

        default:
            echo "Unknown command: {$command}\n";
            echo "Valid commands: sitelink, callout, list\n";
            exit(1);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
