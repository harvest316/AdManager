<?php
/** Project settings — CRUD, goals, sync history */

use AdManager\Dashboard\{PerformanceQuery, Metrics, ConversionPlanner};

// Load project details
$goals = [];
$syncHistory = [];
$conversionActions = [];
if ($projectId) {
    $conversionActions = ConversionPlanner::listForProject($projectId);
    $gs = $db->prepare('SELECT * FROM goals WHERE project_id = ?');
    $gs->execute([$projectId]);
    $goals = $gs->fetchAll();

    $sh = $db->prepare('SELECT * FROM sync_jobs WHERE project_id = ? ORDER BY created_at DESC LIMIT 20');
    $sh->execute([$projectId]);
    $syncHistory = $sh->fetchAll();
}
?>

<!-- Project list -->
<div class="sec">
 <div class="sec-t">Projects <span class="bg"><?= count($projects) ?></span>
  <button class="btn btn-a btn-sm" style="margin-left:auto" onclick="document.getElementById('newProjectModal').classList.add('on')">+ New Project</button>
 </div>

 <?php foreach ($projects as $p): $isCurrent = (int) $p['id'] === $projectId; ?>
 <div class="bcard" style="margin-bottom:12px;<?= $isCurrent ? 'border-color:var(--blue)' : '' ?>">
  <div style="display:flex;justify-content:space-between;align-items:flex-start">
   <div>
    <div style="font-size:16px;font-weight:600"><?= e($p['display_name'] ?? $p['name']) ?></div>
    <?php if (!empty($p['website_url'])): ?><div style="font-size:12px;color:var(--t3)"><?= e($p['website_url']) ?></div><?php endif; ?>
    <?php if (!empty($p['description'])): ?><div style="font-size:13px;color:var(--t2);margin-top:4px"><?= e($p['description']) ?></div><?php endif; ?>
   </div>
   <a href="?view=overview&project=<?= $p['id'] ?>" class="btn btn-sm" style="flex:none;background:var(--bg3);color:var(--blue);border-color:var(--border)">Dashboard</a>
  </div>
 </div>
 <?php endforeach; ?>
</div>

<?php if ($projectId && $currentProject): ?>
<!-- Current project goals -->
<div class="sec">
 <div class="sec-t">Goals — <?= e($currentProject['display_name'] ?? $currentProject['name']) ?></div>
 <?php if (!empty($goals)): ?>
 <table class="ct">
  <thead><tr><th>Metric</th><th>Platform</th><th>Target</th><th>Current</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($goals as $g):
    $actual = $g['current_value'];
    $target = (float) $g['target_value'];
    $onTrack = $actual !== null ? ($g['metric'] === 'cpa' ? $actual <= $target : $actual >= $target) : null;
    $color = $onTrack === null ? 'var(--t3)' : ($onTrack ? 'var(--green)' : 'var(--red)');
  ?>
  <tr>
   <td style="font-weight:600;text-transform:uppercase"><?= e($g['metric']) ?></td>
   <td><?= e($g['platform'] ?? 'all') ?></td>
   <td><?= $g['metric'] === 'cpa' ? Metrics::money($target) : ($g['metric'] === 'roas' ? Metrics::roas($target) : Metrics::pct($target)) ?></td>
   <td style="color:<?= $color ?>"><?= $actual !== null ? ($g['metric'] === 'cpa' ? Metrics::money((float)$actual) : ($g['metric'] === 'roas' ? Metrics::roas((float)$actual) : Metrics::pct((float)$actual))) : '—' ?></td>
   <td><span style="color:<?= $color ?>;font-weight:600;font-size:12px"><?= $onTrack === null ? 'No data' : ($onTrack ? 'On track' : 'Off track') ?></span></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
 </table>
 <?php else: ?>
  <p style="color:var(--t3);font-size:13px">No goals set. Use <code>php bin/project.php goals <?= e($currentProject['name']) ?> --cpa 30 --platform google</code></p>
 <?php endif; ?>
</div>

<!-- Sync History -->
<div class="sec">
 <div class="sec-t">Sync History</div>
 <?php if (!empty($syncHistory)): ?>
 <table class="ct">
  <thead><tr><th>Time</th><th>Platform</th><th>Days</th><th>Status</th><th>Duration</th></tr></thead>
  <tbody>
  <?php foreach ($syncHistory as $s):
    $statusColor = match ($s['status']) { 'complete' => 'var(--green)', 'failed' => 'var(--red)', 'running' => 'var(--orange)', default => 'var(--t3)' };
    $duration = ($s['completed_at'] && $s['started_at']) ? (strtotime($s['completed_at']) - strtotime($s['started_at'])) . 's' : '—';
  ?>
  <tr>
   <td><?= e($s['created_at']) ?></td>
   <td><?= e($s['platform']) ?></td>
   <td><?= (int) $s['days'] ?></td>
   <td><span style="color:<?= $statusColor ?>;font-weight:600;font-size:12px;text-transform:uppercase"><?= e($s['status']) ?></span></td>
   <td><?= $duration ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
 </table>
 <?php else: ?>
  <p style="color:var(--t3);font-size:13px">No syncs recorded yet. Use the "Sync Now" button in the sidebar.</p>
 <?php endif; ?>
