<?php
require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Creative\ReviewStore;

DB::init(); // ensure tables exist

$store = new ReviewStore();
$projectId = isset($_GET['project']) ? (int)$_GET['project'] : null;
$statusFilter = $_GET['status'] ?? null;

// Get all projects for nav
$projects = DB::get()->query('SELECT * FROM projects ORDER BY name')->fetchAll();

// Auto-select first project if none specified
if (!$projectId && count($projects) > 0) {
    $projectId = (int)$projects[0]['id'];
}

// Get assets if project selected
$assets = $projectId ? $store->listByProject($projectId, $statusFilter) : [];

// Get pending campaigns
$pendingCampaigns = $projectId ? $store->getPendingCampaigns($projectId) : [];

// Get budgets for selected project
$budgets = [];
if ($projectId) {
    $budgetStmt = DB::get()->prepare('SELECT * FROM budgets WHERE project_id = :pid ORDER BY platform');
    $budgetStmt->execute([':pid' => $projectId]);
    $budgets = $budgetStmt->fetchAll();
}

// Get current project name
$currentProject = null;
foreach ($projects as $p) {
    if ((int)$p['id'] === $projectId) {
        $currentProject = $p;
        break;
    }
}

// Status config
$statuses = [
    ''         => ['label' => 'All',      'color' => '#8b949e', 'bg' => '#30363d'],
    'draft'    => ['label' => 'Draft',    'color' => '#8b949e', 'bg' => '#30363d'],
    'feedback' => ['label' => 'Feedback', 'color' => '#d29922', 'bg' => '#3d2e00'],
    'approved' => ['label' => 'Approved', 'color' => '#3fb950', 'bg' => '#0d2d1a'],
    'rejected' => ['label' => 'Rejected', 'color' => '#f85149', 'bg' => '#3d1117'],
    'overlaid' => ['label' => 'Overlaid', 'color' => '#58a6ff', 'bg' => '#0d2240'],
    'uploaded' => ['label' => 'Uploaded', 'color' => '#bc8cff', 'bg' => '#271052'],
];

// Count assets per status
$statusCounts = ['all' => count($assets)];
if ($projectId) {
    $allAssets = $store->listByProject($projectId);
    $statusCounts['all'] = count($allAssets);
    foreach ($statuses as $key => $cfg) {
        if ($key === '') continue;
        $statusCounts[$key] = count(array_filter($allAssets, fn($a) => $a['status'] === $key));
    }
}

function truncate(string $text, int $len = 80): string {
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len) . '...' : $text;
}

function timeAgo(string $datetime): string {
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AdManager &mdash; Creative Review</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg-primary: #0d1117;
    --bg-secondary: #161b22;
    --bg-tertiary: #21262d;
    --bg-card: #1c2128;
    --border: #30363d;
    --text-primary: #e6edf3;
    --text-secondary: #8b949e;
    --text-muted: #6e7681;
    --accent-blue: #58a6ff;
    --accent-green: #3fb950;
    --accent-red: #f85149;
    --accent-orange: #d29922;
    --accent-purple: #bc8cff;
    --radius: 8px;
    --shadow: 0 1px 3px rgba(0,0,0,.4), 0 1px 2px rgba(0,0,0,.3);
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
    background: var(--bg-primary);
    color: var(--text-primary);
    line-height: 1.5;
    min-height: 100vh;
}

/* Header */
.header {
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border);
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
}
.header-title {
    font-size: 20px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}
.header-title .icon {
    width: 28px; height: 28px;
    background: var(--accent-blue);
    border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; color: #fff;
}
.header-controls {
    display: flex;
    align-items: center;
    gap: 12px;
}
.header-controls select {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 8px 12px;
    font-size: 14px;
    cursor: pointer;
    min-width: 200px;
}
.header-controls select:focus {
    outline: none;
    border-color: var(--accent-blue);
    box-shadow: 0 0 0 3px rgba(88,166,255,.15);
}

/* Main layout */
.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 24px;
}

/* Filter bar */
.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 24px;
    padding: 16px;
    background: var(--bg-secondary);
    border-radius: var(--radius);
    border: 1px solid var(--border);
}
.filter-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    transition: all .15s ease;
    border: 1px solid transparent;
}
.filter-badge:hover {
    opacity: .85;
    transform: translateY(-1px);
}
.filter-badge.active {
    border-color: currentColor;
    box-shadow: 0 0 0 1px currentColor;
}
.filter-count {
    background: rgba(255,255,255,.1);
    padding: 1px 7px;
    border-radius: 10px;
    font-size: 11px;
}

/* Asset grid */
.asset-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 32px;
}
@media (max-width: 1100px) { .asset-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 700px) { .asset-grid { grid-template-columns: 1fr; } }

