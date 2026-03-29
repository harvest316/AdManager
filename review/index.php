<?php
require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;
use AdManager\Creative\ReviewStore;

DB::init();

$store = new ReviewStore();
$projectId = isset($_GET['project']) ? (int)$_GET['project'] : null;
$statusFilter = $_GET['status'] ?? null;
$tab = $_GET['tab'] ?? 'creative';
$langFilter = $_GET['lang'] ?? null;

$projects = DB::get()->query('SELECT * FROM projects ORDER BY name')->fetchAll();
if (!$projectId && count($projects) > 0) $projectId = (int)$projects[0]['id'];

$assets = $projectId ? $store->listByProject($projectId, $statusFilter) : [];
$allAssets = $projectId ? $store->listByProject($projectId) : [];
$pendingCampaigns = $projectId ? $store->getPendingCampaigns($projectId) : [];

$adCopy = [];
$copyCounts = [];
$langCounts = [];
if ($projectId) {
    $where = 'project_id = ?';
    $params = [$projectId];
    if ($statusFilter) { $where .= ' AND status = ?'; $params[] = $statusFilter; }
    if ($langFilter) { $where .= ' AND language = ?'; $params[] = $langFilter; }
    $copyStmt = DB::get()->prepare("SELECT * FROM ad_copy WHERE {$where} ORDER BY campaign_name, ad_group_name, copy_type, id");
    $copyStmt->execute($params);
    $adCopy = $copyStmt->fetchAll();
    $ccStmt = DB::get()->prepare('SELECT status, COUNT(*) as cnt FROM ad_copy WHERE project_id = ? GROUP BY status');
    $ccStmt->execute([$projectId]);
    foreach ($ccStmt->fetchAll() as $r) $copyCounts[$r['status']] = (int)$r['cnt'];
    $lcStmt = DB::get()->prepare('SELECT language, COUNT(*) as cnt FROM ad_copy WHERE project_id = ? GROUP BY language ORDER BY language');
    $lcStmt->execute([$projectId]);
    foreach ($lcStmt->fetchAll() as $r) $langCounts[$r['language']] = (int)$r['cnt'];
}

$budgets = [];
if ($projectId) {
    $bs = DB::get()->prepare('SELECT * FROM budgets WHERE project_id = ? ORDER BY platform');
    $bs->execute([$projectId]);
    $budgets = $bs->fetchAll();
}

$currentProject = null;
foreach ($projects as $p) { if ((int)$p['id'] === $projectId) { $currentProject = $p; break; } }

$statuses = [
    '' => ['label'=>'All','color'=>'#8b949e','bg'=>'#30363d'],
    'draft' => ['label'=>'Draft','color'=>'#8b949e','bg'=>'#30363d'],
    'feedback' => ['label'=>'Feedback','color'=>'#d29922','bg'=>'#3d2e00'],
    'approved' => ['label'=>'Approved','color'=>'#3fb950','bg'=>'#0d2d1a'],
    'rejected' => ['label'=>'Rejected','color'=>'#f85149','bg'=>'#3d1117'],
    'overlaid' => ['label'=>'Overlaid','color'=>'#58a6ff','bg'=>'#0d2240'],
    'uploaded' => ['label'=>'Uploaded','color'=>'#bc8cff','bg'=>'#271052'],
];

$statusCounts = ['all' => 0];
if ($projectId) {
    if ($tab === 'copy') {
        $statusCounts['all'] = array_sum($copyCounts);
        foreach ($statuses as $k => $v) { if ($k !== '') $statusCounts[$k] = $copyCounts[$k] ?? 0; }
    } else {
        $statusCounts['all'] = count($allAssets);
        foreach ($statuses as $k => $v) { if ($k !== '') $statusCounts[$k] = count(array_filter($allAssets, fn($a) => $a['status'] === $k)); }
    }
}

