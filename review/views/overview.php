<?php
/** Dashboard overview — KPI cards, campaign table, alerts, recent changes */

use AdManager\Dashboard\{PerformanceQuery, Metrics, Changelog};

$days = isset($_GET['days']) ? (int) $_GET['days'] : 7;
if (!in_array($days, [7, 14, 30])) $days = 7;

$summary = PerformanceQuery::projectSummary($projectId, $days);
$campaigns = PerformanceQuery::campaignBreakdown($projectId, 14);
$goals = PerformanceQuery::goalsStatus($projectId, 14);
$syncStatus = PerformanceQuery::syncStatus($projectId);
$recentChanges = Changelog::list($projectId, null, 5);

$cur = $summary['current'];
$del = $summary['deltas'];

// Build alerts from optimisation modules (catch errors gracefully)
$alerts = [];
try {
    $fatigue = new \AdManager\Optimise\CreativeFatigue();
    foreach ($fatigue->detect($projectId) as $f) {
        if ($f['trend_slope'] <= -0.1) {
            $alerts[] = [
                'type' => 'fatigue',
                'severity' => $f['trend_slope'] <= -0.3 ? 'high' : 'moderate',
                'title' => "Creative fatigue: Ad #{$f['ad_id']}",
                'detail' => "CTR slope: " . round($f['trend_slope'], 2) . "%/day over {$f['days_declining']}d. {$f['recommendation']}",
            ];
        }
    }
} catch (\Throwable $e) {}

try {
    $allocator = new \AdManager\Optimise\BudgetAllocator();
    foreach ($allocator->recommend($projectId) as $r) {
        if (abs($r['recommended_budget'] - $r['current_budget']) > 0.5) {
            $dir = $r['recommended_budget'] > $r['current_budget'] ? 'Increase' : 'Decrease';
            $alerts[] = [
                'type' => 'budget',
                'severity' => 'info',
                'title' => "{$dir} budget: {$r['campaign_name']}",
                'detail' => Metrics::money($r['current_budget']) . "/day -> " . Metrics::money($r['recommended_budget']) . "/day. {$r['reason']}",
            ];
        }
    }
} catch (\Throwable $e) {}

try {
    $splitter = new \AdManager\Optimise\SplitTest();
    $testsStmt = $db->prepare("SELECT id, name FROM split_tests WHERE project_id = ? AND status = 'running'");
    $testsStmt->execute([$projectId]);
    foreach ($testsStmt->fetchAll() as $t) {
        $eval = $splitter->evaluate((int) $t['id']);
        if ($eval['status'] === 'concluded' || ($eval['confidence'] ?? 0) >= 0.95) {
            $alerts[] = [
                'type' => 'split_test',
                'severity' => 'action',
                'title' => "Split test ready: {$t['name']}",
                'detail' => "Confidence: " . round(($eval['confidence'] ?? 0) * 100, 1) . "%. Winner: Ad #{$eval['winner']}",
            ];
        }
    }
} catch (\Throwable $e) {}

// Check for zero-conversion campaigns with spend
foreach ($campaigns as $c) {
    if ($c['conversions'] == 0 && $c['cost'] > 30 && $c['status'] !== 'paused') {
        $alerts[] = [
            'type' => 'campaign',
            'severity' => 'high',
            'title' => "Zero conversions: {$c['name']}",
            'detail' => Metrics::money($c['cost']) . " spent with 0 conversions over 14 days.",
        ];
    }
}

// Check budget underdelivery
foreach ($campaigns as $c) {
    if ($c['budget_utilisation'] !== null && $c['budget_utilisation'] < 80 && $c['days_with_data'] >= 3 && $c['status'] !== 'paused') {
        $alerts[] = [
            'type' => 'campaign',
            'severity' => 'moderate',
            'title' => "Underdelivering: {$c['name']}",
            'detail' => "Budget utilisation: " . round($c['budget_utilisation']) . "% over {$c['days_with_data']}d.",
        ];
    }
}

$severityColors = [
    'high' => 'var(--red)',
    'action' => 'var(--orange)',
    'moderate' => 'var(--orange)',
    'info' => 'var(--blue)',
];
$typeIcons = [
    'fatigue' => '&#127912;',
    'budget' => '&#36;',
    'split_test' => '&#9878;',
    'campaign' => '&#128640;',
];

function deltaHtml(array $d, bool $lowerIsBetter = false): string {
    if ($d['value'] === null) return '<span style="color:var(--t3)">—</span>';
    $color = $d['direction'] === 'flat' ? 'var(--t3)' :
        (($d['direction'] === 'up') === !$lowerIsBetter ? 'var(--green)' : 'var(--red)');
    $arrow = $d['direction'] === 'up' ? '&#9650;' : ($d['direction'] === 'down' ? '&#9660;' : '&#8226;');
    return "<span style=\"color:{$color}\">{$arrow} " . abs($d['value']) . "%</span>";
}
?>