.asset-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    transition: border-color .15s, box-shadow .15s;
}
.asset-card:hover {
    border-color: var(--accent-blue);
    box-shadow: 0 4px 12px rgba(0,0,0,.3);
}

.asset-preview {
    position: relative;
    width: 100%;
    height: 200px;
    background: var(--bg-tertiary);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.asset-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.asset-preview .video-icon {
    font-size: 48px;
    color: var(--text-muted);
}
.asset-type-badge {
    position: absolute;
    top: 10px;
    left: 10px;
    background: rgba(0,0,0,.7);
    color: #fff;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.asset-status-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.asset-dims {
    position: absolute;
    bottom: 8px;
    right: 10px;
    background: rgba(0,0,0,.7);
    color: var(--text-secondary);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
}

.asset-body {
    padding: 16px;
}
.asset-prompt {
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 12px;
    line-height: 1.4;
    min-height: 36px;
}
.asset-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 12px;
    color: var(--text-muted);
    margin-bottom: 14px;
}
.asset-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}
.asset-feedback-text {
    background: var(--bg-tertiary);
    border-left: 3px solid var(--accent-orange);
    padding: 8px 12px;
    margin-bottom: 12px;
    font-size: 12px;
    color: var(--accent-orange);
    border-radius: 0 4px 4px 0;
}
.asset-rejected-text {
    background: var(--bg-tertiary);
    border-left: 3px solid var(--accent-red);
    padding: 8px 12px;
    margin-bottom: 12px;
    font-size: 12px;
    color: var(--accent-red);
    border-radius: 0 4px 4px 0;
}
.asset-actions {
    display: flex;
    gap: 8px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 7px 14px;
    border: 1px solid transparent;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all .15s;
    flex: 1;
    text-align: center;
}
.btn:hover { opacity: .85; transform: translateY(-1px); }
.btn-approve { background: #238636; color: #fff; border-color: #2ea043; }
.btn-reject { background: #da3633; color: #fff; border-color: #f85149; }
.btn-feedback { background: #9e6a03; color: #fff; border-color: #d29922; }
.btn-enable { background: #238636; color: #fff; border-color: #2ea043; padding: 8px 20px; }

/* Sections */
.section {
    margin-bottom: 32px;
}
.section-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text-primary);
}
.section-title .badge {
    background: var(--bg-tertiary);
    color: var(--text-secondary);
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 500;
}

/* Campaign table */
.campaign-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.campaign-table th {
    text-align: left;
    padding: 12px 16px;
    background: var(--bg-tertiary);
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: .5px;
    border-bottom: 1px solid var(--border);
}
.campaign-table td {
    padding: 12px 16px;
    font-size: 14px;
    border-bottom: 1px solid var(--border);
}
.campaign-table tr:last-child td { border-bottom: none; }
.campaign-table tr:hover td { background: var(--bg-tertiary); }

.platform-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.platform-google { background: #1a3a1f; color: #3fb950; }
.platform-meta { background: #1a2640; color: #58a6ff; }

/* Budget cards */
.budget-grid {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
}
.budget-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 24px;
    min-width: 180px;
}
.budget-card .platform {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 4px;
}
.budget-card .amount {
    font-size: 24px;
    font-weight: 700;
    color: var(--accent-green);
}
.budget-card .label {
    font-size: 11px;
    color: var(--text-muted);
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}
.empty-state .icon { font-size: 48px; margin-bottom: 16px; }
.empty-state h3 { font-size: 18px; color: var(--text-secondary); margin-bottom: 8px; }
.empty-state p { font-size: 14px; }

/* Modal */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,.6);
    z-index: 200;
    align-items: center;
    justify-content: center;
}
.modal-overlay.active { display: flex; }
.modal {
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 8px 32px rgba(0,0,0,.5);
}
.modal h3 {
    font-size: 18px;
    margin-bottom: 16px;
}
.modal textarea {
    width: 100%;
    min-height: 100px;
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 14px;
    font-family: inherit;
    resize: vertical;
    margin-bottom: 16px;
}
.modal textarea:focus {
    outline: none;
    border-color: var(--accent-blue);
    box-shadow: 0 0 0 3px rgba(88,166,255,.15);
}
.modal-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}
.modal .btn-cancel {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border: 1px solid var(--border);
    padding: 8px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
}
.modal .btn-submit {
    padding: 8px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: #fff;
}
.modal .btn-submit.reject { background: #da3633; }
.modal .btn-submit.feedback { background: #9e6a03; }
</style>
</head>
<body>

<div class="header">
    <div class="header-title">
        <div class="icon">A</div>
        <span>AdManager</span>
        <span style="color: var(--text-muted); font-weight: 400;">&mdash;</span>
        <span style="color: var(--text-secondary); font-weight: 400;">Creative Review</span>
    </div>
    <div class="header-controls">
        <select onchange="location.href='?project='+this.value">
            <option value="">Select project...</option>
            <?php foreach ($projects as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $projectId === (int)$p['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['display_name'] ?? $p['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="container">

<?php if (!$projectId || empty($projects)): ?>
    <div class="empty-state">
        <div class="icon">&#128203;</div>
        <h3>No project selected</h3>
        <p>Select a project from the dropdown above, or create one first with <code>bin/project.php</code>.</p>
    </div>
<?php else: ?>

    <!-- Filter bar -->
    <div class="filter-bar">
        <?php foreach ($statuses as $key => $cfg): ?>
            <?php
                $isActive = ($statusFilter ?? '') === $key;
                $href = '?project=' . $projectId . ($key !== '' ? '&status=' . $key : '');
                $count = $key === '' ? ($statusCounts['all'] ?? 0) : ($statusCounts[$key] ?? 0);
            ?>
            <a href="<?= $href ?>"
               class="filter-badge <?= $isActive ? 'active' : '' ?>"
               style="color: <?= $cfg['color'] ?>; background: <?= $cfg['bg'] ?>;">
                <?= $cfg['label'] ?>
                <span class="filter-count"><?= $count ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Asset grid -->
    <?php if (empty($assets)): ?>
        <div class="empty-state">
            <div class="icon">&#127912;</div>
            <h3>No creative assets<?= $statusFilter ? ' with status "' . htmlspecialchars($statusFilter) . '"' : '' ?></h3>
            <p>Generate creative with <code>bin/generate-creative.php</code> or change the filter above.</p>
        </div>
    <?php else: ?>
        <div class="section">
            <div class="section-title">
                Assets
                <span class="badge"><?= count($assets) ?></span>
            </div>
            <div class="asset-grid">
                <?php foreach ($assets as $asset): ?>
                    <?php
                        $st = $statuses[$asset['status']] ?? $statuses['draft'];
                        $isImage = $asset['type'] === 'image';
                        $isVideo = $asset['type'] === 'video';
                        $dims = '';
                        if ($asset['width'] && $asset['height']) $dims = $asset['width'] . 'x' . $asset['height'];
                        if ($asset['duration_seconds']) $dims .= ($dims ? ' / ' : '') . round($asset['duration_seconds'], 1) . 's';
                    ?>
                    <div class="asset-card">
                        <div class="asset-preview">
                            <?php if ($isImage && $asset['local_path']): ?>
                                <img src="assets.php?path=<?= urlencode($asset['local_path']) ?>"
                                     alt="Asset #<?= $asset['id'] ?>"
                                     loading="lazy">
                            <?php elseif ($isVideo): ?>
                                <div class="video-icon">&#9654;</div>
                            <?php else: ?>
                                <div class="video-icon">&#128196;</div>
                            <?php endif; ?>
                            <span class="asset-type-badge"><?= htmlspecialchars($asset['type']) ?></span>
                            <span class="asset-status-badge" style="color: <?= $st['color'] ?>; background: <?= $st['bg'] ?>;">
                                <?= $st['label'] ?>
                            </span>
                            <?php if ($dims): ?>
                                <span class="asset-dims"><?= $dims ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="asset-body">
                            <div class="asset-prompt">
                                <?= htmlspecialchars(truncate($asset['generation_prompt'] ?? 'No prompt', 80)) ?>
                            </div>
                            <div class="asset-meta">
                                <?php if ($asset['generation_model']): ?>
                                    <span>&#9881; <?= htmlspecialchars($asset['generation_model']) ?></span>
                                <?php endif; ?>
                                <?php if ($asset['generation_cost_usd'] !== null): ?>
                                    <span>&#128176; $<?= number_format((float)$asset['generation_cost_usd'], 4) ?></span>
                                <?php endif; ?>
                                <span>&#128197; <?= timeAgo($asset['created_at']) ?></span>
                                <span style="color: var(--text-muted);">#<?= $asset['id'] ?></span>
                            </div>
                            <?php if ($asset['feedback']): ?>
                                <div class="asset-feedback-text">
                                    <strong>Feedback:</strong> <?= htmlspecialchars($asset['feedback']) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($asset['rejected_reason']): ?>
                                <div class="asset-rejected-text">
                                    <strong>Rejected:</strong> <?= htmlspecialchars($asset['rejected_reason']) ?>
                                </div>
                            <?php endif; ?>
                            <div class="asset-actions">
                                <form method="POST" action="api.php" style="flex:1;display:flex;">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="asset_id" value="<?= $asset['id'] ?>">
                                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                                    <button type="submit" class="btn btn-approve" style="flex:1;">&#10003; Approve</button>
                                </form>
                                <button type="button" class="btn btn-reject"
                                        onclick="openModal('reject', <?= $asset['id'] ?>, <?= $projectId ?>)"
                                        style="flex:1;">
                                    &#10007; Reject
                                </button>
                                <button type="button" class="btn btn-feedback"
                                        onclick="openModal('feedback', <?= $asset['id'] ?>, <?= $projectId ?>)"
                                        style="flex:1;">
                                    &#9998; Feedback
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Pending campaigns -->
    <?php if (!empty($pendingCampaigns)): ?>
        <div class="section">
            <div class="section-title">
                Pending Campaigns
                <span class="badge"><?= count($pendingCampaigns) ?></span>
            </div>
            <table class="campaign-table">
                <thead>
                    <tr>
                        <th>Campaign</th>
                        <th>Platform</th>
                        <th>Type</th>
                        <th>Budget</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingCampaigns as $c): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($c['name']) ?></strong>
                                <?php if ($c['external_id']): ?>
                                    <br><span style="color:var(--text-muted);font-size:11px;"><?= htmlspecialchars($c['external_id']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="platform-badge platform-<?= strtolower($c['platform']) ?>">
                                    <?= htmlspecialchars($c['platform']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($c['type']) ?></td>
                            <td>
                                <?php if ($c['daily_budget_aud']): ?>
                                    $<?= number_format((float)$c['daily_budget_aud'], 2) ?>/day
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--text-muted);"><?= timeAgo($c['created_at']) ?></td>
                            <td>
                                <form method="POST" action="api.php" style="display:inline;">
                                    <input type="hidden" name="action" value="enable_campaign">
                                    <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                                    <button type="submit" class="btn btn-enable">Enable</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Budget overview -->
    <?php if (!empty($budgets)): ?>
        <div class="section">
            <div class="section-title">
                Budget Overview
            </div>
            <div class="budget-grid">
                <?php foreach ($budgets as $b): ?>
                    <div class="budget-card">
                        <div class="platform" style="color: <?= strtolower($b['platform']) === 'google' ? 'var(--accent-green)' : 'var(--accent-blue)' ?>;">
                            <?= htmlspecialchars($b['platform']) ?>
                        </div>
                        <div class="amount">$<?= number_format((float)$b['daily_budget_aud'], 2) ?></div>
                        <div class="label">AUD / day</div>
                    </div>
                <?php endforeach; ?>
                <?php
                    $totalDaily = array_sum(array_column($budgets, 'daily_budget_aud'));
                ?>
                <div class="budget-card" style="border-color: var(--accent-blue);">
                    <div class="platform" style="color: var(--accent-blue);">Total</div>
                    <div class="amount">$<?= number_format($totalDaily, 2) ?></div>
                    <div class="label">AUD / day (~$<?= number_format($totalDaily * 30.4, 0) ?>/mo)</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal">
        <h3 style="color: var(--accent-red);">Reject Asset</h3>
        <p style="color: var(--text-secondary); margin-bottom: 16px; font-size: 14px;">
            Provide a reason for rejecting this asset. The creative team will see this feedback.
        </p>
        <form method="POST" action="api.php" id="rejectForm">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="asset_id" id="rejectAssetId" value="">
            <input type="hidden" name="project_id" id="rejectProjectId" value="">
            <textarea name="reason" placeholder="Reason for rejection..." required></textarea>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="btn-submit reject">Reject Asset</button>
            </div>
        </form>
    </div>
</div>

<!-- Feedback Modal -->
<div class="modal-overlay" id="feedbackModal">
    <div class="modal">
        <h3 style="color: var(--accent-orange);">Send Feedback</h3>
        <p style="color: var(--text-secondary); margin-bottom: 16px; font-size: 14px;">
            Provide feedback for this asset. It will be flagged for revision.
        </p>
        <form method="POST" action="api.php" id="feedbackForm">
            <input type="hidden" name="action" value="feedback">
            <input type="hidden" name="asset_id" id="feedbackAssetId" value="">
            <input type="hidden" name="project_id" id="feedbackProjectId" value="">
            <textarea name="feedback" placeholder="Your feedback..." required></textarea>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('feedbackModal')">Cancel</button>
                <button type="submit" class="btn-submit feedback">Send Feedback</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(type, assetId, projectId) {
    if (type === 'reject') {
        document.getElementById('rejectAssetId').value = assetId;
        document.getElementById('rejectProjectId').value = projectId;
        document.getElementById('rejectModal').classList.add('active');
        document.querySelector('#rejectModal textarea').focus();
    } else {
        document.getElementById('feedbackAssetId').value = assetId;
        document.getElementById('feedbackProjectId').value = projectId;
        document.getElementById('feedbackModal').classList.add('active');
        document.querySelector('#feedbackModal textarea').focus();
    }
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.classList.remove('active');
    });
});

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(function(m) {
            m.classList.remove('active');
        });
    }
});
</script>

</body>
</html>
