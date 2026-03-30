<?php
/**
 * AdManager Dashboard — Router + Layout Shell
 *
 * Routes to views/ based on ?view= parameter. Provides shared layout
 * (sidebar nav, header, CSS, JS, modals) for all dashboard pages.
 */
require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Dashboard\{Auth, PerformanceQuery, Metrics};

DB::init();
Auth::requireIfConfigured();

$db = DB::get();
$view = $_GET['view'] ?? 'overview';
$projectId = isset($_GET['project']) ? (int) $_GET['project'] : null;
$statusFilter = $_GET['status'] ?? null;
$langFilter = $_GET['lang'] ?? null;

$allowedViews = ['overview', 'creative', 'copy', 'campaigns', 'strategies', 'changelog', 'settings'];
if (!in_array($view, $allowedViews)) $view = 'overview';

$projects = $db->query('SELECT * FROM projects ORDER BY name')->fetchAll();
if (!$projectId && count($projects) > 0) $projectId = (int) $projects[0]['id'];

$currentProject = null;
foreach ($projects as $p) { if ((int) $p['id'] === $projectId) { $currentProject = $p; break; } }

// Sync status for sidebar
$syncStatus = $projectId ? PerformanceQuery::syncStatus($projectId) : null;

// Status definitions shared across review views
$statuses = [
    '' => ['label' => 'All', 'color' => '#8b949e', 'bg' => '#30363d'],
    'draft' => ['label' => 'Draft', 'color' => '#8b949e', 'bg' => '#30363d'],
    'feedback' => ['label' => 'Feedback', 'color' => '#d29922', 'bg' => '#3d2e00'],
    'approved' => ['label' => 'Approved', 'color' => '#3fb950', 'bg' => '#0d2d1a'],
    'rejected' => ['label' => 'Rejected', 'color' => '#f85149', 'bg' => '#3d1117'],
    'overlaid' => ['label' => 'Overlaid', 'color' => '#58a6ff', 'bg' => '#0d2240'],
    'uploaded' => ['label' => 'Uploaded', 'color' => '#bc8cff', 'bg' => '#271052'],
];

// Helpers
function truncate(string $t, int $l = 80): string { return mb_strlen($t) > $l ? mb_substr($t, 0, $l) . '...' : $t; }
function timeAgo(string $d): string { $diff = time() - strtotime($d); if ($diff < 60) return 'just now'; if ($diff < 3600) return floor($diff / 60) . 'm ago'; if ($diff < 86400) return floor($diff / 3600) . 'h ago'; if ($diff < 604800) return floor($diff / 86400) . 'd ago'; return date('M j', strtotime($d)); }
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Nav items
$navItems = [
    'overview'   => ['label' => 'Dashboard',  'icon' => '&#128202;'],
    'creative'   => ['label' => 'Creative',   'icon' => '&#127912;', 'group' => 'review'],
    'copy'       => ['label' => 'Ad Copy',    'icon' => '&#128221;', 'group' => 'review'],
    'campaigns'  => ['label' => 'Campaigns',  'icon' => '&#128640;', 'group' => 'review'],
    'strategies' => ['label' => 'Strategies',  'icon' => '&#128203;'],
    'changelog'  => ['label' => 'Change Log', 'icon' => '&#128339;'],
    'settings'   => ['label' => 'Settings',   'icon' => '&#9881;'],
];

$viewTitle = $navItems[$view]['label'] ?? 'Dashboard';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AdManager — <?= e($viewTitle) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0d1117;--bg2:#161b22;--bg3:#21262d;--card:#1c2128;--border:#30363d;--t1:#e6edf3;--t2:#8b949e;--t3:#6e7681;--blue:#58a6ff;--green:#3fb950;--red:#f85149;--orange:#d29922;--purple:#bc8cff;--r:8px;--sidebar:220px}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;background:var(--bg);color:var(--t1);line-height:1.5;min-height:100vh;display:flex}

