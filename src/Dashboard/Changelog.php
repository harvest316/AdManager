<?php

namespace AdManager\Dashboard;

use AdManager\DB;

/**
 * Read/write operations for the changelog table.
 */
class Changelog
{
    /**
     * Log an optimisation decision or action.
     */
    public static function log(
        int $projectId,
        string $category,
        string $action,
        string $summary,
        ?array $detail = null,
        ?string $entityType = null,
        ?int $entityId = null,
        string $actor = 'system'
    ): int {
        $db = DB::get();
        $stmt = $db->prepare(
            'INSERT INTO changelog (project_id, category, action, summary, detail_json, entity_type, entity_id, actor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $projectId,
            $category,
            $action,
            $summary,
            $detail ? json_encode($detail) : null,
            $entityType,
            $entityId,
            $actor,
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * List changelog entries for a project.
     */
    public static function list(
        int $projectId,
        ?string $category = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $db = DB::get();
        $where = 'project_id = ?';
        $params = [$projectId];

        if ($category) {
            $where .= ' AND category = ?';
            $params[] = $category;
        }

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare(
            "SELECT * FROM changelog WHERE {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Count changelog entries for a project (for pagination).
     */
    public static function count(int $projectId, ?string $category = null): int
    {
        $db = DB::get();
        $where = 'project_id = ?';
        $params = [$projectId];

        if ($category) {
            $where .= ' AND category = ?';
            $params[] = $category;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM changelog WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get recent entries across all projects (for multi-project overview).
     */
    public static function recent(int $limit = 10): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'SELECT cl.*, p.display_name AS project_name
             FROM changelog cl
             JOIN projects p ON p.id = cl.project_id
             ORDER BY cl.created_at DESC
             LIMIT ?'
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Category labels and colours for UI rendering.
     */
    public static function categories(): array
    {
        return [
            'split_test' => ['label' => 'Split Test', 'color' => '#bc8cff', 'icon' => '&#9878;'],
            'budget'     => ['label' => 'Budget',     'color' => '#3fb950', 'icon' => '&#36;'],
            'creative'   => ['label' => 'Creative',   'color' => '#58a6ff', 'icon' => '&#127912;'],
            'keyword'    => ['label' => 'Keyword',    'color' => '#d29922', 'icon' => '&#128269;'],
            'campaign'   => ['label' => 'Campaign',   'color' => '#f0883e', 'icon' => '&#128640;'],
            'strategy'   => ['label' => 'Strategy',   'color' => '#8b949e', 'icon' => '&#128203;'],
            'system'     => ['label' => 'System',     'color' => '#6e7681', 'icon' => '&#9881;'],
            'manual'     => ['label' => 'Manual',     'color' => '#e6edf3', 'icon' => '&#9998;'],
        ];
    }
}
