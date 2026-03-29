#!/usr/bin/env php
<?php
/**
 * Sync GA4 landing-page performance into the local ga4_performance table.
 *
 * Usage:
 *   php bin/sync-ga4.php --project <name> [--days 7]
 *
 * Pulls GA4::landingPagePerformance() and GA4::conversionValidation() then
 * upserts into ga4_performance, keyed on project_id + landing_page + source
 * + medium + campaign_name + date.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Google\GA4;

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
    echo "  php bin/sync-ga4.php --project <name> [--days 7]\n";
    echo "\nOptions:\n";
    echo "  --project   Project name (required)\n";
    echo "  --days      Number of past days to pull (default: 7)\n";
    exit(1);
}

// ── Upsert ───────────────────────────────────────────────────────────────────

function upsertGA4Row(int $projectId, array $row, string $date): void
{
    $db = DB::get();

    // Delete any existing row for this composite key before inserting
    $delStmt = $db->prepare(<<<'SQL'
        DELETE FROM ga4_performance
        WHERE project_id    = :project_id
          AND COALESCE(landing_page,   '') = COALESCE(:landing_page,   '')
          AND COALESCE(source,         '') = COALESCE(:source,         '')
          AND COALESCE(medium,         '') = COALESCE(:medium,         '')
          AND COALESCE(campaign_name,  '') = COALESCE(:campaign_name,  '')
          AND date = :date
    SQL);

    $delStmt->execute([
        ':project_id'   => $projectId,
        ':landing_page' => $row['landing_page'] ?? null,
        ':source'       => $row['source']       ?? null,
        ':medium'       => $row['medium']       ?? null,
        ':campaign_name'=> $row['campaign_name']?? null,
        ':date'         => $date,
    ]);

    $insStmt = $db->prepare(<<<'SQL'
        INSERT INTO ga4_performance
            (project_id, landing_page, source, medium, campaign_name,
             sessions, bounce_rate, avg_session_duration, conversions, revenue, date, synced_at)
        VALUES
            (:project_id, :landing_page, :source, :medium, :campaign_name,
             :sessions, :bounce_rate, :avg_session_duration, :conversions, :revenue, :date, datetime('now'))
    SQL);

    $insStmt->execute([
        ':project_id'          => $projectId,
        ':landing_page'        => $row['landing_page']         ?? null,
        ':source'              => $row['source']               ?? null,
        ':medium'              => $row['medium']               ?? null,
        ':campaign_name'       => $row['campaign_name']        ?? null,
        ':sessions'            => (int)   ($row['sessions']            ?? 0),
        ':bounce_rate'         => isset($row['bounce_rate'])         ? (float) $row['bounce_rate']         : null,
        ':avg_session_duration'=> isset($row['avg_session_duration']) ? (float) $row['avg_session_duration'] : null,
        ':conversions'         => (float) ($row['conversions']        ?? 0),
        ':revenue'             => (float) ($row['revenue']            ?? 0),
        ':date'                => $date,
    ]);
}

// ── Main ─────────────────────────────────────────────────────────────────────

DB::init();

$args        = parseArgs($argv);
$projectName = $args['project'] ?? null;
$days        = (int) ($args['days'] ?? 7);

if (!$projectName) {
    usage();
}

$db   = DB::get();
$stmt = $db->prepare('SELECT * FROM projects WHERE name = :name');
$stmt->execute([':name' => $projectName]);
$project = $stmt->fetch();

if (!$project) {
    echo "Error: project '{$projectName}' not found.\n";
    exit(1);
}

$projectId = (int) $project['id'];

echo "\n=== Sync GA4 — {$project['name']} ===\n\n";
echo "Days: {$days}\n\n";

$endDate   = date('Y-m-d');
$startDate = date('Y-m-d', strtotime("-{$days} days"));

echo "Date range: {$startDate} → {$endDate}\n\n";

try {
    $ga4 = new GA4();
} catch (\Throwable $e) {
    echo "Error: failed to initialise GA4 client: {$e->getMessage()}\n";
    exit(1);
}

// ── Landing-page performance ──────────────────────────────────────────────────

echo "Fetching landing page performance...\n";

try {
    $landingRows = $ga4->landingPagePerformance($startDate, $endDate);
    echo "  " . count($landingRows) . " rows returned.\n";

    $synced = 0;
    foreach ($landingRows as $row) {
        upsertGA4Row($projectId, [
            'landing_page'         => $row['landingPage']              ?? null,
            'source'               => $row['sessionSource']            ?? null,
            'medium'               => $row['sessionMedium']            ?? null,
            'campaign_name'        => $row['sessionCampaignName']      ?? null,
            'sessions'             => $row['sessions']                 ?? 0,
            'bounce_rate'          => $row['bounceRate']               ?? null,
            'avg_session_duration' => $row['averageSessionDuration']   ?? null,
            'conversions'          => $row['conversions']              ?? 0,
            'revenue'              => $row['purchaseRevenue']          ?? 0,
        ], $endDate);
        $synced++;
    }

    echo "  Upserted: {$synced} rows.\n\n";
} catch (\Throwable $e) {
    echo "  Error fetching landing page performance: {$e->getMessage()}\n\n";
}

// ── Conversion validation totals ────────────────────────────────────────────

echo "Fetching conversion validation totals...\n";

try {
    $validation = $ga4->conversionValidation($startDate, $endDate);
    echo "  Total conversions : {$validation['total_conversions']}\n";
    echo "  Total revenue     : {$validation['total_revenue']}\n\n";
} catch (\Throwable $e) {
    echo "  Error fetching conversion validation: {$e->getMessage()}\n\n";
}

echo "=== Done ===\n";
