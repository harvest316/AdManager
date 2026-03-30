<?php
/** Campaign management tab — extracted from original index.php */

$budgets = [];
if ($projectId) {
    $bs = $db->prepare('SELECT * FROM budgets WHERE project_id = ? ORDER BY platform');
    $bs->execute([$projectId]);
    $budgets = $bs->fetchAll();
}

$allCampaigns = [];
if ($projectId) {
    $cs = $db->prepare('SELECT * FROM campaigns WHERE project_id = ? ORDER BY platform, name');
    $cs->execute([$projectId]);
    $allCampaigns = $cs->fetchAll();
}

$adGroupsByCampaign = [];
if ($projectId) {
    $agStmt = $db->prepare('SELECT * FROM ad_groups WHERE campaign_id IN (SELECT id FROM campaigns WHERE project_id = ?) ORDER BY name');
    $agStmt->execute([$projectId]);
    foreach ($agStmt->fetchAll() as $ag) {
        $adGroupsByCampaign[$ag['campaign_id']][] = $ag;
    }
}

$typeTooltips = [
    'search' => 'Text ads shown on Google Search results. Targets people actively searching for your product.',
    'pmax' => 'Performance Max: AI-driven ads across Search, Display, YouTube, Gmail, Maps.',
    'display' => 'Banner/image ads shown on websites in the Google Display Network.',
    'video' => 'Video ads on YouTube (skippable in-stream, bumper, Shorts).',
    'demand_gen' => 'Demand Gen: visual ads across YouTube, Gmail, and Discover feeds.',
    'shopping' => 'Product listing ads with images and prices. Requires a Merchant Center feed.',
    'conversions' => 'Meta campaign optimised for a specific conversion event.',
    'traffic' => 'Meta campaign optimised for link clicks to your website.',
    'awareness' => 'Meta campaign optimised for reach and impressions.',
    'engagement' => 'Meta campaign optimised for likes, comments, shares.',
];

$td = array_sum(array_column($budgets, 'daily_budget_aud'));
?>

<!-- Budget Overview -->
<div class="sec"><div class="sec-t">Budget</div>
<div class="bgrid">
 <?php foreach ($budgets as $b): $plat = strtolower($b['platform']); $bd = (float) $b['daily_budget_aud']; ?>
 <div class="bcard">
  <div class="pf" style="color:<?= $plat === 'google' ? 'var(--green)' : 'var(--blue)' ?>"><?= e($b['platform']) ?></div>
  <div class="am"><span class="editable-budget" data-type="platform" data-platform="<?= e($b['platform']) ?>" data-daily="<?= $bd ?>" data-monthly="0">$<?= number_format($bd, 2) ?></span><span style="font-size:14px;color:var(--t3)">/day</span></div>
  <div class="lb"><span class="editable-budget" data-type="platform" data-platform="<?= e($b['platform']) ?>" data-daily="<?= $bd ?>" data-monthly="1" style="font-size:14px;font-weight:600;color:var(--t2)">$<?= number_format($bd * 30.4, 0) ?></span><span style="color:var(--t3)">/mo</span></div>
 </div>
 <?php endforeach; ?>
 <div class="bcard" style="border-color:var(--blue)">
  <div class="pf" style="color:var(--blue)">Total</div>
  <div class="am"><span class="editable-budget" data-type="total" data-daily="<?= $td ?>" data-monthly="0">$<?= number_format($td, 2) ?></span><span style="font-size:14px;color:var(--t3)">/day</span></div>
  <div class="lb"><span class="editable-budget" data-type="total" data-daily="<?= $td ?>" data-monthly="1" style="font-size:14px;font-weight:600;color:var(--t2)">$<?= number_format($td * 30.4, 0) ?></span><span style="color:var(--t3)">/mo</span></div>
 </div>
</div>
<p style="font-size:11px;color:var(--t3);margin-top:8px">Click any amount to edit. Changing platform or total budgets proportionally adjusts campaign budgets.</p>
</div>

