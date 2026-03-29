#!/usr/bin/env php
<?php
/**
 * Sync Google Ads search terms into the local search_terms table.
 *
 * Usage:
 *   php bin/sync-search-terms.php --project <name> [--days 7]
 *
 * Fetches search_term_view data from the Google Ads API and upserts rows into
 * the search_terms table keyed on (ad_group_id, search_term, date).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Google\Client;
use AdManager\Google\Reports as GoogleReports;

// ── Arg parsing ───────────────────────────────────────────────────────────────

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
    echo "  php bin/sync-search-terms.php --project <name> [--days 7]\n";
    echo "\nOptions:\n";
    echo "  --project   Project name (required)\n";
    echo "  --days      Number of days to fetch (default: 7)\n";
    exit(1);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function daysToGoogleDateRange(int $days): string
{
    return match (true) {
        $days <= 7  => 'LAST_7_DAYS',
        $days <= 14 => 'LAST_14_DAYS',
        $days <= 30 => 'LAST_30_DAYS',
        default     => 'LAST_30_DAYS',
    };
}

/**
 * Upsert a single search term row.
 * Uses INSERT OR REPLACE — the UNIQUE(ad_group_id, search_term, date) constraint
 * handles deduplication.
 */
function upsertSearchTerm(
    int    $projectId,
    ?int   $campaignId,
    ?int   $adGroupId,
    string $searchTerm,
    ?string $matchType,
    string $date,
    array  $metrics
): void {
    $db   = DB::get();
    $stmt = $db->prepare(<<<'SQL'
        INSERT OR REPLACE INTO search_terms
            (project_id, campaign_id, ad_group_id, search_term, match_type,
             impressions, clicks, cost_micros, conversions, conversion_value, date, synced_at)
        VALUES
            (:project_id, :campaign_id, :ad_group_id, :search_term, :match_type,
             :impressions, :clicks, :cost_micros, :conversions, :conversion_value, :date, datetime('now'))
    SQL);

    $stmt->execute([
        ':project_id'       => $projectId,
        ':campaign_id'      => $campaignId,
        ':ad_group_id'      => $adGroupId,
        ':search_term'      => $searchTerm,
        ':match_type'       => $matchType,
        ':impressions'      => $metrics['impressions']      ?? 0,
        ':clicks'           => $metrics['clicks']           ?? 0,
        ':cost_micros'      => $metrics['cost_micros']      ?? 0,
        ':conversions'      => $metrics['conversions']      ?? 0,
        ':conversion_value' => $metrics['conversion_value'] ?? 0,
        ':date'             => $date,
    ]);
}

// ── Google sync ───────────────────────────────────────────────────────────────

/**
 * Sync search terms for a project's Google campaigns.
 *
 * @param array  $campaigns  Rows from the campaigns table (project's google campaigns)
 * @param int    $projectId
 * @param string $dateRange  GAQL date range string
 * @return int   Number of rows upserted
 */
function syncSearchTerms(array $campaigns, int $projectId, string $dateRange): int
{
    $reports = new GoogleReports();
    $rows    = $reports->searchTerms($dateRange);
    $synced  = 0;

    // Build lookup: Google external campaign ID → local campaign ID
    $extToLocal = [];
    foreach ($campaigns as $c) {
        if ($c['external_id']) {
            $extToLocal[$c['external_id']] = $c['id'];
        }
    }

    // Build ad group lookup: Google external ad group ID → local ad group ID
    $db           = DB::get();
    $adGroupLocal = [];
    foreach ($campaigns as $c) {
        $agStmt = $db->prepare('SELECT id, external_id FROM ad_groups WHERE campaign_id = :cid');
        $agStmt->execute([':cid' => $c['id']]);
        foreach ($agStmt->fetchAll() as $ag) {
            if ($ag['external_id']) {
                $adGroupLocal[$ag['external_id']] = $ag['id'];
            }
        }
    }

    foreach ($rows as $row) {
        // Resolve campaign
        $gCampaignId  = (string) $row->getCampaign()->getId();
        $localCampId  = $extToLocal[$gCampaignId] ?? null;

        // Resolve ad group
        $gAdGroupId   = (string) $row->getAdGroup()->getId();
        $localAgId    = $adGroupLocal[$gAdGroupId] ?? null;

        // Skip if we don't recognise the campaign — it's from a different account segment
        if ($localCampId === null) {
            continue;
        }

        $stv        = $row->getSearchTermView();
        $searchTerm = $stv->getSearchTerm();
        $matchType  = $stv->getStatus() ? (string) $stv->getStatus() : null;

        $m    = $row->getMetrics();
        $date = date('Y-m-d'); // Google DURING ranges don't segment by day without segments.date

        upsertSearchTerm(
            $projectId,
            $localCampId,
            $localAgId,
            $searchTerm,
            $matchType,
            $date,
            [
                'impressions'      => $m->getImpressions(),
                'clicks'           => $m->getClicks(),
                'cost_micros'      => $m->getCostMicros(),
                'conversions'      => $m->getConversions(),
                'conversion_value' => 0,
            ]
        );
        $synced++;
    }

    return $synced;
}

// ── Main ──────────────────────────────────────────────────────────────────────

DB::init();

$args = parseArgs($argv);

$projectName = $args['project'] ?? null;
$days        = (int) ($args['days'] ?? 7);

if (!$projectName) {
    usage();
}

$db = DB::get();

// Load project
$stmt = $db->prepare('SELECT * FROM projects WHERE name = :name');
$stmt->execute([':name' => $projectName]);
$project = $stmt->fetch();

if (!$project) {
    echo "Error: project '{$projectName}' not found.\n";
    exit(1);
}

echo "\n=== Sync Search Terms — {$project['name']} ===\n\n";
echo "Days:      {$days}\n";

$dateRange = daysToGoogleDateRange($days);
echo "Range:     {$dateRange}\n\n";

// Load Google campaigns for this project
$campStmt = $db->prepare(
    'SELECT * FROM campaigns WHERE project_id = :pid AND platform = :plat'
);
$campStmt->execute([':pid' => $project['id'], ':plat' => 'google']);
$googleCampaigns = $campStmt->fetchAll();

if (empty($googleCampaigns)) {
    echo "No Google campaigns found for project '{$projectName}'.\n";
    exit(0);
}

echo "Campaigns: " . count($googleCampaigns) . "\n\n";

try {
    Client::boot();
    $synced = syncSearchTerms($googleCampaigns, (int) $project['id'], $dateRange);
    echo "Synced: {$synced} search term rows\n";
} catch (\Exception $e) {
    echo "Error: {$e->getMessage()}\n";
    exit(1);
}

echo "\n=== Done ===\n";
