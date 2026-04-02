#!/usr/bin/env php
<?php
/**
 * End-to-end ad creation workflow.
 *
 * Loads project + strategy from the DB, checks for approved creative assets,
 * optionally generates new creative, then creates a full campaign (paused)
 * on Google or Meta.
 *
 * Usage:
 *   php bin/create-ad.php --project <name> --platform google|meta --strategy <id> [--auto-generate-creative]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Creative\ImageGen;
use AdManager\Creative\ReviewStore;
use AdManager\Copy\Store as CopyStore;
use AdManager\Google\Client;
use AdManager\Google\AssetUpload;
use AdManager\Google\AdGroup;
use AdManager\Google\Ads\ResponsiveSearch;
use AdManager\Google\Campaign\Search as GoogleSearch;
use AdManager\Google\Campaign\Display as GoogleDisplay;
use AdManager\Google\Campaign\DemandGen as GoogleDemandGen;

// ── Arg parsing ──────────────────────────────────────────────────────────────

function parseArgs(array $argv): array
{
    $named = [];
    for ($i = 1; $i < count($argv); $i++) {
        if (str_starts_with($argv[$i], '--')) {
            $key = substr($argv[$i], 2);
            // Boolean flags (no value following)
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

function usage(): never
{
    echo <<<USAGE
Usage:
  php bin/create-ad.php --project <name> --platform google|meta --strategy <id> [--auto-generate-creative]

Options:
  --project                 Project name (as stored in DB)
  --platform                Target platform: google or meta
  --strategy                Strategy ID from the strategies table
  --auto-generate-creative  If no approved assets exist, generate a draft image

Flow:
  1. Loads project and strategy from the local DB
  2. Checks for approved assets (status='approved') for the project
  3. If --auto-generate-creative and no approved assets:
     - Generates draft image from strategy's creative direction
     - Saves to DB as 'draft' — you must approve it in the dashboard first
  4. If approved assets exist:
     - Uploads images to the target platform
     - Creates campaign (PAUSED) + ad group + ad
     - Saves everything to local DB with external IDs

USAGE;
    exit(1);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function extractId(string $resourceName): string
{
    $parts = explode('/', $resourceName);
    return end($parts);
}

function loadProject(string $name): array
{
    $db = DB::get();
    $stmt = $db->prepare('SELECT * FROM projects WHERE name = :name');
    $stmt->execute([':name' => $name]);
    $project = $stmt->fetch();

    if (!$project) {
        echo "Error: project '{$name}' not found.\n";
        echo "Create one first: php bin/project.php create {$name} --url https://...\n";
        exit(1);
    }

    return $project;
}

function loadStrategy(int $strategyId): array
{
    $db = DB::get();
    $stmt = $db->prepare('SELECT * FROM strategies WHERE id = :id');
    $stmt->execute([':id' => $strategyId]);
    $strategy = $stmt->fetch();

    if (!$strategy) {
        echo "Error: strategy #{$strategyId} not found.\n";
        exit(1);
    }

    return $strategy;
}

function loadBudget(int $projectId, string $platform): float
{
    $db = DB::get();
    $stmt = $db->prepare(
        'SELECT daily_budget_aud FROM budgets WHERE project_id = :pid AND platform = :platform'
    );
    $stmt->execute([':pid' => $projectId, ':platform' => $platform]);
    $row = $stmt->fetch();

    if (!$row) {
        echo "Warning: no budget set for project #{$projectId} / {$platform}. Using default $5.00/day.\n";
        return 5.00;
    }

    return (float) $row['daily_budget_aud'];
}

function saveAssetToDB(int $projectId, string $localPath, string $prompt, string $model): int
{
    $db = DB::get();
    $stmt = $db->prepare(<<<'SQL'
        INSERT INTO assets (project_id, type, platform, local_path, generation_prompt, generation_model, status)
        VALUES (:project_id, 'image', 'local', :local_path, :prompt, :model, 'draft')
    SQL);
    $stmt->execute([
        ':project_id' => $projectId,
        ':local_path' => $localPath,
        ':prompt'     => $prompt,
        ':model'      => $model,
    ]);
    return (int) $db->lastInsertId();
}

function saveCampaignToDB(int $projectId, string $platform, string $externalId, string $name, string $type, float $budget, int $strategyId): int
{
    $db = DB::get();
    $stmt = $db->prepare(<<<'SQL'
        INSERT INTO campaigns (project_id, platform, external_id, name, type, status, daily_budget_aud, strategy_id)
        VALUES (:project_id, :platform, :external_id, :name, :type, 'paused', :budget, :strategy_id)
    SQL);
    $stmt->execute([
        ':project_id'  => $projectId,
        ':platform'    => $platform,
        ':external_id' => $externalId,
        ':name'        => $name,
        ':type'        => $type,
        ':budget'      => $budget,
        ':strategy_id' => $strategyId,
    ]);
    return (int) $db->lastInsertId();
}

function saveAdGroupToDB(int $campaignId, string $externalId, string $name): int
{
    $db = DB::get();
    $stmt = $db->prepare(<<<'SQL'
        INSERT INTO ad_groups (campaign_id, external_id, name, status)
        VALUES (:campaign_id, :external_id, :name, 'paused')
    SQL);
    $stmt->execute([
        ':campaign_id' => $campaignId,
        ':external_id' => $externalId,
        ':name'        => $name,
    ]);
    return (int) $db->lastInsertId();
}

function saveAdToDB(int $adGroupId, string $externalId, string $type, string $finalUrl): int
{
    $db = DB::get();
    $stmt = $db->prepare(<<<'SQL'
        INSERT INTO ads (ad_group_id, external_id, type, status, final_url)
        VALUES (:ad_group_id, :external_id, :type, 'paused', :final_url)
    SQL);
    $stmt->execute([
        ':ad_group_id' => $adGroupId,
        ':external_id' => $externalId,
        ':type'        => $type,
        ':final_url'   => $finalUrl,
    ]);
    return (int) $db->lastInsertId();
}

function saveAdAsset(int $adId, int $assetId, string $role): void
{
    $db = DB::get();
    $stmt = $db->prepare(<<<'SQL'
        INSERT OR IGNORE INTO ad_assets (ad_id, asset_id, role)
        VALUES (:ad_id, :asset_id, :role)
    SQL);
    $stmt->execute([
        ':ad_id'    => $adId,
        ':asset_id' => $assetId,
        ':role'     => $role,
    ]);
}

function markAssetUploaded(int $assetId, string $externalId): void
{
    $db = DB::get();
    $stmt = $db->prepare("UPDATE assets SET status = 'uploaded', external_id = :eid WHERE id = :id");
    $stmt->execute([':eid' => $externalId, ':id' => $assetId]);
}

// ── Creative direction from strategy ─────────────────────────────────────────

function buildCreativePrompt(array $strategy): string
{
    $parts = [];

    if (!empty($strategy['value_proposition'])) {
        $parts[] = "Value proposition: {$strategy['value_proposition']}";
    }
    if (!empty($strategy['target_audience'])) {
        $parts[] = "Target audience: {$strategy['target_audience']}";
    }
    if (!empty($strategy['tone'])) {
        $parts[] = "Tone: {$strategy['tone']}";
    }

    $context = implode('. ', $parts);

    return "Create a professional digital ad image for a {$strategy['campaign_type']} campaign. "
         . "{$context}. "
         . "Clean, modern design suitable for display advertising. No text overlay needed.";
}

// ── Google ad creation ───────────────────────────────────────────────────────

function createGoogleAd(array $project, array $strategy, array $approvedAssets, float $budget): void
{
    Client::boot();

    $campaignType = strtolower($strategy['campaign_type'] ?? 'search');
    $campaignName = "{$project['display_name']} — " . ucfirst($campaignType) . " — Ad";

    echo "Creating Google {$campaignType} campaign: {$campaignName}\n";

    // 1. Create campaign (paused)
    $config = [
        'name'             => $campaignName,
        'daily_budget_usd' => $budget,
        'bidding'          => 'maximize_conversions',
    ];

    $campaignRn = match ($campaignType) {
        'display'   => (new GoogleDisplay())->create($config),
        'demandgen' => (new GoogleDemandGen())->create($config),
        default     => (new GoogleSearch())->create($config),
    };
    $campaignExtId = extractId($campaignRn);
    echo "  Campaign created: {$campaignRn}\n";

    // Save campaign to DB
    $localCampaignId = saveCampaignToDB(
        $project['id'], 'google', $campaignExtId, $campaignName, $campaignType, $budget, $strategy['id']
    );

    // 2. Create ad group
    $adGroupName = "{$project['display_name']} — AG1";
    $adGroupSvc  = new AdGroup();

    $adGroupType = match ($campaignType) {
        'display'   => \Google\Ads\GoogleAds\V20\Enums\AdGroupTypeEnum\AdGroupType::DISPLAY_STANDARD,
        default     => \Google\Ads\GoogleAds\V20\Enums\AdGroupTypeEnum\AdGroupType::SEARCH_STANDARD,
    };

    $adGroupRn    = $adGroupSvc->create($campaignExtId, $adGroupName, 0, $adGroupType);
    $adGroupExtId = extractId($adGroupRn);
    echo "  Ad group created: {$adGroupRn}\n";

    $localAdGroupId = saveAdGroupToDB($localCampaignId, $adGroupExtId, $adGroupName);

    // 3. Upload image assets to Google
    $uploader        = new AssetUpload();
    $uploadedAssetRn = null;

    foreach ($approvedAssets as $asset) {
        if (!empty($asset['local_path']) && file_exists($asset['local_path'])) {
            $assetName = $project['name'] . ' — Image ' . $asset['id'];
            echo "  Uploading image: {$asset['local_path']}\n";

            try {
                $assetRn = $uploader->uploadImage($asset['local_path'], $assetName);
                echo "  Asset uploaded: {$assetRn}\n";
                markAssetUploaded($asset['id'], $assetRn);
                $uploadedAssetRn = $assetRn;
            } catch (\Exception $e) {
                echo "  Warning: failed to upload asset #{$asset['id']}: {$e->getMessage()}\n";
            }
        }
    }

    // 4. Create ad (RSA for search, or display ad for display)
    $finalUrl = $project['website_url'] ?? (getenv('BRAND_URL') ?: 'https://example.com');

    if ($campaignType === 'search') {
        // Load approved copy from ad_copy table (populated by bin/proofread-copy.php)
        $copyStore = new CopyStore();

        // Try to match campaign name from strategy; fall back to first available
        $campaignName = $args['campaign-name'] ?? null;
        if (!$campaignName) {
            // Auto-detect: find campaigns with approved headlines
            $allCopy = $copyStore->listByProject((int) $project['id'], 'approved', 'headline', 'google');
            $campaignNames = array_unique(array_column($allCopy, 'campaign_name'));
            $campaignName = reset($campaignNames) ?: null;
        }

        $approvedHeadlines = $campaignName
            ? $copyStore->getApprovedForCampaign((int) $project['id'], $campaignName, 'headline')
            : [];
        $approvedDescriptions = $campaignName
            ? $copyStore->getApprovedForCampaign((int) $project['id'], $campaignName, 'description')
            : [];

        if (empty($approvedHeadlines) || empty($approvedDescriptions)) {
            echo "Error: no approved ad copy found for campaign '{$campaignName}'.\n";
            echo "Run proofreading first: php bin/proofread-copy.php --project {$projectName} --strategy {$args['strategy']}\n";
            echo "Then review/approve copy in the dashboard.\n";
            exit(1);
        }

        echo "  Using " . count($approvedHeadlines) . " approved headlines + " . count($approvedDescriptions) . " descriptions";
        echo $campaignName ? " from campaign '{$campaignName}'\n" : "\n";

        $headlines = array_map(function ($h) {
            $entry = ['text' => $h['content']];
            if ($h['pin_position']) {
                $entry['pin'] = (int) $h['pin_position'];
            }
            return $entry;
        }, $approvedHeadlines);

        $descriptions = array_map(function ($d) {
            return ['text' => $d['content']];
        }, $approvedDescriptions);

        $rsaConfig = [
            'final_url'    => $finalUrl,
            'display_path' => ['Get-Started', ''],
            'headlines'    => $headlines,
            'descriptions' => $descriptions,
        ];

        $rsaSvc = new ResponsiveSearch();
        $adRn   = $rsaSvc->create($adGroupExtId, $rsaConfig);
        echo "  RSA created: {$adRn}\n";

        $adExtId = extractId($adRn);
        $localAdId = saveAdToDB($localAdGroupId, $adExtId, 'responsive_search', $finalUrl);
    } else {
        // For display/demandgen, we create a placeholder ad record
        // (full responsive display ad creation requires more asset types)
        echo "  Note: display/demandgen ads require manual creative setup in Google Ads UI.\n";
        echo "  Image assets have been uploaded to your asset library.\n";
        $localAdId = saveAdToDB($localAdGroupId, '', $campaignType, $finalUrl);
    }

    // Link assets to the ad in local DB
    foreach ($approvedAssets as $asset) {
        saveAdAsset($localAdId, $asset['id'], 'image');
    }

    echo "\n";
    printGoogleSummary($project, $campaignExtId, $localCampaignId);
}

// ── Meta ad creation ─────────────────────────────────────────────────────────

function createMetaAd(array $project, array $strategy, array $approvedAssets, float $budget): void
{
    $metaCampaign = new \AdManager\Meta\Campaign();
    $metaAdSet    = new \AdManager\Meta\AdSet();
    $metaAd       = new \AdManager\Meta\Ad();
    $metaAssets   = new \AdManager\Meta\Assets();

    $campaignName = "{$project['display_name']} — Meta — Ad";
    $campaignType = strtolower($strategy['campaign_type'] ?? 'traffic');

    $objective = match ($campaignType) {
        'sales', 'purchase'   => 'OUTCOME_SALES',
        'leads', 'lead'       => 'OUTCOME_LEADS',
        'awareness'           => 'OUTCOME_AWARENESS',
        'engagement'          => 'OUTCOME_ENGAGEMENT',
        default               => 'OUTCOME_TRAFFIC',
    };

    echo "Creating Meta campaign: {$campaignName} (objective: {$objective})\n";

    // 1. Create campaign (paused)
    $campaignId = $metaCampaign->create([
        'name'      => $campaignName,
        'objective' => $objective,
        'status'    => 'PAUSED',
    ]);
    echo "  Campaign created: {$campaignId}\n";

    $localCampaignId = saveCampaignToDB(
        $project['id'], 'meta', $campaignId, $campaignName, $campaignType, $budget, $strategy['id']
    );

    // 2. Create ad set
    $adSetName   = "{$project['display_name']} — AdSet1";
    $budgetCents = (int) ($budget * 100);

    $adSetConfig = [
        'name'              => $adSetName,
        'daily_budget'      => $budgetCents,
        'optimization_goal' => 'LINK_CLICKS',
        'billing_event'     => 'IMPRESSIONS',
        'targeting'         => [
            'geo_locations' => ['countries' => ['AU']],
            'age_min'       => 25,
            'age_max'       => 65,
        ],
        'status' => 'PAUSED',
    ];

    $adSetId = $metaAdSet->create($campaignId, $adSetConfig);
    echo "  Ad set created: {$adSetId}\n";

    $localAdGroupId = saveAdGroupToDB($localCampaignId, $adSetId, $adSetName);

    // 3. Upload images and create creative
    $imageHash = null;
    $firstAsset = null;

    foreach ($approvedAssets as $asset) {
        if (!empty($asset['local_path']) && file_exists($asset['local_path'])) {
            echo "  Uploading image: {$asset['local_path']}\n";

            try {
                $result = $metaAssets->uploadImage($asset['local_path']);
                echo "  Image uploaded: hash={$result['hash']}\n";
                markAssetUploaded($asset['id'], $result['hash']);

                if ($imageHash === null) {
                    $imageHash  = $result['hash'];
                    $firstAsset = $asset;
                }
            } catch (\Exception $e) {
                echo "  Warning: failed to upload asset #{$asset['id']}: {$e->getMessage()}\n";
            }
        }
    }

    if (!$imageHash) {
        echo "  Error: no images could be uploaded. Skipping ad creation.\n";
        return;
    }

    // 4. Create ad creative
    $finalUrl = $project['website_url'] ?? (getenv('BRAND_URL') ?: 'https://example.com');
    $message  = $strategy['value_proposition'] ?? "Check out {$project['display_name']}";

    $pageId = getenv('META_PAGE_ID') ?: '';
    if (!$pageId) {
        echo "  Warning: META_PAGE_ID not set. Creative may fail without a Facebook Page.\n";
    }

    $creativeId = $metaAd->createCreative([
        'name' => "{$project['display_name']} — Creative — v1",
        'object_story_spec' => [
            'page_id'   => $pageId,
            'link_data' => [
                'message'        => $message,
                'link'           => $finalUrl,
                'image_hash'     => $imageHash,
                'call_to_action' => [
                    'type'  => 'LEARN_MORE',
                    'value' => ['link' => $finalUrl],
                ],
            ],
        ],
    ]);
    echo "  Creative created: {$creativeId}\n";

    // 5. Create ad
    $adName = "{$project['display_name']} — Ad — v1";
    $adId   = $metaAd->create($adSetId, $creativeId, $adName, 'PAUSED');
    echo "  Ad created: {$adId}\n";

    $localAdId = saveAdToDB($localAdGroupId, $adId, 'image', $finalUrl);

    foreach ($approvedAssets as $asset) {
        saveAdAsset($localAdId, $asset['id'], 'image');
    }

    echo "\n";
    printMetaSummary($project, $campaignId, $localCampaignId);
}

// ── Summary output ───────────────────────────────────────────────────────────

function printGoogleSummary(array $project, string $campaignExtId, int $localCampaignId): void
{
    echo "=== Campaign Created (PAUSED) ===\n\n";
    echo "  Platform:       Google Ads\n";
    echo "  Campaign ID:    {$campaignExtId}\n";
    echo "  Local DB ID:    {$localCampaignId}\n";
    echo "  Review:         http://localhost:8080/?project={$project['name']}\n";
    echo "\n";
    echo "To enable:\n";
    echo "  php bin/manage.php enable {$campaignExtId}\n";
    echo "\n";
}

function printMetaSummary(array $project, string $campaignExtId, int $localCampaignId): void
{
    echo "=== Campaign Created (PAUSED) ===\n\n";
    echo "  Platform:       Meta (Facebook/Instagram)\n";
    echo "  Campaign ID:    {$campaignExtId}\n";
    echo "  Local DB ID:    {$localCampaignId}\n";
    echo "  Review:         http://localhost:8080/?project={$project['name']}\n";
    echo "\n";
    echo "To enable:\n";
    echo "  php bin/meta-campaign.php enable {$campaignExtId}\n";
    echo "\n";
}

// ── Main ─────────────────────────────────────────────────────────────────────

DB::init();

$args = parseArgs($argv);

$projectName  = $args['project'] ?? null;
$platform     = strtolower($args['platform'] ?? '');
$strategyId   = isset($args['strategy']) ? (int) $args['strategy'] : null;
$autoGenerate = isset($args['auto-generate-creative']);

if (!$projectName || !$platform || !$strategyId) {
    usage();
}

if (!in_array($platform, ['google', 'meta'])) {
    echo "Error: platform must be 'google' or 'meta'.\n";
    usage();
}

echo "\n=== AdManager — Create Ad ===\n\n";

// 1. Load project
$project = loadProject($projectName);
echo "Project:  {$project['name']}";
if ($project['display_name']) echo " ({$project['display_name']})";
echo "\n";

// 2. Load strategy
$strategy = loadStrategy($strategyId);
echo "Strategy: #{$strategy['id']} — {$strategy['name']}\n";
echo "Platform: {$platform}\n\n";

// 3. Check for approved assets
$reviewStore    = new ReviewStore();
$approvedAssets = $reviewStore->listByProject($project['id'], 'approved');

echo "Approved assets: " . count($approvedAssets) . "\n";

// 4. If no approved assets and auto-generate requested
if (empty($approvedAssets) && $autoGenerate) {
    echo "\nNo approved assets found. Generating draft creative...\n";

    try {
        $prompt  = buildCreativePrompt($strategy);
        $gen     = new ImageGen();
        $cost    = $gen->estimateCost('draft');
        echo "  Prompt: {$prompt}\n";
        echo "  Mode: draft (~\${$cost})\n";

        $filePath = $gen->generate($prompt, 'draft');
        echo "  Image saved: {$filePath}\n";

        $assetId = saveAssetToDB($project['id'], $filePath, $prompt, 'draft');
        echo "  Asset saved to DB: #{$assetId} (status: draft)\n";
    } catch (\Exception $e) {
        echo "  Error generating creative: {$e->getMessage()}\n";
        exit(1);
    }

    echo "\n";
    echo "Creative generated. Review and approve it before continuing:\n";
    echo "  Dashboard: http://localhost:8080/?project={$project['name']}\n";
    echo "  Approve:   (use the review dashboard or ReviewStore::approve({$assetId}))\n";
    echo "\nThen re-run this command without --auto-generate-creative.\n";
    exit(0);
}

// 5. If still no approved assets and no auto-generate
if (empty($approvedAssets)) {
    echo "\nNo approved assets found for this project.\n";
    echo "Either:\n";
    echo "  1. Generate creative:  php bin/generate-creative.php image \"<prompt>\"\n";
    echo "  2. Add this flag:      --auto-generate-creative\n";
    echo "  3. Approve existing:   Use the review dashboard at http://localhost:8080/?project={$project['name']}\n";
    exit(1);
}

// 6. Create the ad on the target platform
$budget = loadBudget($project['id'], $platform);

echo "\nCreating {$platform} ad with " . count($approvedAssets) . " approved asset(s)...\n";
echo "Budget: \${$budget}/day\n\n";

try {
    if ($platform === 'google') {
        createGoogleAd($project, $strategy, $approvedAssets, $budget);
    } else {
        createMetaAd($project, $strategy, $approvedAssets, $budget);
    }
} catch (\Exception $e) {
    echo "Error: {$e->getMessage()}\n";
    if (getenv('ADMANAGER_DEBUG')) {
        echo "\nStack trace:\n{$e->getTraceAsString()}\n";
    }
    exit(1);
}
