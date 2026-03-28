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