<!-- All Campaigns -->
<?php if (!empty($allCampaigns)): ?>
<?php $byPlatform = []; foreach ($allCampaigns as $c) $byPlatform[$c['platform']][] = $c; ?>
<?php foreach ($byPlatform as $plat => $camps): ?>
<div class="sec"><div class="sec-t"><span class="pb pb-<?= $plat ?>"><?= e($plat) ?></span> Campaigns <span class="bg"><?= count($camps) ?></span></div>
<table class="ct"><thead><tr><th>Campaign</th><th>Type</th><th>Daily</th><th>Monthly</th><th>Status</th><th>Action</th></tr></thead><tbody>
 <?php foreach ($camps as $c): $daily = (float) $c['daily_budget_aud']; $statusColor = $c['status'] === 'paused' ? 'var(--orange)' : ($c['status'] === 'active' ? 'var(--green)' : 'var(--t3)'); $tip = $typeTooltips[$c['type']] ?? ''; $ags = $adGroupsByCampaign[$c['id']] ?? []; ?>
 <tr>
  <td><strong><?= e($c['name']) ?></strong><?php if (!empty($c['external_id'])): ?><br><span style="color:var(--t3);font-size:11px"><?= e($c['external_id']) ?></span><?php endif; ?><br><button class="expand-btn" onclick="var r=document.getElementById('det-<?= $c['id'] ?>');r.classList.toggle('open');this.textContent=r.classList.contains('open')?'hide details':'show details'">show details</button></td>
  <td><span class="type-tip" title="<?= e($tip) ?>"><?= e($c['type']) ?></span></td>
  <td><span class="editable-budget" data-type="campaign" data-campaign-id="<?= $c['id'] ?>" data-daily="<?= $daily ?>" data-monthly="0">$<?= number_format($daily, 2) ?></span></td>
  <td><span class="editable-budget" data-type="campaign" data-campaign-id="<?= $c['id'] ?>" data-daily="<?= $daily ?>" data-monthly="1" style="color:var(--t2)">$<?= number_format($daily * 30.4, 0) ?></span></td>
  <td><span style="color:<?= $statusColor ?>;font-weight:600;font-size:12px;text-transform:uppercase"><?= e($c['status']) ?></span></td>
  <td><?php if ($c['status'] === 'draft' || $c['status'] === 'paused'): ?><button class="btn btn-e btn-sm" onclick="act('enable_campaign',{campaign_id:<?= $c['id'] ?>,project_id:<?= $projectId ?>})">Enable</button><?php endif; ?></td>
 </tr>
 <tr class="detail-row" id="det-<?= $c['id'] ?>">
  <td colspan="6"><div class="detail-content"><dl>
   <dt>Platform</dt><dd><?= e($c['platform']) ?></dd>
   <dt>Campaign type</dt><dd><?= e($tip ?: $c['type']) ?></dd>
   <?php if (!empty($c['external_id'])): ?><dt>External ID</dt><dd><?= e($c['external_id']) ?></dd><?php endif; ?>
   <dt>Created</dt><dd><?= e($c['created_at']) ?></dd>
   <?php if (!empty($ags)): ?>
   <dt>Ad groups (<?= count($ags) ?>)</dt>
   <?php foreach ($ags as $ag): ?>
   <dd style="margin-left:12px"><strong><?= e($ag['name']) ?></strong></dd>
   <?php endforeach; ?>
   <?php else: ?>
   <dt>Ad groups</dt><dd style="color:var(--t3)">None configured yet</dd>
   <?php endif; ?>
  </dl></div></td>
 </tr>
 <?php endforeach; ?>
</tbody></table></div>
<?php endforeach; ?>
<?php else: ?>
 <div class="empty"><div class="ic">&#128640;</div><h3>No campaigns</h3><p>Create campaigns with the strategy or CLI tools.</p></div>
<?php endif; ?>
