<?php
/** Ad copy review tab — extracted from original index.php */

$adCopy = [];
$copyCounts = [];
$langCounts = [];
if ($projectId) {
    $where = 'project_id = ?';
    $params = [$projectId];
    if ($statusFilter) { $where .= ' AND status = ?'; $params[] = $statusFilter; }
    if ($langFilter) { $where .= ' AND language = ?'; $params[] = $langFilter; }
    $copyStmt = $db->prepare("SELECT * FROM ad_copy WHERE {$where} ORDER BY campaign_name, ad_group_name, copy_type, id");
    $copyStmt->execute($params);
    $adCopy = $copyStmt->fetchAll();
    $ccStmt = $db->prepare('SELECT status, COUNT(*) as cnt FROM ad_copy WHERE project_id = ? GROUP BY status');
    $ccStmt->execute([$projectId]);
    foreach ($ccStmt->fetchAll() as $r) $copyCounts[$r['status']] = (int) $r['cnt'];
    $lcStmt = $db->prepare('SELECT language, COUNT(*) as cnt FROM ad_copy WHERE project_id = ? GROUP BY language ORDER BY language');
    $lcStmt->execute([$projectId]);
    foreach ($lcStmt->fetchAll() as $r) $langCounts[$r['language']] = (int) $r['cnt'];
}

$statusCounts = [];
$statusCounts['all'] = array_sum($copyCounts);
foreach ($statuses as $k => $v) { if ($k !== '') $statusCounts[$k] = $copyCounts[$k] ?? 0; }

$langParam = $langFilter ? '&lang=' . e($langFilter) : '';
?>

<div class="fbar">
 <?php foreach ($statuses as $k => $v): $on = ($statusFilter ?? '') === $k; $href = "?view=copy&project={$projectId}" . ($k ? "&status={$k}" : '') . $langParam; $c = $k === '' ? ($statusCounts['all'] ?? 0) : ($statusCounts[$k] ?? 0); ?>
 <a href="<?= $href ?>" class="fb <?= $on ? 'on' : '' ?>" style="color:<?= $v['color'] ?>;background:<?= $v['bg'] ?>"><?= $v['label'] ?> <span class="fc"><?= $c ?></span></a>
 <?php endforeach; ?>
</div>

<?php if (!empty($langCounts)): ?>
<?php $statusParam = $statusFilter ? '&status=' . e($statusFilter) : ''; ?>
<div class="fbar" style="margin-top:-16px">
 <a href="?view=copy&project=<?= $projectId ?><?= $statusParam ?>" class="fb <?= !$langFilter ? 'on' : '' ?>" style="color:#8b949e;background:#30363d">All <span class="fc"><?= array_sum($langCounts) ?></span></a>
 <?php foreach ($langCounts as $lc => $cnt): $lon = ($langFilter === $lc); ?>
 <a href="?view=copy&project=<?= $projectId ?>&lang=<?= e($lc) ?><?= $statusParam ?>" class="fb <?= $lon ? 'on' : '' ?>" style="color:var(--blue);background:#0d2240"><?= strtoupper($lc) ?> <span class="fc"><?= $cnt ?></span></a>
 <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($adCopy)): ?>
 <div class="empty"><div class="ic">&#128221;</div><h3>No ad copy<?= $statusFilter ? ' with "' . $statusFilter . '"' : '' ?></h3><p>Import with <code>php bin/import-copy.php</code> or generate via strategy.</p></div>
<?php else: ?>
 <?php $grouped = []; foreach ($adCopy as $c) { $k = ($c['campaign_name'] ?? 'Ungrouped') . ' > ' . ($c['ad_group_name'] ?? 'General'); $grouped[$k][] = $c; } ?>
 <div class="sec"><div class="sec-t">Ad Copy <span class="bg"><?= count($adCopy) ?></span></div>
 <?php foreach ($grouped as $g => $copies): ?>
  <div class="cgh"><?= e($g) ?></div>
  <div class="cgrid">
   <?php foreach ($copies as $c): $cst = $statuses[$c['status']] ?? $statuses['draft']; ?>
   <div class="ccard" id="c-<?= $c['id'] ?>">
    <div class="ch">
     <span class="ct-badge ct-<?= $c['copy_type'] ?>"><?= e($c['copy_type']) ?></span>
     <span><?php if (!empty($c['language']) && $c['language'] !== 'en'): ?><span class="cpin"><?= strtoupper(e($c['language'])) ?></span><?php endif; ?> <span style="color:<?= $cst['color'] ?>;font-size:11px;font-weight:600"><?= $cst['label'] ?></span></span>
    </div>
    <div class="cc"><?= e($c['content']) ?></div>
    <div class="cmeta">
     <span style="color:var(--t2)"><?= e($c['platform']) ?></span>
     <?php if (!empty($c['target_market']) && $c['target_market'] !== 'all'): ?><span class="cpin"><?= e($c['target_market']) ?></span><?php endif; ?>
     <?php if ($c['pin_position']): ?><span class="cpin">PIN <?= $c['pin_position'] ?></span><?php endif; ?>
     <span style="float:right">#<?= $c['id'] ?> &middot; <?= mb_strlen($c['content']) ?> chars</span>
    </div>
    <?php if (!empty($c['feedback'])): ?><div class="fb-text"><strong>Feedback:</strong> <?= e($c['feedback']) ?></div><?php endif; ?>
    <?php if (!empty($c['rejected_reason'])): ?><div class="rj-text"><strong>Rejected:</strong> <?= e($c['rejected_reason']) ?></div><?php endif; ?>
    <div class="acts">
     <button class="btn btn-a btn-sm" onclick="act('approve_copy',{copy_id:<?= $c['id'] ?>,project_id:<?= $projectId ?>})">&#10003;</button>
     <button class="btn btn-r btn-sm" onclick="modal('reject',<?= $c['id'] ?>,<?= $projectId ?>,'copy')">&#10007;</button>
     <button class="btn btn-f btn-sm" onclick="modal('feedback',<?= $c['id'] ?>,<?= $projectId ?>,'copy')">&#9998;</button>
    </div>
   </div>
   <?php endforeach; ?>
  </div>
 <?php endforeach; ?>
 </div>
<?php endif; ?>
