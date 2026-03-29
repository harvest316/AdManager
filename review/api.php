<?php
require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Creative\ReviewStore;

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
            break;

        // Ad copy actions
        case 'approve_copy':
            $db->prepare('UPDATE ad_copy SET status = ? WHERE id = ?')
               ->execute(['approved', $copyId]);
            break;
        case 'reject_copy':
            $db->prepare('UPDATE ad_copy SET status = ?, rejected_reason = ? WHERE id = ?')
               ->execute(['rejected', $_POST['reason'] ?? '', $copyId]);
            break;
        case 'feedback_copy':
            $db->prepare('UPDATE ad_copy SET status = ?, feedback = ? WHERE id = ?')
               ->execute(['feedback', $_POST['feedback'] ?? '', $copyId]);
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
            $db->prepare('UPDATE campaigns SET daily_budget_aud = ? WHERE id = ?')
               ->execute([round($daily, 2), $campaignId]);
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