/* Sidebar */
.sidebar{width:var(--sidebar);background:var(--bg2);border-right:1px solid var(--border);position:fixed;top:0;left:0;bottom:0;display:flex;flex-direction:column;z-index:50;overflow-y:auto}
.sidebar-header{padding:16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.sidebar-header .icon{width:28px;height:28px;background:var(--blue);border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#fff;flex-shrink:0}
.sidebar-header span{font-size:16px;font-weight:600}
.sidebar-project{padding:12px 16px;border-bottom:1px solid var(--border)}
.sidebar-project select{width:100%;background:var(--bg3);color:var(--t1);border:1px solid var(--border);border-radius:var(--r);padding:8px 10px;font-size:13px;cursor:pointer}
.sidebar-nav{flex:1;padding:8px 0}
.nav-label{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:var(--t3);padding:12px 16px 4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:8px 16px;font-size:13px;color:var(--t2);text-decoration:none;transition:all .15s;border-left:2px solid transparent}
.nav-item:hover{color:var(--t1);background:var(--bg3)}
.nav-item.active{color:var(--blue);border-left-color:var(--blue);background:rgba(88,166,255,.06)}
.nav-item .ni{width:18px;text-align:center;font-size:14px}
.nav-item.sub{padding-left:44px;font-size:12px}
.sidebar-sync{padding:16px;border-top:1px solid var(--border);margin-top:auto}
.sidebar-sync .sync-label{font-size:11px;color:var(--t3);margin-bottom:6px}
.sync-btn{width:100%;background:var(--bg3);color:var(--t1);border:1px solid var(--border);border-radius:6px;padding:6px 12px;font-size:12px;cursor:pointer;transition:all .15s}
.sync-btn:hover{border-color:var(--blue);color:var(--blue)}
.sync-btn:disabled{opacity:.5;cursor:not-allowed}

/* Main content */
.main{margin-left:var(--sidebar);flex:1;min-width:0}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:12px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:40}
.topbar-title{font-size:18px;font-weight:600;display:flex;align-items:center;gap:8px}
.topbar-title .ti{font-size:16px}
.topbar-project{font-size:13px;color:var(--t2)}
.container{max-width:1400px;margin:0 auto;padding:24px}

/* Hamburger (mobile) */
.hamburger{display:none;background:none;border:none;color:var(--t1);font-size:24px;cursor:pointer;padding:0}
.sidebar-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:45}

@media(max-width:1024px){
 .sidebar{width:56px}
 .sidebar-header span,.nav-item span:not(.ni),.nav-label,.sidebar-project,.sidebar-sync .sync-label{display:none}
 .nav-item{justify-content:center;padding:10px;border-left:none}
 .nav-item .ni{width:auto;font-size:18px}
 .nav-item.sub{padding-left:10px}
 .main{margin-left:56px}
}
@media(max-width:768px){
 .sidebar{transform:translateX(-100%);width:var(--sidebar);transition:transform .2s}
 .sidebar.open{transform:translateX(0)}
 .sidebar.open~.sidebar-overlay{display:block}
 .sidebar-header span,.nav-item span:not(.ni),.nav-label,.sidebar-project,.sidebar-sync .sync-label{display:initial}
 .main{margin-left:0}
 .hamburger{display:block}
}

