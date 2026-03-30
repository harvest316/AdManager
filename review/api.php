<?php
require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Creative\ReviewStore;
use AdManager\Dashboard\{Changelog, PerformanceQuery, SyncRunner, ConversionPlanner};

DB::init();

$store = new ReviewStore();
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

$action = $_POST['action'] ?? '';
$assetId = isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0;
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$copyId = isset($_POST['copy_id']) ? (int)$_POST['copy_id'] : 0;

$db = DB::get();
$result = ['ok' => true];

try {
    switch ($action) {
        case 'approve':
            $store->approve($assetId);
            break;
        case 'reject':
            $store->reject($assetId, $_POST['reason'] ?? '');
            break;
        case 'feedback':
            $store->addFeedback($assetId, $_POST['feedback'] ?? '');
            break;
        case 'enable_campaign':
            $campaignId = (int)$_POST['campaign_id'];
            $store->enableCampaign($campaignId);
            $cName = $db->prepare('SELECT name FROM campaigns WHERE id = ?');
            $cName->execute([$campaignId]);
            $campName = $cName->fetchColumn() ?: "Campaign #{$campaignId}";
            Changelog::log($projectId, 'campaign', 'enabled', "Campaign '{$campName}' enabled", ['campaign_id' => $campaignId], 'campaign', $campaignId, 'admin');
            break;

        // Ad copy actions
        case 'approve_copy':
            $db->prepare('UPDATE ad_copy SET status = ? WHERE id = ?')
               ->execute(['approved', $copyId]);
            if ($projectId) Changelog::log($projectId, 'creative', 'approved', "Ad copy #{$copyId} approved", ['copy_id' => $copyId], 'ad', $copyId, 'admin');
            break;
        case 'reject_copy':
            $reason = $_POST['reason'] ?? '';
            $db->prepare('UPDATE ad_copy SET status = ?, rejected_reason = ? WHERE id = ?')
               ->execute(['rejected', $reason, $copyId]);
            if ($projectId) Changelog::log($projectId, 'creative', 'rejected', "Ad copy #{$copyId} rejected: {$reason}", ['copy_id' => $copyId, 'reason' => $reason], 'ad', $copyId, 'admin');
            break;
        case 'feedback_copy':
            $db->prepare('UPDATE ad_copy SET status = ?, feedback = ? WHERE id = ?')
               ->execute(['feedback', $_POST['feedback'] ?? '', $copyId]);
            break;
        case 'unapprove_copy':
            $db->prepare("UPDATE ad_copy SET status = 'draft', updated_at = datetime('now') WHERE id = ?")
               ->execute([$copyId]);
            break;

        // CV QA
        case 'run_qa':
            $qa = new \AdManager\Creative\QualityCheck();
            $result = $qa->check($assetId);
            $result['ok'] = true;
            break;

        // Budget management
        case 'update_campaign_budget':
            $campaignId = (int)$_POST['campaign_id'];
            $daily = (float)$_POST['daily_budget'];
            if ($daily < 0) throw new \RuntimeException('Budget cannot be negative');
            $oldBudget = $db->prepare('SELECT daily_budget_aud, name FROM campaigns WHERE id = ?');
            $oldBudget->execute([$campaignId]);
            $oldRow = $oldBudget->fetch();
            $db->prepare('UPDATE campaigns SET daily_budget_aud = ? WHERE id = ?')
               ->execute([round($daily, 2), $campaignId]);
            if ($projectId && $oldRow) {
                Changelog::log($projectId, 'budget', 'reallocated',
                    "Campaign '{$oldRow['name']}' budget: \${$oldRow['daily_budget_aud']}/day -> \$" . round($daily, 2) . "/day",
                    ['campaign_id' => $campaignId, 'old' => (float)$oldRow['daily_budget_aud'], 'new' => round($daily, 2)],
                    'campaign', $campaignId, 'admin');
            }
            break;

        case 'update_platform_budget':
            $platform = $_POST['platform'] ?? '';
            $daily = (float)$_POST['daily_budget'];
            if ($daily < 0) throw new \RuntimeException('Budget cannot be negative');
            if (!in_array($platform, ['google', 'meta'])) throw new \RuntimeException('Invalid platform');
            $db->prepare('UPDATE budgets SET daily_budget_aud = ?, updated_at = datetime("now") WHERE project_id = ? AND platform = ?')
               ->execute([round($daily, 2), $projectId, $platform]);
            // Proportionally adjust campaigns on this platform
            $camps = $db->prepare('SELECT id, daily_budget_aud FROM campaigns WHERE project_id = ? AND platform = ?');
            $camps->execute([$projectId, $platform]);
            $rows = $camps->fetchAll();
            $oldTotal = array_sum(array_column($rows, 'daily_budget_aud'));
            if ($oldTotal > 0) {
                $ratio = $daily / $oldTotal;
                foreach ($rows as $r) {
                    $db->prepare('UPDATE campaigns SET daily_budget_aud = ? WHERE id = ?')
                       ->execute([round($r['daily_budget_aud'] * $ratio, 2), $r['id']]);
                }
            }
            break;

        case 'update_total_budget':
            $daily = (float)$_POST['daily_budget'];
            if ($daily < 0) throw new \RuntimeException('Budget cannot be negative');
            // Get current totals per platform
            $bs = $db->prepare('SELECT id, platform, daily_budget_aud FROM budgets WHERE project_id = ?');
            $bs->execute([$projectId]);
            $budgetRows = $bs->fetchAll();
            $oldTotal = array_sum(array_column($budgetRows, 'daily_budget_aud'));
            if ($oldTotal > 0) {
                $ratio = $daily / $oldTotal;
                foreach ($budgetRows as $b) {
                    $newPlatBudget = round($b['daily_budget_aud'] * $ratio, 2);
                    $db->prepare('UPDATE budgets SET daily_budget_aud = ?, updated_at = datetime("now") WHERE id = ?')
                       ->execute([$newPlatBudget, $b['id']]);
                    // Also scale campaigns on this platform
                    $camps = $db->prepare('SELECT id, daily_budget_aud FROM campaigns WHERE project_id = ? AND platform = ?');
                    $camps->execute([$projectId, $b['platform']]);
                    foreach ($camps->fetchAll() as $c) {
                        $db->prepare('UPDATE campaigns SET daily_budget_aud = ? WHERE id = ?')
                           ->execute([round($c['daily_budget_aud'] * $ratio, 2), $c['id']]);
                    }
                }
            }
            break;

        // ── Performance API ─────────────────────────────────────

        case 'performance_drilldown':
            $campaignId = (int) ($_POST['campaign_id'] ?? 0);
            $days = (int) ($_POST['days'] ?? 14);
            $result = ['ok' => true, 'rows' => PerformanceQuery::adGroupBreakdown($campaignId, $days)];
            break;

        case 'performance_ads':
            $adGroupId = (int) ($_POST['ad_group_id'] ?? 0);
            $days = (int) ($_POST['days'] ?? 14);
            $result = ['ok' => true, 'rows' => PerformanceQuery::adBreakdown($adGroupId, $days)];
            break;

        // ── Changelog API ───────────────────────────────────────

        case 'changelog_add':
            $category = $_POST['category'] ?? 'manual';
            $action_type = $_POST['action'] ?? 'note';
            $summary = $_POST['summary'] ?? '';
            if (!$summary) throw new \RuntimeException('Summary is required');
            $id = Changelog::log($projectId, $category, $action_type, $summary, null, null, null, 'admin');
            $result = ['ok' => true, 'id' => $id];
            break;

        // ── Strategy annotations ────────────────────────────────

        case 'strategy_annotate':
            $strategyId = (int) ($_POST['strategy_id'] ?? 0);
            $anchor = $_POST['section_anchor'] ?? '';
            $comment = $_POST['comment'] ?? '';
            if (!$strategyId || !$anchor || !$comment) throw new \RuntimeException('Missing fields');
            $db->prepare(
                'INSERT INTO strategy_annotations (strategy_id, section_anchor, comment) VALUES (?, ?, ?)'
            )->execute([$strategyId, $anchor, $comment]);
            $result = ['ok' => true, 'id' => (int) $db->lastInsertId()];
            break;

        case 'strategy_annotation_resolve':
            $annId = (int) ($_POST['annotation_id'] ?? 0);
            $db->prepare(
                "UPDATE strategy_annotations SET status = 'resolved', resolved_at = datetime('now') WHERE id = ?"
            )->execute([$annId]);
            $result = ['ok' => true];
            break;

        // ── Sync trigger ────────────────────────────────────────

        case 'sync_trigger':
            $platform = $_POST['platform'] ?? 'all';
            $days = (int) ($_POST['days'] ?? 7);
            $runner = new SyncRunner();
            $jobId = $runner->start($projectId, $platform, $days);
            $result = ['ok' => true, 'job_id' => $jobId];
            break;

        case 'sync_poll':
            $jobId = (int) ($_POST['job_id'] ?? 0);
            $runner = new SyncRunner();
            $poll = $runner->poll($jobId);
            $result = array_merge(['ok' => true], $poll);
            break;

        case 'sync_status':
            $status = PerformanceQuery::syncStatus($projectId);
            $result = array_merge(['ok' => true], $status);
            break;

        // ── Project CRUD ────────────────────────────────────────

        case 'project_create':
            $name = trim($_POST['name'] ?? '');
            $displayName = trim($_POST['display_name'] ?? '');
            $url = trim($_POST['website_url'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if (!$name || !$displayName) throw new \RuntimeException('Name and display name required');
            $db->prepare(
                'INSERT INTO projects (name, display_name, website_url, description) VALUES (?, ?, ?, ?)'
            )->execute([$name, $displayName, $url ?: null, $desc ?: null]);
            $newId = (int) $db->lastInsertId();
            Changelog::log($newId, 'system', 'created', "Project '{$displayName}' created", null, null, null, 'admin');
            $result = ['ok' => true, 'id' => $newId];
            break;

        case 'project_update':
            $displayName = trim($_POST['display_name'] ?? '');
            $url = trim($_POST['website_url'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $db->prepare(
                "UPDATE projects SET display_name = ?, website_url = ?, description = ?, updated_at = datetime('now') WHERE id = ?"
            )->execute([$displayName ?: null, $url ?: null, $desc ?: null, $projectId]);
            $result = ['ok' => true];
            break;

        // ── Conversion actions ──────────────────────────────────

        // ── LLM Proofreading ────────────────────────────────────

        case 'proofread_batch':
            // Launch LLM proofreading as a background job (Claude takes 30-120s;
            // running synchronously would block the single-threaded PHP dev server).
            $strategyId = (int) ($_POST['strategy_id'] ?? 0);
            $copyStatus = $_POST['copy_status'] ?? 'draft';
            $market     = $_POST['market'] ?? 'AU';

            if (!$projectId) throw new \RuntimeException('project_id required');

            $jobDir = dirname(__DIR__) . '/tmp/proofread-jobs';
            if (!is_dir($jobDir)) mkdir($jobDir, 0755, true);

            // Create job record in DB
            $db->prepare(
                "INSERT INTO sync_jobs (project_id, platform, days, status, started_at)
                 VALUES (?, 'proofread', 0, 'running', datetime('now'))"
            )->execute([$projectId]);
            $jobId = (int) $db->lastInsertId();

            // Launch bin/proofread-copy.php with --project and --strategy args
            $proj = $db->prepare('SELECT name FROM projects WHERE id = ?');
            $proj->execute([$projectId]);
            $projectName = $proj->fetchColumn();
            if (!$projectName) throw new \RuntimeException("Project #{$projectId} not found");

            $binPath = dirname(__DIR__) . '/bin/proofread-copy.php';
            $outFile = "{$jobDir}/{$jobId}.log";
            $pidFile = "{$jobDir}/{$jobId}.pid";

            $cmd = sprintf(
                'php %s --project %s --strategy %d --market %s --job-id %d > %s 2>&1 & echo $!',
                escapeshellarg($binPath),
                escapeshellarg($projectName),
                $strategyId,
                escapeshellarg($market),
                $jobId,
                escapeshellarg($outFile)
            );

            $pid = trim(shell_exec($cmd));
            file_put_contents($pidFile, $pid);

            $result = ['ok' => true, 'job_id' => $jobId, 'async' => true,
                       'message' => 'Proofreading started. Poll proofread_poll for status.'];
            break;

        case 'proofread_poll':
            $jobId = (int) ($_POST['job_id'] ?? 0);
            if (!$jobId) throw new \RuntimeException('job_id required');

            $jobDir = dirname(__DIR__) . '/tmp/proofread-jobs';
            $pidFile = "{$jobDir}/{$jobId}.pid";
            $logFile = "{$jobDir}/{$jobId}.log";

            $job = $db->prepare('SELECT * FROM sync_jobs WHERE id = ?');
            $job->execute([$jobId]);
            $jobRow = $job->fetch();
            if (!$jobRow) throw new \RuntimeException("Job #{$jobId} not found");

            $isRunning = false;
            if (file_exists($pidFile)) {
                $pid = trim(file_get_contents($pidFile));
                $isRunning = $pid && file_exists("/proc/{$pid}");
            }

            if (!$isRunning && $jobRow['status'] === 'running') {
                // Process finished — mark complete
                $db->prepare(
                    "UPDATE sync_jobs SET status = 'complete', completed_at = datetime('now') WHERE id = ?"
                )->execute([$jobId]);
                $jobRow['status'] = 'complete';
            }

            $tail = '';
            if (file_exists($logFile)) {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $tail = implode("\n", array_slice($lines ?: [], -20));
            }

            $result = [
                'ok'     => true,
                'status' => $jobRow['status'],
                'done'   => $jobRow['status'] === 'complete' || $jobRow['status'] === 'failed',
                'log'    => $tail,
            ];
            break;

        case 'conversion_plan':
            $planner = new ConversionPlanner();
            $plan = $planner->plan($projectId);
            if (empty($plan['actions'])) {
                $result = ['ok' => true, 'message' => 'No new actions to plan (all already exist)'];
            } else {
                $ids = $planner->savePlan($projectId, $plan['actions']);
                $result = ['ok' => true, 'count' => count($ids), 'business_type' => $plan['business_type']];
            }
            break;

        case 'conversion_provision':
            $actionId = (int) ($_POST['action_id'] ?? 0);
            $planner = new ConversionPlanner();
            $result = $planner->provision($actionId);
            break;

        case 'conversion_verify':
            $verifier = new \AdManager\Dashboard\ConversionVerifier();
            $report = $verifier->verify($projectId);
            $result = $report;
            break;

        default:
            $result = ['ok' => false, 'error' => 'Unknown action'];
    }
} catch (\Exception $e) {
    $result = ['ok' => false, 'error' => $e->getMessage()];
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Non-AJAX: redirect back
$statusParam = isset($_POST['status']) ? '&status=' . urlencode($_POST['status']) : '';
$tab = isset($_POST['tab']) ? '&tab=' . urlencode($_POST['tab']) : '';
header('Location: index.php?project=' . $projectId . $statusParam . $tab);
exit;
