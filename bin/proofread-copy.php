#!/usr/bin/env php
<?php
/**
 * Ad copy proofreading pipeline.
 *
 * Extracts copy from a strategy, runs programmatic checks + Opus proofreading,
 * and auto-approves items scoring >= 70. Items needing attention appear in the
 * review dashboard.
 *
 * Usage:
 *   php bin/proofread-copy.php --project <name> --strategy <id> [--force] [--skip-llm] [--market <code>]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Copy\Parser;
use AdManager\Copy\Store;
use AdManager\Copy\ProgrammaticCheck;
use AdManager\Copy\Proofreader;

// ── Arg parsing ──────────────────────────────────────────────────────────────

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

if (!isset($args['project']) || !isset($args['strategy'])) {
    echo <<<USAGE
Usage:
  php bin/proofread-copy.php --project <name> --strategy <id> [--force] [--skip-llm] [--market <code>]

Options:
  --project    Project name (as stored in DB)
  --strategy   Strategy ID from the strategies table
  --force      Re-import copy even if already imported for this strategy
  --skip-llm   Run programmatic checks only (skip Opus proofreading)
  --market     Target market code for locale checks (AU, US, GB, etc.)

Flow:
  1. Parses strategy markdown to extract ad copy (headlines, descriptions, Meta text)
  2. Inserts into ad_copy table
  3. Runs 15 programmatic validation rules
  4. Runs Opus LLM proofreading for sales effectiveness + policy compliance
  5. Auto-approves items scoring >= 70 with no programmatic fails
  6. Prints summary and directs to review dashboard

USAGE;
    exit(1);
}

$projectName = $args['project'];
$strategyId = (int) $args['strategy'];
$force = isset($args['force']);
$jobId = isset($args['job-id']) ? (int) $args['job-id'] : null;
$skipLLM = isset($args['skip-llm']);
$market = $args['market'] ?? 'all';

// ── Load project and strategy ────────────────────────────────────────────────

$db = DB::get();

$stmt = $db->prepare('SELECT * FROM projects WHERE name = ?');
$stmt->execute([$projectName]);
$project = $stmt->fetch();
if (!$project) {
    echo "Error: project '{$projectName}' not found.\n";
    exit(1);
}

$stmt = $db->prepare('SELECT * FROM strategies WHERE id = ?');
$stmt->execute([$strategyId]);
$strategy = $stmt->fetch();
if (!$strategy) {
    echo "Error: strategy #{$strategyId} not found.\n";
    exit(1);
}

echo "Project: {$project['display_name']} ({$project['name']})\n";
echo "Strategy: #{$strategy['id']} — {$strategy['name']}\n";
echo "Market: {$market}\n\n";

// ── Step 1: Parse strategy and extract copy ──────────────────────────────────

$store = new Store();

if ($store->existsForStrategy($strategyId) && !$force) {
    echo "Copy already imported for strategy #{$strategyId}. Use --force to re-import.\n";
    $items = $store->listByProject((int) $project['id']);
    echo "Existing items: " . count($items) . "\n\n";
} else {
    if ($force && $store->existsForStrategy($strategyId)) {
        $deleted = $store->deleteForStrategy($strategyId);
        echo "Deleted {$deleted} existing copy items (--force).\n";
    }

    echo "Parsing strategy markdown...\n";
    $parser = new Parser();
    $parsed = $parser->parse($strategy['full_strategy']);
    echo "  Extracted " . count($parsed) . " copy items\n";

    if (empty($parsed)) {
        echo "Error: no ad copy found in strategy markdown.\n";
        echo "Check that the strategy contains a '## 8. Ad Copy' section.\n";
        exit(1);
    }

    // Count by type
    $typeCounts = [];
    foreach ($parsed as $item) {
        $key = $item['platform'] . '/' . $item['copy_type'];
        $typeCounts[$key] = ($typeCounts[$key] ?? 0) + 1;
    }
    foreach ($typeCounts as $type => $count) {
        echo "    {$type}: {$count}\n";
    }

    echo "\nInserting into ad_copy table...\n";
    $ids = $store->bulkInsert((int) $project['id'], $strategyId, $parsed);
    echo "  Inserted " . count($ids) . " rows\n\n";

    $items = $store->listByProject((int) $project['id']);
}

// Filter to only items from this strategy that need checking
$items = array_filter($items, fn($i) => (int) $i['strategy_id'] === $strategyId);
$items = array_values($items);

if (empty($items)) {
    echo "No copy items to check.\n";
    exit(0);
}

// ── Step 2: Programmatic checks ──────────────────────────────────────────────

echo "Running programmatic checks (" . count($items) . " items)...\n";
$checker = new ProgrammaticCheck();
$brandName = $project['display_name'] ?? $project['name'];
$checkResults = $checker->checkAll($items, $brandName, $market);

$progFails = 0;
$progWarnings = 0;
$progPasses = 0;

foreach ($checkResults as $id => $result) {
    $status = ProgrammaticCheck::overallStatus($result['issues']);

    // Apply auto-fixes (whitespace trimming)
    foreach ($result['auto_fixed'] as $fixed) {
        if ($fixed !== null) {
            $db->prepare("UPDATE ad_copy SET content = ?, updated_at = datetime('now') WHERE id = ?")
               ->execute([$fixed, $id]);
        }
    }

    // Update QA status from programmatic checks
    $store->updateQA($id, $status, $result['issues']);

    if ($status === 'fail') {
        $store->setStatus($id, 'draft');
        $progFails++;
    } elseif ($status === 'warning') {
        $progWarnings++;
    } else {
        $progPasses++;
    }
}

echo "  Pass: {$progPasses}  Warnings: {$progWarnings}  Fails: {$progFails}\n\n";

// ── Step 3: LLM proofreading (items that passed programmatic checks) ────────

if ($skipLLM) {
    echo "Skipping LLM proofreading (--skip-llm).\n\n";

    // Auto-approve items that passed programmatic checks
    foreach ($checkResults as $id => $result) {
        $status = ProgrammaticCheck::overallStatus($result['issues']);
        if ($status === 'pass') {
            $store->approve($id);
        } elseif ($status === 'warning') {
            $store->setStatus($id, 'proofread');
        }
    }
} else {
    // Collect items that didn't hard-fail programmatic checks
    $llmItems = [];
    foreach ($items as $item) {
        $progStatus = ProgrammaticCheck::overallStatus($checkResults[$item['id']]['issues'] ?? []);
        if ($progStatus !== 'fail') {
            $llmItems[] = $item;
        }
    }

    if (empty($llmItems)) {
        echo "All items failed programmatic checks — nothing to send to LLM.\n\n";
    } else {
        echo "Sending " . count($llmItems) . " items to Opus for proofreading...\n";
        $proofreader = new Proofreader();
        $llmResult = $proofreader->proofread($llmItems, $project, $strategy, $market);

        if ($llmResult === null) {
            echo "  LLM proofreading failed — items left as 'proofread' for manual review.\n";
            foreach ($llmItems as $item) {
                $store->setStatus($item['id'], 'proofread');
            }
        } else {
            echo "  Overall score: {$llmResult['overall_score']}\n";

            $autoApproved = 0;
            $needsReview = 0;
            $needsRework = 0;

            foreach ($llmResult['items'] as $itemResult) {
                $id = $itemResult['id'];

                // Merge programmatic + LLM issues
                $progIssues = $checkResults[$id]['issues'] ?? [];
                $llmIssues = $itemResult['issues'] ?? [];
                $allIssues = array_merge($progIssues, $llmIssues);

                $store->updateQA($id, $itemResult['verdict'], $allIssues, $itemResult['score'] ?? null);

                $score = $itemResult['score'] ?? 0;
                $hasFails = false;
                foreach ($allIssues as $issue) {
                    if (($issue['severity'] ?? '') === 'fail') {
                        $hasFails = true;
                        break;
                    }
                }

                if ($score >= 70 && !$hasFails) {
                    $store->approve($id);
                    $autoApproved++;
                } elseif ($score >= 50) {
                    $store->setStatus($id, 'proofread');
                    $needsReview++;
                } else {
                    $store->setStatus($id, 'draft');
                    $needsRework++;
                }
            }

            echo "  Auto-approved: {$autoApproved}  Needs review: {$needsReview}  Needs rework: {$needsRework}\n\n";
        }
    }
}

// ── Summary ──────────────────────────────────────────────────────────────────

$counts = $store->countByStatus((int) $project['id']);
echo "=== Final Status ===\n";
foreach ($counts as $status => $count) {
    echo "  {$status}: {$count}\n";
}
echo "\nReview copy in the dashboard:\n";
echo "  http://localhost:8080/?project={$project['id']}&tab=copy\n";

// Mark background job complete (if launched via dashboard proofread_batch)
if ($jobId) {
    DB::init();
    DB::get()->prepare(
        "UPDATE sync_jobs SET status = 'complete', completed_at = datetime('now') WHERE id = ?"
    )->execute([$jobId]);
    echo "\nJob #{$jobId} marked complete.\n";
}
