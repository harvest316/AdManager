#!/usr/bin/env php
<?php
/**
 * Ad copy generation CLI.
 *
 * Generates fresh ad copy variants using COPY.md prompt template, then runs
 * them through the full proofreading pipeline (programmatic + Opus).
 *
 * Use cases:
 * - Generate initial copy for a new campaign (instead of extracting from strategy)
 * - Generate replacement headlines for underperforming ones (split testing)
 * - Generate Meta primary text variants
 *
 * Usage:
 *   php bin/generate-copy.php --project <name> --platform google|meta --type search|display|traffic|conversions [options]
 *   php bin/generate-copy.php --project <name> --replace-weak --campaign <name> [--count N]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Copy\Generator;
use AdManager\Copy\Store;
use AdManager\Copy\ProgrammaticCheck;
use AdManager\Copy\Proofreader;

function parseArgs(array $argv): array
{
    $named = [];
    for ($i = 1; $i < count($argv); $i++) {
        if (str_starts_with($argv[$i], '--')) {
            $key = substr($argv[$i], 2);
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

$args = parseArgs($argv);

if (!isset($args['project'])) {
    echo <<<USAGE
Usage:
  php bin/generate-copy.php --project <name> --platform google|meta --type search [options]
  php bin/generate-copy.php --project <name> --replace-weak --campaign <name>

Generate fresh copy:
  --project       Project name
  --platform      Target platform: google or meta
  --type          Campaign type: search, display, pmax, traffic, conversions
  --strategy      Strategy ID (loads target_audience + value_proposition from it)
  --market        Target market (AU, US, GB) for locale
  --campaign      Campaign name to assign copy to
  --skip-proofread  Skip the proofreading step

Replace weak headlines:
  --project       Project name
  --replace-weak  Enable replacement mode
  --campaign      Campaign name with underperforming headlines
  --count         Number of replacements (default: all weak ones)

USAGE;
    exit(1);
}

DB::init();
$db = DB::get();

// Load project
$stmt = $db->prepare('SELECT * FROM projects WHERE name = ?');
$stmt->execute([$args['project']]);
$project = $stmt->fetch();
if (!$project) {
    echo "Error: project '{$args['project']}' not found.\n";
    exit(1);
}

$store = new Store();
$generator = new Generator();
$market = $args['market'] ?? 'all';

// Load strategy context if provided
$strategy = null;
$options = ['market' => $market];
if (isset($args['strategy'])) {
    $stmt = $db->prepare('SELECT * FROM strategies WHERE id = ?');
    $stmt->execute([(int) $args['strategy']]);
    $strategy = $stmt->fetch();
    if ($strategy) {
        $options['target_audience'] = $strategy['target_audience'] ?? '';
        $options['value_proposition'] = $strategy['value_proposition'] ?? '';
    }
}

echo "Project: " . ($project['display_name'] ?? $project['name']) . "\n";

// ── Replace weak headlines mode ──────────────────────────────────────────────

if (isset($args['replace-weak'])) {
    $campaignName = $args['campaign'] ?? null;
    if (!$campaignName) {
        echo "Error: --campaign required for --replace-weak mode.\n";
        exit(1);
    }

    echo "Mode: Replace weak headlines for campaign '{$campaignName}'\n\n";

    // Get all headlines for this campaign
    $headlines = $store->getByCampaign((int) $project['id'], $campaignName);
    $headlines = array_filter($headlines, fn($h) => $h['copy_type'] === 'headline');

    if (empty($headlines)) {
        echo "Error: no headlines found for campaign '{$campaignName}'.\n";
        exit(1);
    }

    // In a real scenario, weak/strong would come from Google Ads API performance data.
    // For now, use QA score as a proxy: score < 70 = weak, >= 70 = strong.
    $weak = array_filter($headlines, fn($h) => ($h['qa_score'] ?? 100) < 70);
    $strong = array_filter($headlines, fn($h) => ($h['qa_score'] ?? 0) >= 70);

    if (isset($args['count'])) {
        $weak = array_slice($weak, 0, (int) $args['count']);
    }

    if (empty($weak)) {
        echo "No weak headlines found (all scoring >= 70).\n";
        exit(0);
    }

    echo "Found " . count($weak) . " weak headlines, " . count($strong) . " strong.\n";
    echo "Generating " . count($weak) . " replacements...\n";

    $replacements = $generator->generateReplacements(
        $project, array_values($weak), array_values($strong), $campaignName, $options
    );

    if (empty($replacements)) {
        echo "Error: generation failed.\n";
        exit(1);
    }

    echo "Generated " . count($replacements) . " replacement headlines.\n";

    // Assign campaign name
    foreach ($replacements as &$r) {
        $r['campaign_name'] = $campaignName;
    }
    unset($r);

    // Insert + proofread
    $strategyId = $strategy ? (int) $strategy['id'] : 0;
    $ids = $store->bulkInsert((int) $project['id'], $strategyId, $replacements);
    echo "Inserted " . count($ids) . " new headlines.\n\n";

    runProofread($store, $project, $strategy, $market, $ids);
    exit(0);
}

// ── Generate fresh copy mode ─────────────────────────────────────────────────

$platform = $args['platform'] ?? 'google';
$campaignType = $args['type'] ?? 'search';
$campaignName = $args['campaign'] ?? null;

echo "Platform: {$platform}, Type: {$campaignType}\n";
echo "Generating copy...\n\n";

$items = $generator->generate($project, $platform, $campaignType, $options);

if (empty($items)) {
    echo "Error: no copy items generated.\n";
    exit(1);
}

echo "Generated " . count($items) . " copy items:\n";
$typeCounts = [];
foreach ($items as &$item) {
    $item['campaign_name'] = $campaignName;
    $key = $item['copy_type'];
    $typeCounts[$key] = ($typeCounts[$key] ?? 0) + 1;
}
unset($item);
foreach ($typeCounts as $type => $count) {
    echo "  {$type}: {$count}\n";
}

// Insert into DB
$strategyId = $strategy ? (int) $strategy['id'] : 0;
$ids = $store->bulkInsert((int) $project['id'], $strategyId, $items);
echo "\nInserted " . count($ids) . " rows into ad_copy.\n\n";

if (isset($args['skip-proofread'])) {
    echo "Skipping proofreading (--skip-proofread).\n";
    exit(0);
}

runProofread($store, $project, $strategy, $market, $ids);

// ── Shared proofread function ────────────────────────────────────────────────

function runProofread(Store $store, array $project, ?array $strategy, string $market, array $ids): void
{
    // Reload the inserted items
    $items = [];
    foreach ($ids as $id) {
        $item = $store->getById($id);
        if ($item) $items[] = $item;
    }

    echo "Running programmatic checks...\n";
    $checker = new ProgrammaticCheck();
    $brandName = $project['display_name'] ?? $project['name'];
    $results = $checker->checkAll($items, $brandName, $market);

    $passCount = 0;
    $warnCount = 0;
    $failCount = 0;

    foreach ($results as $id => $result) {
        $status = ProgrammaticCheck::overallStatus($result['issues']);
        $store->updateQA($id, $status, $result['issues']);
        if ($status === 'fail') { $store->setStatus($id, 'draft'); $failCount++; }
        elseif ($status === 'warning') { $warnCount++; }
        else { $passCount++; }
    }

    echo "  Pass: {$passCount}  Warnings: {$warnCount}  Fails: {$failCount}\n\n";

    // LLM proofread items that didn't hard-fail
    $llmItems = array_filter($items, fn($i) => ProgrammaticCheck::overallStatus($results[$i['id']]['issues'] ?? []) !== 'fail');

    if (empty($llmItems)) {
        echo "All items failed programmatic checks.\n";
        return;
    }

    echo "Sending " . count($llmItems) . " items to Opus for proofreading...\n";
    $proofreader = new Proofreader();
    $mockStrategy = $strategy ?? ['target_audience' => '', 'value_proposition' => ''];
    $llmResult = $proofreader->proofread(array_values($llmItems), $project, $mockStrategy, $market);

    if ($llmResult === null) {
        echo "  LLM proofreading failed. Items left as 'proofread'.\n";
        foreach ($llmItems as $item) $store->setStatus($item['id'], 'proofread');
        return;
    }

    echo "  Overall score: {$llmResult['overall_score']}\n";
    $approved = 0;
    $review = 0;
    $rework = 0;

    foreach ($llmResult['items'] as $ir) {
        $id = $ir['id'];
        $allIssues = array_merge($results[$id]['issues'] ?? [], $ir['issues'] ?? []);
        $store->updateQA($id, $ir['verdict'], $allIssues, $ir['score'] ?? null);
        $score = $ir['score'] ?? 0;
        $hasFails = !empty(array_filter($allIssues, fn($i) => ($i['severity'] ?? '') === 'fail'));
        if ($score >= 70 && !$hasFails) { $store->approve($id); $approved++; }
        elseif ($score >= 50) { $store->setStatus($id, 'proofread'); $review++; }
        else { $store->setStatus($id, 'draft'); $rework++; }
    }

    echo "  Auto-approved: {$approved}  Needs review: {$review}  Needs rework: {$rework}\n";
}
