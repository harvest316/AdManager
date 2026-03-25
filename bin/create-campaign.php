#!/usr/bin/env php
<?php
/**
 * Interactive campaign builder.
 *
 * Usage: php bin/create-campaign.php
 *
 * Walks through campaign type, name, budget, bidding, and creates the campaign
 * in PAUSED state. You enable it manually in the UI after reviewing.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use AdManager\Google\Client;
use AdManager\Google\Campaign\Search;
use AdManager\Google\Campaign\PMax;
use AdManager\Google\Campaign\Display;
use AdManager\Google\Campaign\Video;
use AdManager\Google\Campaign\DemandGen;

Client::boot();

echo "\n=== AdManager — Create Campaign ===\n\n";

$type = prompt("Campaign type [search/pmax/display/video/demandgen]", 'search');
$name = prompt("Campaign name", "Audit\&Fix — " . ucfirst($type) . " — AU");
$budget = (float) prompt("Daily budget (USD)", "6.70");

$biddingOptions = match ($type) {
    'search'    => ['maximize_conversions', 'manual_cpc', 'target_cpa'],
    'pmax'      => ['maximize_conversions', 'maximize_conversion_value'],
    'display'   => ['maximize_conversions', 'target_cpa'],
    'video'     => ['maximize_conversions', 'target_cpa'],
    'demandgen' => ['maximize_conversions', 'maximize_conversion_value'],
    default     => ['maximize_conversions'],
};

$bidding = prompt("Bidding strategy [" . implode('/', $biddingOptions) . "]", $biddingOptions[0]);

$config = [
    'name'             => $name,
    'daily_budget_usd' => $budget,
    'bidding'          => $bidding,
];

if ($bidding === 'target_cpa') {
    $config['target_cpa_usd'] = (float) prompt("Target CPA (USD)", "150");
}
if ($bidding === 'maximize_conversion_value') {
    $roas = prompt("Target ROAS (optional, press enter to skip)", "");
    if ($roas !== '') $config['target_roas'] = (float) $roas;
}
if ($type === 'search') {
    $config['search_partners'] = strtolower(prompt("Include search partners? [y/n]", "n")) === 'y';
    $config['display_network'] = false;
}

echo "\n--- Summary ---\n";
echo "Type:    {$type}\n";
echo "Name:    {$name}\n";
echo "Budget:  \${$budget}/day\n";
echo "Bidding: {$bidding}\n";
if (!empty($config['target_cpa_usd'])) echo "CPA:     \${$config['target_cpa_usd']}\n";
echo "\nCampaign will be created in PAUSED state.\n\n";

$confirm = prompt("Create campaign? [y/n]", "y");
if (strtolower($confirm) !== 'y') {
    echo "Cancelled.\n";
    exit(0);
}

try {
    $resourceName = match ($type) {
        'pmax'      => (new PMax())->create($config),
        'display'   => (new Display())->create($config),
        'video'     => (new Video())->create($config),
        'demandgen' => (new DemandGen())->create($config),
        default     => (new Search())->create($config),
    };

    echo "\n✓ Campaign created: {$resourceName}\n";
    echo "\nNext steps:\n";
    echo "  1. Add ad groups and keywords\n";
    echo "  2. Add ads (RSAs)\n";
    echo "  3. Enable the campaign in Google Ads UI when ready\n\n";

    // Save to campaign map
    saveCampaignMap($name, extractId($resourceName));

} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

function prompt(string $question, string $default): string
{
    echo "{$question}" . ($default ? " [{$default}]" : "") . ": ";
    $input = trim(fgets(STDIN));
    return $input === '' ? $default : $input;
}

function extractId(string $resourceName): string
{
    $parts = explode('/', $resourceName);
    return end($parts);
}

function saveCampaignMap(string $name, string $id): void
{
    $mapFile = dirname(__DIR__) . '/config/campaigns.php';
    $map = file_exists($mapFile) ? require $mapFile : [];
    $map[$name] = $id;
    $dir = dirname($mapFile);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($mapFile, "<?php\nreturn " . var_export($map, true) . ";\n");
    echo "Campaign ID saved to config/campaigns.php\n";
}
