<?php
/** Strategy viewer with section annotations */

use AdManager\Dashboard\Changelog;

$strategies = [];
$currentStrategy = null;
$annotations = [];

if ($projectId) {
    $ss = $db->prepare('SELECT id, name, platform, campaign_type, model, created_at FROM strategies WHERE project_id = ? ORDER BY created_at DESC');
    $ss->execute([$projectId]);
    $strategies = $ss->fetchAll();

    $stratId = isset($_GET['strategy_id']) ? (int) $_GET['strategy_id'] : ($strategies[0]['id'] ?? null);
    if ($stratId) {
        $sStmt = $db->prepare('SELECT * FROM strategies WHERE id = ?');
        $sStmt->execute([$stratId]);
        $currentStrategy = $sStmt->fetch();

        // Load annotations
        $aStmt = $db->prepare('SELECT * FROM strategy_annotations WHERE strategy_id = ? ORDER BY created_at');
        $aStmt->execute([$stratId]);
        $annotations = $aStmt->fetchAll();
    }
}

// Group annotations by section_anchor
$annotationsBySection = [];
foreach ($annotations as $ann) {
    $annotationsBySection[$ann['section_anchor']][] = $ann;
}

// Simple markdown renderer that inserts annotation hooks
function renderStrategyMarkdown(string $md, array $annotationsBySection, int $strategyId): string
{
    $md = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');

    // Headers — track section anchors
    $md = preg_replace_callback('/^(#{1,6})\s+(.+)$/m', function ($m) use ($annotationsBySection, $strategyId) {
        $level = strlen($m[1]);
        $text = $m[2];
        $anchor = substr(trim($m[0]), 0, 60);
        $tag = "h{$level}";
        $style = $level === 2 ? ' style="margin-top:24px;padding-bottom:8px;border-bottom:1px solid var(--border)"' : '';
        $html = "<div class=\"strat-section\" id=\"sec-" . md5($anchor) . "\">";
        $html .= "<div class=\"strat-heading\"><{$tag}{$style}>{$text}</{$tag}>";
        $html .= '<button class="strat-note-btn" onclick="addAnnotation(' . $strategyId . ',\'' . htmlspecialchars(addslashes($anchor), ENT_QUOTES) . '\')" title="Provide feedback">&#9998;</button>';
        $html .= '</div>';

        // Show existing annotations
        if (!empty($annotationsBySection[$anchor])) {
            foreach ($annotationsBySection[$anchor] as $ann) {
                $statusCls = $ann['status'] === 'resolved' ? 'opacity:.5' : '';
                $html .= '<div class="annotation" style="' . $statusCls . '">';
                $html .= '<div class="fb-text" style="margin:8px 0"><strong>Note:</strong> ' . htmlspecialchars($ann['comment'], ENT_QUOTES, 'UTF-8');
                $html .= '<br><span style="font-size:10px;color:var(--t3)">' . htmlspecialchars($ann['created_at']) . ' &middot; ' . htmlspecialchars($ann['status']);
                if ($ann['status'] === 'open') {
                    $html .= ' &middot; <button class="expand-btn" onclick="resolveAnnotation(' . $ann['id'] . ')">resolve</button>';
                }
                $html .= '</span></div></div>';
            }
        }

        $html .= '</div>';
        return $html;
    }, $md);

    // Bold and italic
    $md = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $md);
    $md = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $md);
    $md = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $md);

    // Inline code
    $md = preg_replace('/`([^`]+)`/', '<code style="background:var(--bg3);padding:2px 6px;border-radius:3px;font-size:13px">$1</code>', $md);

    // Blockquotes
    $md = preg_replace('/^&gt;\s?(.+)$/m', '<blockquote style="border-left:3px solid var(--blue);padding:8px 16px;margin:8px 0;color:var(--t2);background:var(--bg2);border-radius:0 var(--r) var(--r) 0">$1</blockquote>', $md);

    // Horizontal rules
    $md = preg_replace('/^---+$/m', '<hr style="border:none;border-top:1px solid var(--border);margin:24px 0">', $md);

    // Tables
    $md = preg_replace_callback('/(?:^\|.+\|$\n?)+/m', function ($m) {
        $rows = array_filter(explode("\n", trim($m[0])));
        $html = '<table class="ct" style="margin:12px 0"><thead>';
        $isHeader = true;
        foreach ($rows as $row) {
            $row = trim($row, '| ');
            if (preg_match('/^[\s|:-]+$/', $row)) { $isHeader = false; $html .= '</thead><tbody>'; continue; }
            $cells = array_map('trim', explode('|', $row));
            $tag = $isHeader ? 'th' : 'td';
            $html .= '<tr>';
            foreach ($cells as $cell) $html .= "<{$tag}>{$cell}</{$tag}>";
            $html .= '</tr>';
        }
        $html .= ($isHeader ? '</thead>' : '</tbody>') . '</table>';
        return $html;
    }, $md);

    // Lists
    $md = preg_replace('/^\d+\.\s+(.+)$/m', '<li style="margin-left:20px;list-style:decimal">$1</li>', $md);
    $md = preg_replace('/^[-*]\s+(.+)$/m', '<li style="margin-left:20px;list-style:disc">$1</li>', $md);

    // Paragraphs
    $md = preg_replace('/\n\n+/', '</p><p style="margin:8px 0">', $md);
    return '<p style="margin:8px 0">' . $md . '</p>';
}
?>