<!-- Period selector -->
<div style="display:flex;justify-content:flex-end;margin-bottom:20px">
 <div class="fbar" style="display:inline-flex;padding:8px;margin:0">
  <?php foreach ([7, 14, 30] as $d): ?>
  <a href="?view=overview&project=<?= $projectId ?>&days=<?= $d ?>" class="fb <?= $days === $d ? 'on' : '' ?>" style="color:var(--blue);background:<?= $days === $d ? '#0d2240' : 'var(--bg3)' ?>"><?= $d ?>d</a>
  <?php endforeach; ?>
 </div>
</div>

<!-- KPI Cards -->
<div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:32px">
 <div class="bcard" style="flex:1;min-width:140px">
  <div class="pf">Spend</div>
  <div class="am"><?= Metrics::money($cur['cost']) ?></div>
  <div class="lb"><?= deltaHtml($del['cost'], true) ?> vs prior <?= $days ?>d</div>
 </div>
 <div class="bcard" style="flex:1;min-width:140px">
  <div class="pf">Conversions</div>
  <div class="am" style="color:var(--blue)"><?= round($cur['conversions'], 1) ?></div>
  <div class="lb"><?= deltaHtml($del['conversions']) ?> vs prior</div>
 </div>
 <div class="bcard" style="flex:1;min-width:140px">
  <div class="pf">CPA</div>
  <div class="am" style="color:<?= $cur['cpa'] !== null ? 'var(--t1)' : 'var(--t3)' ?>"><?= $cur['cpa'] !== null ? Metrics::money($cur['cpa']) : '—' ?></div>
  <div class="lb"><?= deltaHtml($del['cpa'], true) ?> vs prior</div>
 </div>
 <div class="bcard" style="flex:1;min-width:140px">
  <div class="pf">ROAS</div>
  <div class="am" style="color:<?= $cur['roas'] !== null ? 'var(--t1)' : 'var(--t3)' ?>"><?= Metrics::roas($cur['roas']) ?></div>
  <div class="lb"><?= deltaHtml($del['roas']) ?> vs prior</div>
 </div>
 <div class="bcard" style="flex:1;min-width:140px">
  <div class="pf">CTR</div>
  <div class="am" style="color:<?= $cur['ctr'] !== null ? 'var(--t1)' : 'var(--t3)' ?>"><?= Metrics::pct($cur['ctr']) ?></div>
  <div class="lb"><?= deltaHtml($del['ctr']) ?> vs prior</div>
 </div>
</div>

<!-- Goals -->
<?php if (!empty($goals)): ?>
<div class="sec">
 <div class="sec-t">Goals</div>
 <div style="display:flex;gap:12px;flex-wrap:wrap">
  <?php foreach ($goals as $g): $color = $g['on_track'] === null ? 'var(--t3)' : ($g['on_track'] ? 'var(--green)' : 'var(--red)'); ?>
  <div class="bcard" style="border-color:<?= $color ?>">
   <div class="pf" style="color:<?= $color ?>"><?= strtoupper(e($g['metric'])) ?> <?= $g['platform'] ? '(' . e($g['platform']) . ')' : '' ?></div>
   <div style="font-size:16px;font-weight:600;color:<?= $color ?>">
    <?= $g['actual'] !== null ? ($g['metric'] === 'cpa' ? Metrics::money($g['actual']) : ($g['metric'] === 'roas' ? Metrics::roas($g['actual']) : Metrics::pct($g['actual']))) : '—' ?>
   </div>
   <div class="lb">Target: <?= $g['metric'] === 'cpa' ? Metrics::money($g['target']) : ($g['metric'] === 'roas' ? Metrics::roas($g['target']) : Metrics::pct($g['target'])) ?></div>
  </div>
  <?php endforeach; ?>
 </div>
</div>
<?php endif; ?>

<!-- Campaign Performance Table -->
<div class="sec">
 <div class="sec-t">Campaign Performance <span class="bg"><?= count($campaigns) ?></span></div>
 <?php if (!empty($campaigns)): ?>
 <div style="overflow-x:auto">
 <table class="ct">
  <thead><tr>
   <th style="position:sticky;left:0;background:var(--bg3);z-index:1">Campaign</th>
   <th>Spend</th><th>Conv</th><th>CPA</th><th>ROAS</th><th>CTR</th><th>Budget</th>
  </tr></thead>
  <tbody>
  <?php foreach ($campaigns as $c): $statusColor = $c['status'] === 'paused' ? 'var(--orange)' : ($c['status'] === 'active' ? 'var(--green)' : 'var(--t3)'); ?>
  <tr>
   <td style="position:sticky;left:0;background:var(--bg2);z-index:1">
    <span class="pb pb-<?= $c['platform'] ?>" style="margin-right:6px"><?= e($c['platform']) ?></span>
    <strong><?= e($c['name']) ?></strong>
    <span style="color:<?= $statusColor ?>;font-size:11px;margin-left:6px"><?= e($c['status']) ?></span>
    <br><button class="expand-btn" data-campaign="<?= $c['id'] ?>" onclick="drillDown(this,<?= $c['id'] ?>)">show ad groups</button>
   </td>
   <td><?= Metrics::money($c['cost']) ?></td>
   <td><?= round($c['conversions'], 1) ?></td>
   <td><?= $c['cpa'] !== null ? Metrics::money($c['cpa']) : '—' ?></td>
   <td><?= Metrics::roas($c['roas']) ?></td>
   <td><?= Metrics::pct($c['ctr']) ?></td>
   <td>
    <?= Metrics::money($c['daily_budget']) ?>/d
    <?php if ($c['budget_utilisation'] !== null): ?>
    <br><span style="font-size:11px;color:<?= $c['budget_utilisation'] < 80 ? 'var(--orange)' : 'var(--t3)' ?>"><?= round($c['budget_utilisation']) ?>% util</span>
    <?php endif; ?>
   </td>
  </tr>
  <tr class="detail-row" id="ag-<?= $c['id'] ?>"><td colspan="7" style="padding:0"><div class="drill-container" style="padding:8px 16px 8px 40px"></div></td></tr>
  <?php endforeach; ?>
  </tbody>
 </table>
 </div>
 <?php else: ?>
  <div class="empty"><div class="ic">&#128202;</div><h3>No campaigns yet</h3><p>Create campaigns to see performance data here.</p></div>
 <?php endif; ?>
