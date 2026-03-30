<?php
/** Creative assets review tab — extracted from original index.php */

$store = new \AdManager\Creative\ReviewStore();
$assets = $projectId ? $store->listByProject($projectId, $statusFilter) : [];
$allAssets = $projectId ? $store->listByProject($projectId) : [];

$statusCounts = ['all' => count($allAssets)];
foreach ($statuses as $k => $v) {
    if ($k !== '') $statusCounts[$k] = count(array_filter($allAssets, fn($a) => $a['status'] === $k));
}
?>

<?php $langParam = ''; ?>
<div class="fbar">
 <?php foreach ($statuses as $k => $v): $on = ($statusFilter ?? '') === $k; $href = "?view=creative&project={$projectId}" . ($k ? "&status={$k}" : ''); $c = $k === '' ? ($statusCounts['all'] ?? 0) : ($statusCounts[$k] ?? 0); ?>
 <a href="<?= $href ?>" class="fb <?= $on ? 'on' : '' ?>" style="color:<?= $v['color'] ?>;background:<?= $v['bg'] ?>"><?= $v['label'] ?> <span class="fc"><?= $c ?></span></a>
 <?php endforeach; ?>
</div>

<?php if (empty($assets)): ?>
 <div class="empty"><div class="ic">&#127912;</div><h3>No assets<?= $statusFilter ? ' with "' . $statusFilter . '"' : '' ?></h3><p>Generate with <code>php bin/generate-creative.php</code>.</p></div>
<?php else: ?>
 <div class="sec"><div class="sec-t">Assets <span class="bg"><?= count($assets) ?></span></div>
 <div class="grid">
  <?php foreach ($assets as $a): $st = $statuses[$a['status']] ?? $statuses['draft']; $qa = $a['cv_qa_status'] ?? null; $qi = $a['cv_qa_issues'] ? json_decode($a['cv_qa_issues'], true) : []; $dims = ''; if (!empty($a['width']) && !empty($a['height'])) $dims = $a['width'] . 'x' . $a['height']; if (!empty($a['duration_seconds'])) $dims .= ($dims ? ' / ' : '') . round($a['duration_seconds'], 1) . 's'; ?>
  <div class="card" id="a-<?= $a['id'] ?>">
   <div class="prev">
    <?php if ($a['type'] === 'image' && $a['local_path']): ?><img src="assets.php?path=<?= urlencode($a['local_path']) ?>" loading="lazy">
    <?php elseif ($a['type'] === 'video' && $a['local_path']): ?><video src="assets.php?path=<?= urlencode($a['local_path']) ?>" muted preload="metadata" onmouseenter="this.play()" onmouseleave="this.pause();this.currentTime=0"></video>
    <?php else: ?><div style="font-size:48px;color:var(--t3)">&#128196;</div><?php endif; ?>
    <span class="badge-tl"><?= e($a['type']) ?></span>
    <span class="badge-tr" style="color:<?= $st['color'] ?>;background:<?= $st['bg'] ?>"><?= $st['label'] ?></span>
    <?php if ($dims): ?><span class="badge-br"><?= $dims ?></span><?php endif; ?>
    <?php if ($qa): ?><span class="badge-bl qa-<?= $qa ?>"><?= strtoupper($qa) ?></span><?php endif; ?>
   </div>
   <div class="body">
    <div class="prompt"><?= e(truncate($a['generation_prompt'] ?? 'No prompt', 80)) ?></div>
    <div class="meta">
     <?php if (!empty($a['generation_model'])): ?><span>&#9881; <?= e($a['generation_model']) ?></span><?php endif; ?>
     <?php if ($a['generation_cost_usd'] !== null): ?><span>$<?= number_format((float)$a['generation_cost_usd'], 4) ?></span><?php endif; ?>
     <span><?= timeAgo($a['created_at']) ?></span><span style="color:var(--t3)">#<?= $a['id'] ?></span>
    </div>
    <?php if (!empty($qi)): ?><div class="qa-text"><strong>QA:</strong><ul><?php foreach ($qi as $i): ?><li><strong>[<?= e($i['category'] ?? '?') ?>]</strong> <?= e($i['description'] ?? '') ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (!empty($a['feedback'])): ?><div class="fb-text"><strong>Feedback:</strong> <?= e($a['feedback']) ?></div><?php endif; ?>
    <?php if (!empty($a['rejected_reason'])): ?><div class="rj-text"><strong>Rejected:</strong> <?= e($a['rejected_reason']) ?></div><?php endif; ?>
    <div class="acts">
     <button class="btn btn-a" onclick="act('approve',{asset_id:<?= $a['id'] ?>,project_id:<?= $projectId ?>})">&#10003; Approve</button>
     <button class="btn btn-r" onclick="modal('reject',<?= $a['id'] ?>,<?= $projectId ?>,'asset')">&#10007;</button>
     <button class="btn btn-f" onclick="modal('feedback',<?= $a['id'] ?>,<?= $projectId ?>,'asset')">&#9998;</button>
    </div>
   </div>
  </div>
  <?php endforeach; ?>
 </div></div>
<?php endif; ?>