<?php if (!empty($strategies)): ?>
<div class="sec" style="margin-bottom:16px">
 <div class="sec-t">Strategy Documents <span class="bg"><?= count($strategies) ?></span></div>
 <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
  <?php foreach ($strategies as $s): $isActive = $currentStrategy && (int) $currentStrategy['id'] === (int) $s['id']; ?>
  <a href="?view=strategies&project=<?= $projectId ?>&strategy_id=<?= $s['id'] ?>"
     class="fb <?= $isActive ? 'on' : '' ?>"
     style="color:<?= $isActive ? 'var(--blue)' : 'var(--t2)' ?>;background:<?= $isActive ? '#0d2240' : 'var(--bg3)' ?>">
    <?= e($s['name']) ?> <span class="fc"><?= e($s['platform']) ?></span>
  </a>
  <?php endforeach; ?>
 </div>
</div>

<?php if ($currentStrategy): ?>
<div class="sec">
 <div class="sec-t"><?= e($currentStrategy['name']) ?> <span class="bg"><?= e($currentStrategy['platform']) ?> / <?= e($currentStrategy['campaign_type']) ?></span></div>
 <div style="font-size:12px;color:var(--t3);margin-bottom:16px">
  Model: <?= e($currentStrategy['model']) ?> &middot; Generated: <?= e($currentStrategy['created_at']) ?>
  <?php $openCount = count(array_filter($annotations, fn($a) => $a['status'] === 'open')); ?>
  <?php if ($openCount > 0): ?>&middot; <span style="color:var(--orange)"><?= $openCount ?> open note<?= $openCount > 1 ? 's' : '' ?></span><?php endif; ?>
 </div>
 <div class="strategy-content"><?= renderStrategyMarkdown($currentStrategy['full_strategy'], $annotationsBySection, (int) $currentStrategy['id']) ?></div>
</div>
<?php else: ?>
 <div class="empty"><div class="ic">&#128203;</div><h3>Select a strategy</h3><p>Click one of the strategies above to view it.</p></div>
<?php endif; ?>
<?php else: ?>
 <div class="empty"><div class="ic">&#128203;</div><h3>No strategies</h3><p>Generate a strategy first: <code>php bin/strategy.php generate --project <?= e($currentProject['name'] ?? 'name') ?></code></p></div>
<?php endif; ?>