</div>

<!-- Alerts -->
<?php if (!empty($alerts)): ?>
<div class="sec">
 <div class="sec-t">Alerts <span class="bg"><?= count($alerts) ?></span></div>
 <?php foreach ($alerts as $a): $color = $severityColors[$a['severity']] ?? 'var(--t3)'; $icon = $typeIcons[$a['type']] ?? '&#9888;'; ?>
 <div style="background:var(--bg2);border-left:3px solid <?= $color ?>;padding:12px 16px;margin-bottom:8px;border-radius:0 var(--r) var(--r) 0">
  <div style="font-size:13px;font-weight:600;color:<?= $color ?>"><?= $icon ?> <?= e($a['title']) ?></div>
  <div style="font-size:12px;color:var(--t2);margin-top:4px"><?= e($a['detail']) ?></div>
 </div>
 <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Recent Changes -->
<?php if (!empty($recentChanges)): ?>
<div class="sec">
 <div class="sec-t">Recent Changes <a href="?view=changelog&project=<?= $projectId ?>" style="font-size:12px;color:var(--blue);text-decoration:none;margin-left:8px">View all &rarr;</a></div>
 <?php $cats = Changelog::categories(); ?>
 <?php foreach ($recentChanges as $cl): $cat = $cats[$cl['category']] ?? $cats['system']; ?>
 <div style="display:flex;gap:12px;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px">
  <span style="color:<?= $cat['color'] ?>;min-width:80px;font-weight:600;font-size:11px;text-transform:uppercase"><?= $cat['label'] ?></span>
  <span style="color:var(--t1);flex:1"><?= e($cl['summary']) ?></span>
  <span style="color:var(--t3);white-space:nowrap;font-size:11px"><?= timeAgo($cl['created_at']) ?></span>
 </div>
 <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
function drillDown(btn, campaignId) {
    var row = document.getElementById('ag-' + campaignId);
    if (row.classList.contains('open')) {
        row.classList.remove('open');
        btn.textContent = 'show ad groups';
        return;
    }
    var container = row.querySelector('.drill-container');
    if (row.dataset.loaded) {
        row.classList.add('open');
        btn.textContent = 'hide ad groups';
        return;
    }
    btn.textContent = 'loading...';
    var f = new FormData();
    f.append('action', 'performance_drilldown');
    f.append('campaign_id', campaignId);
    f.append('project_id', <?= $projectId ?>);
    f.append('days', 14);
    fetch('api.php', {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: f})
    .then(function(r) { return r.json(); })
    .then(function(j) {
        if (!j.ok) { btn.textContent = 'error'; return; }
        var rows = j.rows || [];
        if (rows.length === 0) {
            container.innerHTML = '<div style="color:var(--t3);padding:12px">No ad groups with data</div>';
        } else {
            var h = '<table class="ct" style="font-size:13px"><thead><tr><th>Ad Group</th><th>Spend</th><th>Conv</th><th>CPA</th><th>CTR</th><th>Ads</th><th>KW</th></tr></thead><tbody>';
            rows.forEach(function(r) {
                h += '<tr><td><strong>' + r.name + '</strong> <span style="color:var(--t3);font-size:11px">' + r.status + '</span>';
                if (r.running_tests > 0) h += ' <span style="color:var(--purple);font-size:10px">&#9878; test</span>';
                h += '</td>';
                h += '<td>$' + r.cost.toFixed(2) + '</td>';
                h += '<td>' + r.conversions + '</td>';
                h += '<td>' + (r.cpa !== null ? '$' + r.cpa.toFixed(2) : '\u2014') + '</td>';
                h += '<td>' + (r.ctr !== null ? r.ctr.toFixed(1) + '%' : '\u2014') + '</td>';
                h += '<td>' + r.active_ads + '</td>';
                h += '<td>' + r.active_keywords + '</td></tr>';
            });
            h += '</tbody></table>';
            container.innerHTML = h;
        }
        row.dataset.loaded = '1';
        row.classList.add('open');
        btn.textContent = 'hide ad groups';
    })
    .catch(function() { btn.textContent = 'error'; });
}
</script>
