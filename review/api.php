<?php
require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Creative\ReviewStore;

DB::init();

$store = new ReviewStore();
$action = $_POST['action'] ?? '';
$assetId = isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0;
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

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
}

// Redirect back
$statusParam = isset($_POST['status']) ? '&status=' . urlencode($_POST['status']) : '';
header('Location: index.php?project=' . $projectId . $statusParam);
exit;