/* Shared component styles (from original review page) */
select{background:var(--bg3);color:var(--t1);border:1px solid var(--border);border-radius:var(--r);padding:8px 12px;font-size:14px;cursor:pointer}
.tabs{display:flex;gap:0;margin-bottom:24px;border-bottom:1px solid var(--border)}
.tab{padding:12px 24px;font-size:14px;font-weight:500;color:var(--t2);cursor:pointer;border-bottom:2px solid transparent;transition:all .15s;text-decoration:none}
.tab:hover{color:var(--t1)}.tab.active{color:var(--blue);border-bottom-color:var(--blue)}
.tab .ct{background:var(--bg3);padding:1px 8px;border-radius:10px;font-size:11px;margin-left:6px}
.fbar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:24px;padding:16px;background:var(--bg2);border-radius:var(--r);border:1px solid var(--border)}
.fb{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:500;text-decoration:none;transition:all .15s;border:1px solid transparent}
.fb:hover{opacity:.85}.fb.on{border-color:currentColor;box-shadow:0 0 0 1px currentColor}
.fc{background:rgba(255,255,255,.1);padding:1px 7px;border-radius:10px;font-size:11px}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:32px}
@media(max-width:1100px){.grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:700px){.grid{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;transition:border-color .15s,box-shadow .15s}
.card:hover{border-color:var(--blue);box-shadow:0 4px 12px rgba(0,0,0,.3)}
.prev{position:relative;width:100%;min-height:120px;max-height:400px;background:var(--bg3);display:flex;align-items:center;justify-content:center;overflow:hidden}
.prev img,.prev video{width:100%;height:auto;max-height:400px;object-fit:contain}
.editable-budget{cursor:pointer;border-bottom:1px dashed var(--t3);transition:border-color .15s}
.editable-budget:hover{border-color:var(--blue)}
.budget-input{background:var(--bg3);color:var(--t1);border:1px solid var(--blue);border-radius:4px;padding:4px 8px;font-size:inherit;font-weight:inherit;width:100px;text-align:right}
.type-tip{cursor:help;border-bottom:1px dotted var(--t3)}
.detail-row{display:none}.detail-row.open{display:table-row}
.detail-row td{padding:12px 16px;background:var(--bg);border-bottom:1px solid var(--border)}
.detail-content{font-size:13px;color:var(--t2);line-height:1.6}
.detail-content dt{color:var(--t1);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.5px;margin-top:10px}
.detail-content dt:first-child{margin-top:0}
.detail-content dd{margin:2px 0 0 0}
.strategy-content{font-size:14px;line-height:1.7;color:var(--t1);max-width:900px}
.strategy-content h1{font-size:24px;margin:16px 0 8px}
.strategy-content h2{font-size:20px;margin:24px 0 12px}
.strategy-content h3{font-size:16px;margin:16px 0 8px;color:var(--blue)}
.strategy-content h4{font-size:14px;margin:12px 0 6px;color:var(--t2)}
.strategy-content li{margin:4px 0}
.strategy-content strong{color:var(--t1)}
.expand-btn{cursor:pointer;color:var(--blue);font-size:11px;border:none;background:none;padding:0;text-decoration:underline}
.badge-tl{position:absolute;top:10px;left:10px;background:rgba(0,0,0,.7);color:#fff;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.badge-tr{position:absolute;top:10px;right:10px;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.badge-bl{position:absolute;bottom:8px;left:10px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
.badge-br{position:absolute;bottom:8px;right:10px;background:rgba(0,0,0,.7);color:var(--t2);padding:2px 8px;border-radius:4px;font-size:11px}
.qa-pass{background:rgba(63,185,80,.2);color:var(--green)}.qa-warning{background:rgba(210,153,34,.2);color:var(--orange)}.qa-fail{background:rgba(248,81,73,.2);color:var(--red)}
.body{padding:16px}
.prompt{font-size:13px;color:var(--t2);margin-bottom:12px;line-height:1.4;min-height:36px}
.meta{display:flex;flex-wrap:wrap;gap:12px;font-size:12px;color:var(--t3);margin-bottom:14px}
.meta span{display:flex;align-items:center;gap:4px}
.fb-text{background:var(--bg3);border-left:3px solid var(--orange);padding:8px 12px;margin-bottom:12px;font-size:12px;color:var(--orange);border-radius:0 4px 4px 0}
.rj-text{background:var(--bg3);border-left:3px solid var(--red);padding:8px 12px;margin-bottom:12px;font-size:12px;color:var(--red);border-radius:0 4px 4px 0}
.qa-text{background:var(--bg3);border-left:3px solid var(--purple);padding:8px 12px;margin-bottom:12px;font-size:12px;color:var(--purple);border-radius:0 4px 4px 0}
.qa-text ul{margin:4px 0 0 16px}.qa-text li{margin-bottom:2px}
.acts{display:flex;gap:8px;padding-top:12px;border-top:1px solid var(--border)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:7px 14px;border:1px solid transparent;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;transition:all .15s;flex:1;text-align:center}
.btn:hover{opacity:.85;transform:translateY(-1px)}.btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-a{background:#238636;color:#fff;border-color:#2ea043}.btn-r{background:#da3633;color:#fff;border-color:#f85149}
.btn-f{background:#9e6a03;color:#fff;border-color:#d29922}.btn-q{background:#271052;color:#bc8cff;border-color:#6e40c9}
.btn-e{background:#238636;color:#fff;border-color:#2ea043;padding:8px 20px}.btn-sm{padding:5px 10px;font-size:11px;flex:0}
.sec{margin-bottom:32px}.sec-t{font-size:16px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.sec-t .bg{background:var(--bg3);color:var(--t2);padding:2px 10px;border-radius:10px;font-size:12px}
.cgrid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:32px}@media(max-width:900px){.cgrid{grid-template-columns:1fr}}
.ccard{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:16px;transition:border-color .15s}
.ccard:hover{border-color:var(--blue)}
.ch{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px}
.ct-badge{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;padding:2px 8px;border-radius:4px}
.ct-headline{background:#1a2640;color:var(--blue)}.ct-description{background:#271052;color:var(--purple)}
.ct-primary_text{background:#0d2d1a;color:var(--green)}.ct-sitelink_text{background:#3d2e00;color:var(--orange)}.ct-callout{background:#30363d;color:var(--t2)}
.cc{font-size:14px;line-height:1.5;margin-bottom:10px;white-space:pre-wrap}
.cmeta{font-size:11px;color:var(--t3);margin-bottom:10px}
.cpin{display:inline-block;background:var(--bg3);padding:1px 6px;border-radius:3px;font-size:10px;color:var(--blue);margin-left:6px}
.cgh{font-size:13px;font-weight:600;color:var(--t2);margin:20px 0 12px;padding-bottom:8px;border-bottom:1px solid var(--border)}
table.ct{width:100%;border-collapse:collapse;background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
table.ct th{text-align:left;padding:12px 16px;background:var(--bg3);font-size:12px;font-weight:600;color:var(--t2);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
table.ct td{padding:12px 16px;font-size:14px;border-bottom:1px solid var(--border)}table.ct tr:last-child td{border-bottom:none}
.pb{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase}
.pb-google{background:#1a3a1f;color:#3fb950}.pb-meta{background:#1a2640;color:#58a6ff}
.bgrid{display:flex;gap:16px;flex-wrap:wrap}
.bcard{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:16px 24px;min-width:180px}
.bcard .pf{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.bcard .am{font-size:24px;font-weight:700;color:var(--green)}.bcard .lb{font-size:11px;color:var(--t3)}
.empty{text-align:center;padding:60px 20px;color:var(--t3)}.empty .ic{font-size:48px;margin-bottom:16px}.empty h3{font-size:18px;color:var(--t2);margin-bottom:8px}
.mo{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:200;align-items:center;justify-content:center}
.mo.on{display:flex}.mdl{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:24px;width:90%;max-width:500px;box-shadow:0 8px 32px rgba(0,0,0,.5)}
.mdl h3{font-size:18px;margin-bottom:16px}
.mdl textarea,.mdl input[type=text],.mdl input[type=url]{width:100%;background:var(--bg3);color:var(--t1);border:1px solid var(--border);border-radius:6px;padding:10px 12px;font-size:14px;font-family:inherit}
.mdl textarea{min-height:100px;resize:vertical;margin-bottom:16px}
.mdl textarea:focus,.mdl input:focus{outline:none;border-color:var(--blue)}
.ma{display:flex;gap:8px;justify-content:flex-end}
.mc{background:var(--bg3);color:var(--t1);border:1px solid var(--border);padding:8px 20px;border-radius:6px;cursor:pointer;font-size:14px}
.ms{padding:8px 20px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:500;color:#fff}
.ms-r{background:#da3633}.ms-f{background:#9e6a03}
.tc{position:fixed;bottom:24px;right:24px;z-index:300;display:flex;flex-direction:column;gap:8px}
.toast{padding:12px 20px;border-radius:8px;font-size:13px;font-weight:500;color:#fff;transform:translateX(120%);transition:transform .3s ease;box-shadow:0 4px 12px rgba(0,0,0,.4)}
.toast.show{transform:translateX(0)}.ts{background:#238636}.te{background:#da3633}.ti{background:#1f6feb}
.annotation{margin:4px 0}
.strat-section{position:relative}
.strat-heading{display:flex;align-items:center;gap:8px}
.strat-heading h1,.strat-heading h2,.strat-heading h3,.strat-heading h4,.strat-heading h5,.strat-heading h6{flex:1}
.strat-note-btn{opacity:0;transition:opacity .15s;background:none;border:none;color:var(--t3);cursor:pointer;font-size:14px;padding:2px 6px;border-radius:4px;flex-shrink:0}
.strat-section:hover .strat-note-btn{opacity:1}
.strat-note-btn:hover{color:var(--blue);background:var(--bg3)}
</style>
</head>
<body>

<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
 <div class="sidebar-header">
  <div class="icon">A</div>
  <span>AdManager</span>
 </div>
 <div class="sidebar-project">
  <select onchange="location.href='?view=<?= e($view) ?>&project='+this.value">
   <option value="">Select project...</option>
   <?php foreach ($projects as $p): ?>
   <option value="<?= $p['id'] ?>" <?= $projectId === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['display_name'] ?? $p['name']) ?></option>
   <?php endforeach; ?>
  </select>
 </div>
 <div class="sidebar-nav">
  <?php $prevGroup = null; ?>
  <?php foreach ($navItems as $key => $item): ?>
  <?php if (isset($item['group']) && $item['group'] !== $prevGroup): $prevGroup = $item['group']; ?>
  <div class="nav-label">Review</div>
  <?php elseif (!isset($item['group']) && $prevGroup !== null): $prevGroup = null; endif; ?>
  <a href="?view=<?= $key ?>&project=<?= $projectId ?>" class="nav-item <?= isset($item['group']) ? 'sub' : '' ?> <?= $view === $key ? 'active' : '' ?>">
   <span class="ni"><?= $item['icon'] ?></span>
   <span><?= $item['label'] ?></span>
  </a>
  <?php endforeach; ?>
 </div>
 <div class="sidebar-sync">
  <div class="sync-label">
   <?php if ($syncStatus && $syncStatus['last_sync_at']): ?>
   Last sync: <?= timeAgo($syncStatus['last_sync_at']) ?>
   <?php else: ?>
   Never synced
   <?php endif; ?>
  </div>
  <button class="sync-btn" id="syncBtn" onclick="triggerSync()" <?= $syncStatus && $syncStatus['is_running'] ? 'disabled' : '' ?>>
   <?= $syncStatus && $syncStatus['is_running'] ? 'Syncing...' : 'Sync Now' ?>
  </button>
 </div>
</nav>

<div class="sidebar-overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<!-- Main Content -->
<div class="main">
 <div class="topbar">
  <div style="display:flex;align-items:center;gap:12px">
   <button class="hamburger" onclick="document.getElementById('sidebar').classList.toggle('open')">&#9776;</button>
   <div class="topbar-title"><span class="ti"><?= $navItems[$view]['icon'] ?? '' ?></span> <?= e($viewTitle) ?></div>
  </div>
  <?php if ($currentProject): ?>
  <div class="topbar-project"><?= e($currentProject['display_name'] ?? $currentProject['name']) ?></div>
  <?php endif; ?>
 </div>

 <div class="container">
  <?php if (!$projectId || empty($projects)): ?>
   <div class="empty"><div class="ic">&#128203;</div><h3>No project selected</h3><p>Select a project or create one via Settings.</p></div>
  <?php else: ?>
   <?php include __DIR__ . "/views/{$view}.php"; ?>
  <?php endif; ?>
 </div>
</div>

<!-- Shared Modals -->
<div class="mo" id="rM"><div class="mdl"><h3 style="color:var(--red)">Reject</h3><p style="color:var(--t2);margin-bottom:16px;font-size:14px">Why is this being rejected?</p>
<form onsubmit="return sub('reject')"><input type="hidden" id="rId"><input type="hidden" id="rPid"><input type="hidden" id="rType">
<textarea id="rTxt" placeholder="Specific issues..." required></textarea>
<div class="ma"><button type="button" class="mc" onclick="cls('rM')">Cancel</button><button type="submit" class="ms ms-r">Reject</button></div></form></div></div>

<div class="mo" id="fM"><div class="mdl"><h3 style="color:var(--orange)">Feedback</h3><p style="color:var(--t2);margin-bottom:16px;font-size:14px">What needs to change?</p>
<form onsubmit="return sub('feedback')"><input type="hidden" id="fId"><input type="hidden" id="fPid"><input type="hidden" id="fType">
<textarea id="fTxt" placeholder="Your feedback..." required></textarea>
<div class="ma"><button type="button" class="mc" onclick="cls('fM')">Cancel</button><button type="submit" class="ms ms-f">Send</button></div></form></div></div>

<!-- Annotation Modal -->
<div class="mo" id="annM"><div class="mdl"><h3>Provide Feedback</h3>
<form onsubmit="return submitAnnotation()"><input type="hidden" id="annStratId"><input type="hidden" id="annAnchor">
<textarea id="annTxt" placeholder="Your note on this section..." required></textarea>
<div class="ma"><button type="button" class="mc" onclick="cls('annM')">Cancel</button><button type="submit" class="ms" style="background:var(--blue)">Save</button></div></form></div></div>

<div class="tc" id="T"></div>

<script>
function toast(m,t){t=t||'s';var e=document.createElement('div');e.className='toast t'+t;e.textContent=m;document.getElementById('T').appendChild(e);requestAnimationFrame(function(){e.classList.add('show')});setTimeout(function(){e.classList.remove('show');setTimeout(function(){e.remove()},300)},2500)}
function act(a,d){d.action=a;var f=new FormData;for(var k in d)f.append(k,d[k]);fetch('api.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:f}).then(function(r){return r.json()}).then(function(j){if(j.ok){toast(a.replace(/_/g,' ')+' done','s');var c=document.getElementById(a.includes('copy')?'c-'+(d.copy_id||''):'a-'+(d.asset_id||''));if(c){c.style.opacity='.5'}}else toast(j.error||'Failed','e')}).catch(function(){toast('Network error','e')})}
function modal(t,id,pid,itype){var p=t==='reject'?'r':'f';document.getElementById(p+'Id').value=id;document.getElementById(p+'Pid').value=pid;document.getElementById(p+'Type').value=itype;var m=p+'M';document.getElementById(m).classList.add('on');document.getElementById(p+'Txt').value='';document.getElementById(p+'Txt').focus()}
function cls(id){document.getElementById(id).classList.remove('on')}
function sub(t){var p=t==='reject'?'r':'f';var id=document.getElementById(p+'Id').value;var pid=document.getElementById(p+'Pid').value;var itype=document.getElementById(p+'Type').value;var txt=document.getElementById(p+'Txt').value;if(!txt.trim())return false;var a=t+(itype==='copy'?'_copy':'');var d={project_id:pid};d[itype==='copy'?'copy_id':'asset_id']=id;d[t==='reject'?'reason':'feedback']=txt;act(a,d);cls(p+'M');return false}
document.querySelectorAll('.mo').forEach(function(o){o.addEventListener('click',function(e){if(e.target===o)o.classList.remove('on')})});
document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.mo.on').forEach(function(m){m.classList.remove('on')})});

// Inline budget editing
document.querySelectorAll('.editable-budget').forEach(function(el){
el.addEventListener('click',function(){
  if(el.querySelector('input'))return;
  var daily=parseFloat(el.dataset.daily);
  var isMonthly=el.dataset.monthly==='1';
  var inp=document.createElement('input');
  inp.type='number';inp.step=isMonthly?'1':'0.01';inp.min='0';inp.className='budget-input';
  inp.value=isMonthly?Math.round(daily*30.4).toFixed(0):daily.toFixed(2);
  el.textContent='';el.appendChild(inp);inp.focus();inp.select();
  function restore(d){el.textContent='$'+(isMonthly?Math.round(d*30.4):d.toFixed(2))}
  function save(){
    var val=parseFloat(inp.value);if(isNaN(val)||val<0){restore(daily);return}
    var newDaily=isMonthly?val/30.4:val;
    var type=el.dataset.type;
    var d={project_id:<?=$projectId?>,daily_budget:newDaily.toFixed(2)};
    if(type==='campaign'){d.campaign_id=el.dataset.campaignId;d.action='update_campaign_budget'}
    else if(type==='platform'){d.platform=el.dataset.platform;d.action='update_platform_budget'}
    else{d.action='update_total_budget'}
    var f=new FormData;for(var k in d)f.append(k,d[k]);
    fetch('api.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:f})
    .then(function(r){return r.json()})
    .then(function(j){if(j.ok){if(type!=='campaign'){location.reload()}else{el.dataset.daily=newDaily;restore(newDaily)}}else toast(j.error||'Failed','e')})
    .catch(function(){toast('Network error','e')});
  }
  inp.addEventListener('blur',save);
  inp.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();inp.blur()}if(e.key==='Escape'){restore(daily)}});
});
});

// Sync Now
function triggerSync(){
 var btn=document.getElementById('syncBtn');
 btn.disabled=true;btn.textContent='Syncing...';
 act('sync_trigger',{project_id:<?=$projectId?>,platform:'all',days:7});
 var poll=setInterval(function(){
  var f=new FormData;f.append('action','sync_status');f.append('project_id',<?=$projectId?>);
  fetch('api.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:f})
  .then(function(r){return r.json()})
  .then(function(j){if(j.ok&&!j.is_running){clearInterval(poll);btn.disabled=false;btn.textContent='Sync Now';toast('Sync complete','s');setTimeout(function(){location.reload()},1000)}});
 },3000);
}

// Strategy annotations
function addAnnotation(stratId,anchor){
 document.getElementById('annStratId').value=stratId;
 document.getElementById('annAnchor').value=anchor;
 document.getElementById('annM').classList.add('on');
 document.getElementById('annTxt').value='';
 document.getElementById('annTxt').focus();
}
function submitAnnotation(){
 var stratId=document.getElementById('annStratId').value;
 var anchor=document.getElementById('annAnchor').value;
 var comment=document.getElementById('annTxt').value.trim();
 if(!comment)return false;
 act('strategy_annotate',{strategy_id:stratId,section_anchor:anchor,comment:comment});
 cls('annM');
 setTimeout(function(){location.reload()},500);
 return false;
}
function resolveAnnotation(id){
 act('strategy_annotation_resolve',{annotation_id:id});
 setTimeout(function(){location.reload()},500);
}
</script>
</body></html>
