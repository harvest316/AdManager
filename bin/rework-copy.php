#!/usr/bin/env php
<?php
/**
 * Rework a specific ad copy item with human feedback.
 *
 * Loads the original copy item and feedback, generates a revised version via Opus,
 * inserts as a new row (original preserved for audit), and runs through the full
 * proofreading pipeline.
 *
 * Usage:
 *   php bin/rework-copy.php --id <copy_id> --feedback "Make it more benefit-driven"
 *   php bin/rework-copy.php --id <copy_id>   (uses feedback from DB if set)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Locale;
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

if (!isset($args['id'])) {
    echo <<<USAGE
Usage:
  php bin/rework-copy.php --id <copy_id> [--feedback "instruction"]

Options:
  --id        ID of the ad_copy row to rework
  --feedback  Rework instructions (overrides DB feedback field)

The original item is preserved. A new copy item is created with the reworked version
and run through the full proofreading pipeline (programmatic + LLM).

USAGE;
    exit(1);
}

$copyId = (int) $args['id'];
$feedbackArg = $args['feedback'] ?? null;

// ── Load copy item ───────────────────────────────────────────────────────────

DB::init();
$store = new Store();
$item = $store->getById($copyId);

if (!$item) {
    echo "Error: copy item #{$copyId} not found.\n";
    exit(1);
}

$feedback = $feedbackArg ?? $item['feedback'] ?? null;
if (!$feedback) {
    echo "Error: no feedback provided. Use --feedback or set feedback in the dashboard.\n";
    exit(1);
}

// Load project and strategy
$db = DB::get();

$stmt = $db->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$item['project_id']]);
$project = $stmt->fetch();

$stmt = $db->prepare('SELECT * FROM strategies WHERE id = ?');
$stmt->execute([$item['strategy_id']]);
$strategy = $stmt->fetch();

echo "Reworking copy #{$copyId}: \"{$item['content']}\"\n";
echo "Feedback: {$feedback}\n";
echo "Type: {$item['copy_type']} ({$item['platform']})\n\n";

// ── Generate reworked copy via Claude ────────────────────────────────────────

$charLimit = $item['char_limit'] ? "Maximum {$item['char_limit']} characters. " : '';
$localeInstruction = Locale::promptInstruction($item['target_market'] ?? 'all');
$productName = $project['display_name'] ?? $project['name'];

$prompt = <<<PROMPT
You are an expert advertising copywriter. Rewrite the following ad copy based on the feedback provided.

Product: {$productName} ({$project['website_url']})
Value Proposition: {$strategy['value_proposition']}
Copy Type: {$item['copy_type']}
Platform: {$item['platform']}
{$charLimit}{$localeInstruction}

Original copy:
"{$item['content']}"

Feedback:
{$feedback}

Write ONLY the revised copy text. No explanation, no quotes, no formatting. Just the copy.
PROMPT;

$claudeBin = getenv('CLAUDE_BIN') ?: '/home/jason/.local/bin/claude';
$cmd = "{$claudeBin} -p " . escapeshellarg($prompt) . " --model opus --output-format text";

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open($cmd, $descriptors, $pipes);

if (!is_resource($process)) {
    echo "Error: failed to start Claude CLI.\n";
    exit(1);
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0 || !trim($stdout)) {
    echo "Error: Claude CLI failed (exit code {$exitCode}).\n";
    exit(1);
}

$newContent = trim($stdout);
// Strip any quotes the LLM might have added
$newContent = trim($newContent, '"\'');

echo "Revised: \"{$newContent}\"\n\n";

// ── Insert new copy item ─────────────────────────────────────────────────────

$newItem = [
    'platform'      => $item['platform'],
    'campaign_name' => $item['campaign_name'],
    'ad_group_name' => $item['ad_group_name'],
    'copy_type'     => $item['copy_type'],
    'content'       => $newContent,
    'char_limit'    => $item['char_limit'],
    'pin_position'  => $item['pin_position'],
    'language'       => $item['language'] ?? 'en',
    'target_market'  => $item['target_market'] ?? 'all',
];

$ids = $store->bulkInsert((int) $item['project_id'], (int) $item['strategy_id'], [$newItem]);
$newId = $ids[0];
echo "Inserted as copy #{$newId}\n";

// Mark original as rejected with rework reference
$store->reject($copyId, "Reworked → #{$newId}: {$feedback}");

// ── Run programmatic checks on new item ──────────────────────────────────────

echo "Running programmatic checks...\n";
$newItemRow = $store->getById($newId);
$checker = new ProgrammaticCheck();
$brandName = $project['display_name'] ?? $project['name'];
$results = $checker->checkAll([$newItemRow], $brandName, $item['target_market'] ?? 'all');

$progStatus = ProgrammaticCheck::overallStatus($results[$newId]['issues'] ?? []);
$store->updateQA($newId, $progStatus, $results[$newId]['issues'] ?? []);

if ($progStatus === 'fail') {
    echo "  Programmatic check: FAIL\n";
    foreach ($results[$newId]['issues'] as $issue) {
        if ($issue['severity'] === 'fail') {
            echo "    [{$issue['category']}] {$issue['description']}\n";
        }
    }
    echo "\nItem saved as draft. Fix issues and try again.\n";
    exit(0);
}

echo "  Programmatic check: {$progStatus}\n";

// ── Run LLM proofreading ────────────────────────────────────────────────────

echo "Running Opus proofreading...\n";
$proofreader = new Proofreader();
$llmResult = $proofreader->proofread([$newItemRow], $project, $strategy, $item['target_market'] ?? 'all');

if ($llmResult && !empty($llmResult['items'])) {
    $itemResult = $llmResult['items'][0];
    $score = $itemResult['score'] ?? 0;

    $allIssues = array_merge($results[$newId]['issues'] ?? [], $itemResult['issues'] ?? []);
    $store->updateQA($newId, $itemResult['verdict'], $allIssues, $score);

    if ($score >= 70) {
        $store->approve($newId);
        echo "  Score: {$score} — AUTO-APPROVED\n";
    } elseif ($score >= 50) {
        $store->setStatus($newId, 'proofread');
        echo "  Score: {$score} — needs review\n";
    } else {
        echo "  Score: {$score} — needs rework\n";
    }
} else {
    echo "  LLM proofreading failed. Item saved as 'proofread' for manual review.\n";
    $store->setStatus($newId, 'proofread');
}

echo "\nDone. Original #{$copyId} → new #{$newId}\n";