function truncate(string $t, int $l = 80): string { return mb_strlen($t) > $l ? mb_substr($t, 0, $l) . '...' : $t; }
function timeAgo(string $d): string { $diff = time() - strtotime($d); if ($diff < 60) return 'just now'; if ($diff < 3600) return floor($diff/60).'m ago'; if ($diff < 86400) return floor($diff/3600).'h ago'; if ($diff < 604800) return floor($diff/86400).'d ago'; return date('M j', strtotime($d)); }
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AdManager Review</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0d1117;--bg2:#161b22;--bg3:#21262d;--card:#1c2128;--border:#30363d;--t1:#e6edf3;--t2:#8b949e;--t3:#6e7681;--blue:#58a6ff;--green:#3fb950;--red:#f85149;--orange:#d29922;--purple:#bc8cff;--r:8px}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;background:var(--bg);color:var(--t1);line-height:1.5;min-height:100vh}
.header{background:var(--bg2);border-bottom:1px solid var(--border);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100}
.header-title{font-size:20px;font-weight:600;display:flex;align-items:center;gap:10px}
.header-title .icon{width:28px;height:28px;background:var(--blue);border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#fff}
select{background:var(--bg3);color:var(--t1);border:1px solid var(--border);border-radius:var(--r);padding:8px 12px;font-size:14px;cursor:pointer;min-width:200px}
.container{max-width:1400px;margin:0 auto;padding:24px}
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
.prev{position:relative;width:100%;height:200px;background:var(--bg3);display:flex;align-items:center;justify-content:center;overflow:hidden}
.prev img,.prev video{width:100%;height:100%;object-fit:cover}
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
.mdl textarea{width:100%;min-height:100px;background:var(--bg3);color:var(--t1);border:1px solid var(--border);border-radius:6px;padding:10px 12px;font-size:14px;font-family:inherit;resize:vertical;margin-bottom:16px}
.mdl textarea:focus{outline:none;border-color:var(--blue)}
.ma{display:flex;gap:8px;justify-content:flex-end}
.mc{background:var(--bg3);color:var(--t1);border:1px solid var(--border);padding:8px 20px;border-radius:6px;cursor:pointer;font-size:14px}
.ms{padding:8px 20px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:500;color:#fff}
.ms-r{background:#da3633}.ms-f{background:#9e6a03}
.tc{position:fixed;bottom:24px;right:24px;z-index:300;display:flex;flex-direction:column;gap:8px}
.toast{padding:12px 20px;border-radius:8px;font-size:13px;font-weight:500;color:#fff;transform:translateX(120%);transition:transform .3s ease;box-shadow:0 4px 12px rgba(0,0,0,.4)}
.toast.show{transform:translateX(0)}.ts{background:#238636}.te{background:#da3633}.ti{background:#1f6feb}
</style>
</head>
<body>
<div class="header">
 <div class="header-title"><div class="icon">A</div><span>AdManager</span><span style="color:var(--t3);font-weight:400">&mdash;</span><span style="color:var(--t2);font-weight:400">Review</span></div>
 <div><select onchange="location.href='?project='+this.value+'&tab=<?= e($tab) ?>'"><option value="">Select project...</option><?php foreach($projects as $p): ?><option value="<?=$p['id']?>" <?=$projectId===(int)$p['id']?'selected':''?>><?=e($p['display_name']??$p['name'])?></option><?php endforeach; ?></select></div>
</div>
<div class="container">
<?php if(!$projectId||empty($projects)): ?>
<div class="empty"><div class="ic">&#128203;</div><h3>No project selected</h3><p>Select a project or create one with <code>php bin/project.php create</code>.</p></div>
<?php else: ?>
<div class="tabs">
 <a href="?project=<?=$projectId?>&tab=creative" class="tab <?=$tab==='creative'?'active':''?>">Creative<span class="ct"><?=count($allAssets)?></span></a>
 <a href="?project=<?=$projectId?>&tab=copy" class="tab <?=$tab==='copy'?'active':''?>">Ad Copy<span class="ct"><?=array_sum($copyCounts)?></span></a>
 <a href="?project=<?=$projectId?>&tab=campaigns" class="tab <?=$tab==='campaigns'?'active':''?>">Campaigns<span class="ct"><?=count($pendingCampaigns)?></span></a>
</div>
<?php if($tab!=='campaigns'): ?>
<?php $langParam = $langFilter ? '&lang='.e($langFilter) : ''; ?>
<div class="fbar">
 <?php foreach($statuses as $k=>$v): $on=($statusFilter??'')===$k; $href='?project='.$projectId.'&tab='.e($tab).($k?'&status='.$k:'').$langParam; $c=$k===''?($statusCounts['all']??0):($statusCounts[$k]??0); ?>
 <a href="<?=$href?>" class="fb <?=$on?'on':''?>" style="color:<?=$v['color']?>;background:<?=$v['bg']?>"><?=$v['label']?> <span class="fc"><?=$c?></span></a>
 <?php endforeach; ?>
</div>
<?php if($tab==='copy' && !empty($langCounts)): ?>
<?php $statusParam = $statusFilter ? '&status='.e($statusFilter) : ''; ?>
<div class="fbar" style="margin-top:-16px">
 <a href="?project=<?=$projectId?>&tab=copy<?=$statusParam?>" class="fb <?=!$langFilter?'on':''?>" style="color:#8b949e;background:#30363d">All <span class="fc"><?=array_sum($langCounts)?></span></a>
 <?php foreach($langCounts as $lc=>$cnt): $lon=($langFilter===$lc); ?>
 <a href="?project=<?=$projectId?>&tab=copy&lang=<?=e($lc)?><?=$statusParam?>" class="fb <?=$lon?'on':''?>" style="color:var(--blue);background:#0d2240"><?=strtoupper($lc)?> <span class="fc"><?=$cnt?></span></a>
 <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if($tab==='creative'): ?>
 <?php if(empty($assets)): ?>
  <div class="empty"><div class="ic">&#127912;</div><h3>No assets<?=$statusFilter?' with "'.$statusFilter.'"':''?></h3><p>Generate with <code>php bin/generate-creative.php</code>.</p></div>
 <?php else: ?>
  <div class="sec"><div class="sec-t">Assets <span class="bg"><?=count($assets)?></span></div>
  <div class="grid">
   <?php foreach($assets as $a): $st=$statuses[$a['status']]??$statuses['draft']; $qa=$a['cv_qa_status']??null; $qi=$a['cv_qa_issues']?json_decode($a['cv_qa_issues'],true):[]; $dims=''; if(!empty($a['width'])&&!empty($a['height']))$dims=$a['width'].'x'.$a['height']; if(!empty($a['duration_seconds']))$dims.=($dims?' / ':'').round($a['duration_seconds'],1).'s'; ?>
   <div class="card" id="a-<?=$a['id']?>">
    <div class="prev">
     <?php if($a['type']==='image'&&$a['local_path']): ?><img src="assets.php?path=<?=urlencode($a['local_path'])?>" loading="lazy">
     <?php elseif($a['type']==='video'&&$a['local_path']): ?><video src="assets.php?path=<?=urlencode($a['local_path'])?>" muted preload="metadata" onmouseenter="this.play()" onmouseleave="this.pause();this.currentTime=0"></video>
     <?php else: ?><div style="font-size:48px;color:var(--t3)">&#128196;</div><?php endif; ?>
     <span class="badge-tl"><?=e($a['type'])?></span>
     <span class="badge-tr" style="color:<?=$st['color']?>;background:<?=$st['bg']?>"><?=$st['label']?></span>
     <?php if($dims): ?><span class="badge-br"><?=$dims?></span><?php endif; ?>
     <?php if($qa): ?><span class="badge-bl qa-<?=$qa?>"><?=strtoupper($qa)?></span><?php endif; ?>
    </div>
    <div class="body">
     <div class="prompt"><?=e(truncate($a['generation_prompt']??'No prompt',80))?></div>
     <div class="meta">
      <?php if(!empty($a['generation_model'])): ?><span>&#9881; <?=e($a['generation_model'])?></span><?php endif; ?>
      <?php if($a['generation_cost_usd']!==null): ?><span>$<?=number_format((float)$a['generation_cost_usd'],4)?></span><?php endif; ?>
      <span><?=timeAgo($a['created_at'])?></span><span style="color:var(--t3)">#<?=$a['id']?></span>
     </div>
     <?php if(!empty($qi)): ?><div class="qa-text"><strong>QA:</strong><ul><?php foreach($qi as $i): ?><li><strong>[<?=e($i['category']??'?')?>]</strong> <?=e($i['description']??'')?></li><?php endforeach; ?></ul></div><?php endif; ?>
     <?php if(!empty($a['feedback'])): ?><div class="fb-text"><strong>Feedback:</strong> <?=e($a['feedback'])?></div><?php endif; ?>
     <?php if(!empty($a['rejected_reason'])): ?><div class="rj-text"><strong>Rejected:</strong> <?=e($a['rejected_reason'])?></div><?php endif; ?>
     <div class="acts">
      <button class="btn btn-a" onclick="act('approve',{asset_id:<?=$a['id']?>,project_id:<?=$projectId?>})">&#10003; Approve</button>
      <button class="btn btn-r" onclick="modal('reject',<?=$a['id']?>,<?=$projectId?>,'asset')">&#10007;</button>
      <button class="btn btn-f" onclick="modal('feedback',<?=$a['id']?>,<?=$projectId?>,'asset')">&#9998;</button>
      <?php if(!$qa): ?><button class="btn btn-q btn-sm" onclick="runQA(<?=$a['id']?>,this)">QA</button><?php endif; ?>
     </div>
    </div>
   </div>
   <?php endforeach; ?>
  </div></div>
 <?php endif; ?>

<?php elseif($tab==='copy'): ?>
 <?php if(empty($adCopy)): ?>
  <div class="empty"><div class="ic">&#128221;</div><h3>No ad copy<?=$statusFilter?' with "'.$statusFilter.'"':''?></h3><p>Import with <code>php bin/import-copy.php</code> or generate via strategy.</p></div>
 <?php else: ?>
  <?php $grouped=[]; foreach($adCopy as $c){ $k=($c['campaign_name']??'Ungrouped').' > '.($c['ad_group_name']??'General'); $grouped[$k][]=$c; } ?>
  <div class="sec"><div class="sec-t">Ad Copy <span class="bg"><?=count($adCopy)?></span></div>
  <?php foreach($grouped as $g=>$copies): ?>
   <div class="cgh"><?=e($g)?></div>
   <div class="cgrid">
    <?php foreach($copies as $c): $cst=$statuses[$c['status']]??$statuses['draft']; ?>
    <div class="ccard" id="c-<?=$c['id']?>">
     <div class="ch">
      <span class="ct-badge ct-<?=$c['copy_type']?>"><?=e($c['copy_type'])?></span>
      <span><?php if(!empty($c['language'])&&$c['language']!=='en'): ?><span class="cpin"><?=strtoupper(e($c['language']))?></span><?php endif; ?> <span style="color:<?=$cst['color']?>;font-size:11px;font-weight:600"><?=$cst['label']?></span></span>
     </div>
     <div class="cc"><?=e($c['content'])?></div>
     <div class="cmeta">
      <span style="color:var(--t2)"><?=e($c['platform'])?></span>
      <?php if(!empty($c['target_market']) && $c['target_market'] !== 'all'): ?><span class="cpin"><?=e($c['target_market'])?></span><?php endif; ?>
      <?php if($c['pin_position']): ?><span class="cpin">PIN <?=$c['pin_position']?></span><?php endif; ?>
      <span style="float:right">#<?=$c['id']?> &middot; <?=mb_strlen($c['content'])?> chars</span>
     </div>
     <?php if(!empty($c['feedback'])): ?><div class="fb-text"><strong>Feedback:</strong> <?=e($c['feedback'])?></div><?php endif; ?>
     <?php if(!empty($c['rejected_reason'])): ?><div class="rj-text"><strong>Rejected:</strong> <?=e($c['rejected_reason'])?></div><?php endif; ?>
     <div class="acts">
      <button class="btn btn-a btn-sm" onclick="act('approve_copy',{copy_id:<?=$c['id']?>,project_id:<?=$projectId?>})">&#10003;</button>
      <button class="btn btn-r btn-sm" onclick="modal('reject',<?=$c['id']?>,<?=$projectId?>,'copy')">&#10007;</button>
      <button class="btn btn-f btn-sm" onclick="modal('feedback',<?=$c['id']?>,<?=$projectId?>,'copy')">&#9998;</button>
     </div>
    </div>
    <?php endforeach; ?>
   </div>
  <?php endforeach; ?>
  </div>
 <?php endif; ?>

<?php elseif($tab==='campaigns'): ?>
 <?php if(!empty($pendingCampaigns)): ?>
  <div class="sec"><div class="sec-t">Pending Campaigns <span class="bg"><?=count($pendingCampaigns)?></span></div>
  <table class="ct"><thead><tr><th>Campaign</th><th>Platform</th><th>Type</th><th>Budget</th><th>Created</th><th>Action</th></tr></thead><tbody>
   <?php foreach($pendingCampaigns as $c): ?>
   <tr><td><strong><?=e($c['name'])?></strong><?php if(!empty($c['external_id'])): ?><br><span style="color:var(--t3);font-size:11px"><?=e($c['external_id'])?></span><?php endif; ?></td>
   <td><span class="pb pb-<?=strtolower($c['platform'])?>"><?=e($c['platform'])?></span></td>
   <td><?=e($c['type'])?></td>
   <td><?=!empty($c['daily_budget_aud'])?'$'.number_format((float)$c['daily_budget_aud'],2).'/day':'<span style="color:var(--t3)">-</span>'?></td>
   <td style="color:var(--t3)"><?=timeAgo($c['created_at'])?></td>
   <td><button class="btn btn-e" onclick="act('enable_campaign',{campaign_id:<?=$c['id']?>,project_id:<?=$projectId?>})">Enable</button></td></tr>
   <?php endforeach; ?>
  </tbody></table></div>
 <?php else: ?>
  <div class="empty"><div class="ic">&#128640;</div><h3>No pending campaigns</h3></div>
 <?php endif; ?>
 <?php if(!empty($budgets)): ?>
  <div class="sec"><div class="sec-t">Budget Overview</div>
  <div class="bgrid">
   <?php foreach($budgets as $b): ?><div class="bcard"><div class="pf" style="color:<?=strtolower($b['platform'])==='google'?'var(--green)':'var(--blue)'?>"><?=e($b['platform'])?></div><div class="am">$<?=number_format((float)$b['daily_budget_aud'],2)?></div><div class="lb">AUD / day</div></div><?php endforeach; ?>
   <?php $td=array_sum(array_column($budgets,'daily_budget_aud')); ?><div class="bcard" style="border-color:var(--blue)"><div class="pf" style="color:var(--blue)">Total</div><div class="am">$<?=number_format($td,2)?></div><div class="lb">AUD / day (~$<?=number_format($td*30.4,0)?>/mo)</div></div>
  </div></div>
 <?php endif; ?>
<?php endif; ?>

<?php endif; ?>
</div>

<div class="mo" id="rM"><div class="mdl"><h3 style="color:var(--red)">Reject</h3><p style="color:var(--t2);margin-bottom:16px;font-size:14px">Why is this being rejected?</p>
<form onsubmit="return sub('reject')"><input type="hidden" id="rId"><input type="hidden" id="rPid"><input type="hidden" id="rType">
<textarea id="rTxt" placeholder="Specific issues..." required></textarea>
<div class="ma"><button type="button" class="mc" onclick="cls('rM')">Cancel</button><button type="submit" class="ms ms-r">Reject</button></div></form></div></div>

<div class="mo" id="fM"><div class="mdl"><h3 style="color:var(--orange)">Feedback</h3><p style="color:var(--t2);margin-bottom:16px;font-size:14px">What needs to change?</p>
<form onsubmit="return sub('feedback')"><input type="hidden" id="fId"><input type="hidden" id="fPid"><input type="hidden" id="fType">
<textarea id="fTxt" placeholder="Your feedback..." required></textarea>
<div class="ma"><button type="button" class="mc" onclick="cls('fM')">Cancel</button><button type="submit" class="ms ms-f">Send</button></div></form></div></div>

<div class="tc" id="T"></div>
<script>
function toast(m,t){t=t||'s';var e=document.createElement('div');e.className='toast t'+t;e.textContent=m;document.getElementById('T').appendChild(e);requestAnimationFrame(function(){e.classList.add('show')});setTimeout(function(){e.classList.remove('show');setTimeout(function(){e.remove()},300)},2500)}
function act(a,d){d.action=a;var f=new FormData;for(var k in d)f.append(k,d[k]);fetch('api.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:f}).then(function(r){return r.json()}).then(function(j){if(j.ok){var c=document.getElementById(a.includes('copy')?'c-'+(d.copy_id||''):'a-'+(d.asset_id||''));if(c){var lbl=a.includes('approve')?'Approved':a.includes('reject')?'Rejected':a.includes('feedback')?'Feedback':'Done';var clr=a.includes('approve')?'var(--green)':a.includes('reject')?'var(--red)':'var(--orange)';var badge=c.querySelector('.badge-tr')||c.querySelector('.ch span:last-child span:last-child');if(badge){badge.textContent=lbl;badge.style.color=clr}c.style.opacity='.5'}}else toast(j.error||'Failed','e')}).catch(function(){toast('Network error','e')})}
function runQA(id,b){b.disabled=true;b.textContent='...';var f=new FormData;f.append('action','run_qa');f.append('asset_id',id);fetch('api.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:f}).then(function(r){return r.json()}).then(function(j){if(j.ok){var s=j.status||'?';toast('QA: '+s.toUpperCase()+(j.issues&&j.issues.length?' ('+j.issues.length+' issues)':''),s==='pass'?'s':s==='fail'?'e':'i');setTimeout(function(){location.reload()},1500)}else{toast(j.error||'QA failed','e');b.disabled=false;b.textContent='QA'}}).catch(function(){toast('Network error','e');b.disabled=false;b.textContent='QA'})}
function modal(t,id,pid,itype){var p=t==='reject'?'r':'f';document.getElementById(p+'Id').value=id;document.getElementById(p+'Pid').value=pid;document.getElementById(p+'Type').value=itype;var m=p+'M';document.getElementById(m).classList.add('on');document.getElementById(p+'Txt').value='';document.getElementById(p+'Txt').focus()}
function cls(id){document.getElementById(id).classList.remove('on')}
function sub(t){var p=t==='reject'?'r':'f';var id=document.getElementById(p+'Id').value;var pid=document.getElementById(p+'Pid').value;var itype=document.getElementById(p+'Type').value;var txt=document.getElementById(p+'Txt').value;if(!txt.trim())return false;var a=t+(itype==='copy'?'_copy':'');var d={project_id:pid};d[itype==='copy'?'copy_id':'asset_id']=id;d[t==='reject'?'reason':'feedback']=txt;act(a,d);cls(p+'M');return false}
document.querySelectorAll('.mo').forEach(function(o){o.addEventListener('click',function(e){if(e.target===o)o.classList.remove('on')})});
document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.mo.on').forEach(function(m){m.classList.remove('on')})});
</script>
</body></html>
