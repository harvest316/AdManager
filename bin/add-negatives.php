#!/usr/bin/env php
<?php
/**
 * Bulk-add negative keywords from docs/google-ads/negative-keywords.csv
 *
 * Usage:
 *   php bin/add-negatives.php                           # uses default CSV
 *   php bin/add-negatives.php path/to/negatives.csv
 *
 * Requires GOOGLE_ADS_CUSTOMER_ID in .env and campaign IDs to be set
 * in config/campaigns.php (created after first campaign sync).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use AdManager\Google\Client;
use AdManager\Google\Keywords;

Client::boot();

$csvPath = $argv[1] ?? dirname(__DIR__) . '/../mmo-platform/docs/google-ads/negative-keywords.csv';

if (!file_exists($csvPath)) {
    // Try relative to AdManager root
    $csvPath = dirname(__DIR__) . '/data/negative-keywords.csv';
}

if (!file_exists($csvPath)) {
    echo "Error: Cannot find negative-keywords.csv\n";
    echo "Pass path as argument: php bin/add-negatives.php /path/to/negative-keywords.csv\n";
    exit(1);
}

$keywords  = Keywords::loadNegativesCsv($csvPath);
$kw        = new Keywords();
$campaigns = loadCampaignMap();

echo "Loaded " . count($keywords) . " negative keywords from CSV\n";

$campaignGroups = [];
foreach ($keywords as $row) {
    if ($row['level'] === 'Account') {
        // Add to all campaigns
        foreach ($campaigns as $name => $id) {
            $campaignGroups[$name][] = $row;
        }
    } elseif ($row['level'] === 'Campaign' && isset($campaigns[$row['campaign']])) {
        $campaignGroups[$row['campaign']][] = $row;
    }
}

foreach ($campaignGroups as $campaignName => $negatives) {
    $campaignId = $campaigns[$campaignName];
    echo "Adding " . count($negatives) . " negatives to '{$campaignName}' (ID: {$campaignId})... ";
    try {
        $results = $kw->addNegativesToCampaign($campaignId, $negatives);
        echo "✓ " . count($results) . " added\n";
    } catch (Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";

function loadCampaignMap(): array
{
    $mapFile = dirname(__DIR__) . '/config/campaigns.php';
    if (file_exists($mapFile)) {
        return require $mapFile;
    }
    // Fallback: fetch from API
    echo "No config/campaigns.php found — fetching campaign list from API...\n";
    $client   = AdManager\Client::get();
    $service  = $client->getGoogleAdsServiceClient();
    $query    = "SELECT campaign.id, campaign.name FROM campaign WHERE campaign.status != 'REMOVED'";
    $map      = [];
    foreach ($service->search(AdManager\Client::customerId(), $query)->iterateAllElements() as $row) {
        $map[$row->getCampaign()->getName()] = $row->getCampaign()->getId();
    }
    return $map;
}
