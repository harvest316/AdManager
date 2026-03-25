#!/usr/bin/env php
<?php
/**
 * Sync performance data from Google Ads and/or Meta into the local DB.
 *
 * Usage:
 *   php bin/sync-performance.php --project <name> [--days 7] [--platform google|meta|all]
 *
 * Pulls campaign/ad group/ad performance metrics and UPSERTs into the
 * performance table, keyed on campaign_id + ad_group_id + ad_id + date.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Google\Client;
use AdManager\Google\Reports as GoogleReports;
use AdManager\Meta\Reports as MetaReports;

// ── Arg parsing ──────────────────────────────────────────────────────────────

function parseArgs(array $argv): array
{
    $named = [];
    for ($i = 1; $i < count($argv); $i++) {
        if (str_starts_with($argv[$i], '--')) {
            $key = substr($argv[$i], 2);
            if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                $named[$key] = $argv[$i + 1];
                $i++;
            } else {
                $named[$key] = true;
            }
        }
    }
    return $named;
}

function usage(): never
{
    echo "Usage:\n";
    echo "  php bin/sync-performance.php --project <name> [--days 7] [--platform google|meta|all]\n";
    echo "\nOptions:\n";
    echo "  --project   Project name (required)\n";
    echo "  --days      Number of days to sync (default: 7)\n";
    echo "  --platform  google, meta, or all (default: all)\n";
    exit(1);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function upsertPerformance(int $campaignId, ?int $adGroupId, ?int $adId, string $date, array $metrics): void
{
    $db = DB::get();

    // Delete-then-insert pattern: the unique index uses COALESCE for nullable cols,
    // which ON CONFLICT can't reference directly.
    $delStmt = $db->prepare(<<<'SQL'
        DELETE FROM performance
        WHERE campaign_id = :campaign_id
          AND COALESCE(ad_group_id, 0) = COALESCE(:ad_group_id, 0)
          AND COALESCE(ad_id, 0) = COALESCE(:ad_id, 0)
          AND date = :date
    SQL);
    $delStmt->execute([
        ':campaign_id' => $campaignId,
        ':ad_group_id' => $adGroupId,
        ':ad_id'       => $adId,
        ':date'        => $date,
    ]);

    $stmt = $db->prepare(<<<'SQL'
        INSERT INTO performance (campaign_id, ad_group_id, ad_id, date, impressions, clicks, cost_micros, conversions, conversion_value, created_at)
        VALUES (:campaign_id, :ad_group_id, :ad_id, :date, :impressions, :clicks, :cost_micros, :conversions, :conversion_value, datetime('now'))
    SQL);

    $stmt->execute([
        ':campaign_id'      => $campaignId,
        ':ad_group_id'      => $adGroupId,
        ':ad_id'            => $adId,
        ':date'             => $date,
        ':impressions'      => $metrics['impressions'] ?? 0,
        ':clicks'           => $metrics['clicks'] ?? 0,
        ':cost_micros'      => $metrics['cost_micros'] ?? 0,
        ':conversions'      => $metrics['conversions'] ?? 0,
        ':conversion_value' => $metrics['conversion_value'] ?? 0,
    ]);
}

function daysToGoogleDateRange(int $days): string
{
    return match (true) {
        $days <= 7  => 'LAST_7_DAYS',
        $days <= 14 => 'LAST_14_DAYS',
        $days <= 30 => 'LAST_30_DAYS',
        default     => 'LAST_30_DAYS',
    };
}

function daysToMetaDatePreset(int $days): string
{
    return match (true) {
        $days <= 7  => 'last_7d',
        $days <= 14 => 'last_14d',
        $days <= 30 => 'last_30d',
        default     => 'last_30d',
    };
}

// ── Google sync ──────────────────────────────────────────────────────────────

function syncGoogle(array $campaigns, int $days): int
{
    Client::boot();
    $reports   = new GoogleReports();
    $dateRange = daysToGoogleDateRange($days);
    $synced    = 0;

    echo "  Date range: {$dateRange}\n";

    // Campaign-level performance
    echo "  Fetching campaign performance...\n";
    $rows = $reports->campaigns($dateRange);

    // Build lookup: Google external ID -> local campaign ID
    $extToLocal = [];
    foreach ($campaigns as $c) {
        if ($c['external_id']) {
            $extToLocal[$c['external_id']] = $c['id'];
        }
    }

    foreach ($rows as $row) {
        $gCampaignId = (string) $row->getCampaign()->getId();
        $localId     = $extToLocal[$gCampaignId] ?? null;

        if ($localId === null) continue;

        $m = $row->getMetrics();
        // Google doesn't break campaign-level by date with DURING — use today as date
        // For proper date breakdown, we'd need segments.date in the query
        $date = date('Y-m-d');

        upsertPerformance($localId, null, null, $date, [
            'impressions'      => $m->getImpressions(),
            'clicks'           => $m->getClicks(),
            'cost_micros'      => $m->getCostMicros(),
            'conversions'      => $m->getConversions(),
            'conversion_value' => 0,
        ]);
        $synced++;
    }

    // Ad-level performance
    echo "  Fetching ad performance...\n";
    $adRows = $reports->ads($dateRange);

    // Build ad group lookup
    $db = DB::get();
    $adGroupLookup = [];
    $adLookup      = [];

    foreach ($campaigns as $c) {
        $agStmt = $db->prepare('SELECT id, external_id FROM ad_groups WHERE campaign_id = :cid');
        $agStmt->execute([':cid' => $c['id']]);
        foreach ($agStmt->fetchAll() as $ag) {
            if ($ag['external_id']) {
                $adGroupLookup[$ag['external_id']] = $ag['id'];
            }

            $adStmt = $db->prepare('SELECT id, external_id FROM ads WHERE ad_group_id = :agid');
            $adStmt->execute([':agid' => $ag['id']]);
            foreach ($adStmt->fetchAll() as $ad) {
                if ($ad['external_id']) {
                    $adLookup[$ad['external_id']] = ['ad_id' => $ad['id'], 'ag_id' => $ag['id'], 'campaign_id' => $c['id']];
                }
            }
        }
    }

    foreach ($adRows as $row) {
        $gAdId = (string) $row->getAdGroupAd()->getAd()->getId();
        $info  = $adLookup[$gAdId] ?? null;
        if ($info === null) continue;

        $m    = $row->getMetrics();
        $date = date('Y-m-d');

        upsertPerformance($info['campaign_id'], $info['ag_id'], $info['ad_id'], $date, [
            'impressions'      => $m->getImpressions(),
            'clicks'           => $m->getClicks(),
            'cost_micros'      => $m->getCostMicros(),
            'conversions'      => $m->getConversions(),
            'conversion_value' => 0,
        ]);
        $synced++;
    }

    return $synced;
}

// ── Meta sync ────────────────────────────────────────────────────────────────

function syncMeta(array $campaigns, int $days): int
{
    $reports    = new MetaReports();
    $datePreset = daysToMetaDatePreset($days);
    $synced     = 0;

    echo "  Date preset: {$datePreset}\n";

    foreach ($campaigns as $c) {
        if (empty($c['external_id'])) continue;

        echo "  Fetching insights for campaign {$c['external_id']}...\n";

        try {
            $insights = $reports->campaignInsights($c['external_id'], $datePreset);
        } catch (\Exception $e) {
            echo "    Warning: {$e->getMessage()}\n";
            continue;
        }

        foreach ($insights as $insight) {
            $date = $insight['date_start'] ?? date('Y-m-d');

            // Extract conversions from actions array
            $conversions     = 0;
            $conversionValue = 0;
            if (!empty($insight['actions'])) {
                foreach ($insight['actions'] as $action) {
                    if (in_array($action['action_type'] ?? '', ['offsite_conversion', 'lead', 'purchase'])) {
                        $conversions += (float) ($action['value'] ?? 0);
                    }
                }
            }

            // Meta spend is in account currency (dollars), convert to micros
            $spendMicros = (int) (((float) ($insight['spend'] ?? 0)) * 1_000_000);

            upsertPerformance($c['id'], null, null, $date, [
                'impressions'      => (int) ($insight['impressions'] ?? 0),
                'clicks'           => (int) ($insight['clicks'] ?? 0),
                'cost_micros'      => $spendMicros,
                'conversions'      => $conversions,
                'conversion_value' => $conversionValue,
            ]);
            $synced++;
        }

        // Ad-level insights
        $db = DB::get();
        $agStmt = $db->prepare('SELECT id, external_id FROM ad_groups WHERE campaign_id = :cid');
        $agStmt->execute([':cid' => $c['id']]);

        foreach ($agStmt->fetchAll() as $ag) {
            if (empty($ag['external_id'])) continue;

            // Meta ad sets = ad groups in our schema
            try {
                $adSetInsights = $reports->adSetInsights($ag['external_id'], $datePreset);
            } catch (\Exception $e) {
                continue;
            }

            foreach ($adSetInsights as $insight) {
                $date        = $insight['date_start'] ?? date('Y-m-d');
                $spendMicros = (int) (((float) ($insight['spend'] ?? 0)) * 1_000_000);

                upsertPerformance($c['id'], $ag['id'], null, $date, [
                    'impressions'      => (int) ($insight['impressions'] ?? 0),
                    'clicks'           => (int) ($insight['clicks'] ?? 0),
                    'cost_micros'      => $spendMicros,
                    'conversions'      => 0,
                    'conversion_value' => 0,
                ]);
                $synced++;
            }
        }
    }

    return $synced;
}

// ── Main ─────────────────────────────────────────────────────────────────────

DB::init();

$args = parseArgs($argv);

$projectName = $args['project'] ?? null;
$days        = (int) ($args['days'] ?? 7);
$platform    = strtolower($args['platform'] ?? 'all');

if (!$projectName) {
    usage();
}

if (!in_array($platform, ['google', 'meta', 'all'])) {
    echo "Error: platform must be 'google', 'meta', or 'all'.\n";
    usage();
}

// Ensure the performance table has a unique constraint for upsert.
// COALESCE NULLs to 0 so the index works (SQLite treats NULL != NULL).
$db = DB::get();
$db->exec(<<<'SQL'
    CREATE UNIQUE INDEX IF NOT EXISTS idx_performance_upsert
    ON performance (campaign_id, COALESCE(ad_group_id, 0), COALESCE(ad_id, 0), date)
SQL);

// Load project
$stmt = $db->prepare('SELECT * FROM projects WHERE name = :name');
$stmt->execute([':name' => $projectName]);
$project = $stmt->fetch();

if (!$project) {
    echo "Error: project '{$projectName}' not found.\n";
    exit(1);
}

echo "\n=== Sync Performance — {$project['name']} ===\n\n";
echo "Days:     {$days}\n";
echo "Platform: {$platform}\n\n";

// Load campaigns for this project
$campStmt = $db->prepare('SELECT * FROM campaigns WHERE project_id = :pid');
$campStmt->execute([':pid' => $project['id']]);
$allCampaigns = $campStmt->fetchAll();

if (empty($allCampaigns)) {
    echo "No campaigns found for project '{$projectName}'.\n";
    exit(0);
}

$totalSynced = 0;

// Google sync
if ($platform === 'google' || $platform === 'all') {
    $googleCampaigns = array_filter($allCampaigns, fn($c) => $c['platform'] === 'google');
    if (!empty($googleCampaigns)) {
        echo "--- Google Ads ---\n";
        echo "  Campaigns: " . count($googleCampaigns) . "\n";
        try {
            $synced = syncGoogle($googleCampaigns, $days);
            echo "  Synced: {$synced} rows\n\n";
            $totalSynced += $synced;
        } catch (\Exception $e) {
            echo "  Error: {$e->getMessage()}\n\n";
        }
    } else {
        echo "No Google campaigns found.\n\n";
    }
}

// Meta sync
if ($platform === 'meta' || $platform === 'all') {
    $metaCampaigns = array_filter($allCampaigns, fn($c) => $c['platform'] === 'meta');
    if (!empty($metaCampaigns)) {
        echo "--- Meta Ads ---\n";
        echo "  Campaigns: " . count($metaCampaigns) . "\n";
        try {
            $synced = syncMeta(array_values($metaCampaigns), $days);
            echo "  Synced: {$synced} rows\n\n";
            $totalSynced += $synced;
        } catch (\Exception $e) {
            echo "  Error: {$e->getMessage()}\n\n";
        }
    } else {
        echo "No Meta campaigns found.\n\n";
    }
}

echo "=== Total: {$totalSynced} rows synced ===\n";
