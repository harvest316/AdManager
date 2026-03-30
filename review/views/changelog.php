<?php
/** Changelog — chronological log of all optimisation decisions */

use AdManager\Dashboard\Changelog;

$catFilter = $_GET['category'] ?? null;
$limit = 50;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

$entries = Changelog::list($projectId, $catFilter, $limit, $offset);
$totalCount = Changelog::count($projectId, $catFilter);
$categories = Changelog::categories();
?>

<!-- Category filter -->
<div class="fbar">
 <a href="?view=changelog&project=<?= $projectId ?>" class="fb <?= !$catFilter ? 'on' : '' ?>" style="color:var(--t2);background:var(--bg3)">All <span class="fc"><?= Changelog::count($projectId) ?></span></a>
 <?php foreach ($categories as $key => $cat): $count = Changelog::count($projectId, $key); if ($count === 0) continue; ?>
 <a href="?view=changelog&project=<?= $projectId ?>&category=<?= $key ?>" class="fb <?= $catFilter === $key ? 'on' : '' ?>" style="color:<?= $cat['color'] ?>;background:var(--bg3)"><?= $cat['label'] ?> <span class="fc"><?= $count ?></span></a>
 <?php endforeach; ?>
</div>

<?php if (empty($entries)): ?>
 <div class="empty">
  <div class="ic">&#128203;</div>
  <h3>No changes logged<?= $catFilter ? ' for "' . e($catFilter) . '"' : '' ?></h3>
  <p>Optimisation decisions, split test outcomes, budget changes, and manual actions will appear here.</p>
 </div>
<?php else: ?>
 <div class="sec">
  <div class="sec-t">Change Log <span class="bg"><?= $totalCount ?></span></div>

  <?php $lastDate = ''; ?>
  <?php foreach ($entries as $entry): ?>
  <?php
    $cat = $categories[$entry['category']] ?? $categories['system'];
    $date = date('M j, Y', strtotime($entry['created_at']));
    $time = date('H:i', strtotime($entry['created_at']));
    $detail = $entry['detail_json'] ? json_decode($entry['detail_json'], true) : null;
  ?>

  <?php if ($date !== $lastDate): $lastDate = $date; ?>
  <div style="font-size:12px;font-weight:600;color:var(--t3);margin:20px 0 8px;padding-top:12px;border-top:1px solid var(--border)"><?= $date ?></div>
  <?php endif; ?>

  <div style="display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)">
   <!-- Time + category -->
   <div style="min-width:100px;flex-shrink:0">
    <div style="font-size:11px;color:var(--t3)"><?= $time ?></div>
    <div style="font-size:11px;font-weight:600;color:<?= $cat['color'] ?>;text-transform:uppercase;margin-top:2px"><?= $cat['icon'] ?> <?= $cat['label'] ?></div>
   </div>

   <!-- Summary + detail -->
   <div style="flex:1;min-width:0">
    <div style="font-size:14px;color:var(--t1);line-height:1.5"><?= e($entry['summary']) ?></div>
    <?php if ($detail): ?>
    <div style="margin-top:6px;font-size:12px;color:var(--t2)">
     <?php if (isset($detail['variants'])): ?>
      <?php foreach ($detail['variants'] as $v): ?>
       <span style="margin-right:12px">Ad #<?= $v['ad_id'] ?? '?' ?>: <?= round($v['value'] ?? 0, 2) ?>%</span>
      <?php endforeach; ?>
     <?php endif; ?>
     <?php if (isset($detail['platform'])): ?><span style="margin-right:12px">Platform: <?= e($detail['platform']) ?></span><?php endif; ?>
     <?php if (isset($detail['p_value'])): ?><span style="margin-right:12px">p=<?= round($detail['p_value'], 3) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>
   </div>

   <!-- Actor -->
   <div style="min-width:60px;text-align:right;flex-shrink:0">
    <span style="font-size:11px;color:var(--t3)"><?= e($entry['actor']) ?></span>
   </div>
  </div>
  <?php endforeach; ?>

  <!-- Pagination -->
  <?php if ($totalCount > $offset + $limit): ?>
  <div style="text-align:center;padding:20px">
   <a href="?view=changelog&project=<?= $projectId ?><?= $catFilter ? '&category=' . e($catFilter) : '' ?>&offset=<?= $offset + $limit ?>" class="btn btn-f" style="display:inline-flex;flex:none">Load more</a>
  </div>
  <?php endif; ?>
 </div>
<?php endif; ?>

<!-- Add manual note -->
<div style="margin-top:24px;padding:16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--r)">
 <div style="font-size:13px;font-weight:600;color:var(--t2);margin-bottom:8px">Add manual note</div>
 <form onsubmit="return addNote()">
  <textarea id="noteText" placeholder="What changed and why..." style="width:100%;min-height:60px;background:var(--bg3);color:var(--t1);border:1px solid var(--border);border-radius:6px;padding:10px;font-family:inherit;font-size:13px;resize:vertical;margin-bottom:8px"></textarea>
  <button type="submit" class="btn btn-f" style="flex:none;display:inline-flex">Add note</button>
 </form>
</div>

<script>
function addNote() {
    var txt = document.getElementById('noteText').value.trim();
    if (!txt) return false;
    act('changelog_add', {project_id: <?= $projectId ?>, category: 'manual', action: 'note', summary: txt});
    setTimeout(function() { location.reload(); }, 500);
    return false;
}
</script>