</div>
<!-- Conversion Actions -->
<div class="sec">
 <div class="sec-t">Conversion Actions
  <button class="btn btn-a btn-sm" style="margin-left:auto" onclick="planConversions()">Auto-Plan</button>
 </div>

 <?php if (!empty($conversionActions)): ?>
 <table class="ct">
  <thead><tr><th>Action</th><th>Event</th><th>Platform</th><th>Type</th><th>Primary</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($conversionActions as $ca):
    $statusColor = match ($ca['status']) { 'verified' => 'var(--green)', 'created' => 'var(--blue)', 'failed' => 'var(--red)', default => 'var(--t3)' };
    $platColor = match ($ca['platform']) { 'google' => 'var(--green)', 'meta' => 'var(--blue)', 'ga4' => 'var(--orange)', default => 'var(--t3)' };
  ?>
  <tr>
   <td><strong><?= e($ca['name']) ?></strong></td>
   <td><code style="background:var(--bg3);padding:2px 6px;border-radius:3px;font-size:12px"><?= e($ca['event_name']) ?></code></td>
   <td><span style="color:<?= $platColor ?>;font-weight:600;font-size:11px;text-transform:uppercase"><?= e($ca['platform']) ?></span></td>
   <td style="font-size:12px;color:var(--t2)"><?= e($ca['category']) ?></td>
   <td><?= $ca['is_primary'] ? '<span style="color:var(--green)">Primary</span>' : '<span style="color:var(--t3)">Secondary</span>' ?></td>
   <td>
    <span style="color:<?= $statusColor ?>;font-weight:600;font-size:12px;text-transform:uppercase"><?= e($ca['status']) ?></span>
    <?php if ($ca['verification_note']): ?>
    <br><button class="expand-btn" onclick="var d=this.nextElementSibling;d.style.display=d.style.display==='none'?'block':'none'">details</button>
    <div style="display:none;font-size:11px;color:var(--t2);margin-top:4px;white-space:pre-wrap"><?= e($ca['verification_note']) ?></div>
    <?php endif; ?>
   </td>
   <td>
    <?php if ($ca['status'] === 'planned'): ?>
    <button class="btn btn-e btn-sm" onclick="provisionAction(<?= $ca['id'] ?>)">Create</button>
    <?php endif; ?>
   </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
 </table>

 <?php if ($ca['trigger_value'] ?? false): ?>
 <p style="font-size:11px;color:var(--t3);margin-top:8px">Trigger URLs are suggestions based on project URL. Adjust before creating.</p>
 <?php endif; ?>

 <?php else: ?>
  <p style="color:var(--t3);font-size:13px">No conversion actions configured. Click "Auto-Plan" to generate recommendations based on your project and strategy.</p>
 <?php endif; ?>
</div>

<?php endif; ?>

<!-- New Project Modal -->
<div class="mo" id="newProjectModal">
 <div class="mdl">
  <h3>New Project</h3>
  <form onsubmit="return createProject()">
   <div style="margin-bottom:12px">
    <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px">Name (slug)</label>
    <input type="text" id="npName" required placeholder="my-project" style="width:100%;background:var(--bg3);color:var(--t1);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:14px">
   </div>
   <div style="margin-bottom:12px">
    <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px">Display Name</label>
    <input type="text" id="npDisplay" required placeholder="My Project" style="width:100%;background:var(--bg3);color:var(--t1);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:14px">
   </div>
   <div style="margin-bottom:12px">
    <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px">Website URL</label>
    <input type="url" id="npUrl" placeholder="https://example.com" style="width:100%;background:var(--bg3);color:var(--t1);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:14px">
   </div>
   <div style="margin-bottom:16px">
    <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px">Description</label>
    <textarea id="npDesc" placeholder="What this project promotes..." style="width:100%;min-height:60px;background:var(--bg3);color:var(--t1);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:14px;font-family:inherit;resize:vertical"></textarea>
   </div>
   <div class="ma">
    <button type="button" class="mc" onclick="cls('newProjectModal')">Cancel</button>
    <button type="submit" class="ms" style="background:var(--green)">Create</button>
   </div>
  </form>
 </div>
</div>

<script>
function createProject() {
    var data = {
        action: 'project_create',
        name: document.getElementById('npName').value,
        display_name: document.getElementById('npDisplay').value,
        website_url: document.getElementById('npUrl').value,
        description: document.getElementById('npDesc').value
    };
    var f = new FormData();
    for (var k in data) f.append(k, data[k]);
    fetch('api.php', {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: f})
    .then(function(r) { return r.json(); })
    .then(function(j) {
        if (j.ok) { toast('Project created', 's'); setTimeout(function() { location.href = '?view=overview&project=' + j.id; }, 500); }
        else toast(j.error || 'Failed', 'e');
    }).catch(function() { toast('Network error', 'e'); });
    cls('newProjectModal');
    return false;
}

function planConversions() {
    act('conversion_plan', {project_id: <?= $projectId ?>});
    setTimeout(function() { location.reload(); }, 800);
}

function provisionAction(actionId) {
    if (!confirm('Create this conversion action on the platform?')) return;
    act('conversion_provision', {action_id: actionId, project_id: <?= $projectId ?>});
    setTimeout(function() { location.reload(); }, 1500);
}
</script>
