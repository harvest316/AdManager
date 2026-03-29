<?php

namespace AdManager\Copy;

use AdManager\DB;

class Store
{
    /**
     * Bulk-insert parsed copy items.
     *
     * @param array $items Each item: [platform, campaign_name, ad_group_name, copy_type, content, char_limit, pin_position, language, target_market]
     * @return int[] Inserted IDs
     */
    public function bulkInsert(int $projectId, int $strategyId, array $items): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            "INSERT INTO ad_copy (project_id, strategy_id, platform, campaign_name, ad_group_name,
             copy_type, content, char_limit, pin_position, language, target_market)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $ids = [];
        foreach ($items as $item) {
            $stmt->execute([
                $projectId,
                $strategyId,
                $item['platform'],
                $item['campaign_name'] ?? null,
                $item['ad_group_name'] ?? null,
                $item['copy_type'],
                $item['content'],
                $item['char_limit'] ?? null,
                $item['pin_position'] ?? null,
                $item['language'] ?? 'en',
                $item['target_market'] ?? 'all',
            ]);
            $ids[] = (int) $db->lastInsertId();
        }

        return $ids;
    }

    /**
     * List copy items for a project with optional filters.
     */
    public function listByProject(int $projectId, ?string $status = null, ?string $copyType = null, ?string $platform = null): array
    {
        $db = DB::get();
        $sql = 'SELECT * FROM ad_copy WHERE project_id = :project_id';
        $params = [':project_id' => $projectId];

        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }
        if ($copyType !== null) {
            $sql .= ' AND copy_type = :copy_type';
            $params[':copy_type'] = $copyType;
        }
        if ($platform !== null) {
            $sql .= ' AND platform = :platform';
            $params[':platform'] = $platform;
        }

        $sql .= ' ORDER BY campaign_name, copy_type, pin_position, id';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get a single copy item by ID.
     */
    public function getById(int $id): ?array
    {
        $db = DB::get();
        $stmt = $db->prepare('SELECT * FROM ad_copy WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get all copy items for a specific campaign.
     */
    public function getByCampaign(int $projectId, string $campaignName): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'SELECT * FROM ad_copy WHERE project_id = ? AND campaign_name = ? ORDER BY copy_type, pin_position, id'
        );
        $stmt->execute([$projectId, $campaignName]);
        return $stmt->fetchAll();
    }

    /**
     * Get approved copy for a campaign filtered by type.
     */
    public function getApprovedForCampaign(int $projectId, string $campaignName, string $copyType): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            "SELECT * FROM ad_copy
             WHERE project_id = ? AND campaign_name = ? AND copy_type = ? AND status = 'approved'
             ORDER BY pin_position, id"
        );
        $stmt->execute([$projectId, $campaignName, $copyType]);
        return $stmt->fetchAll();
    }

    /**
     * Update QA results for a copy item.
     */
    public function updateQA(int $id, string $qaStatus, array $qaIssues, ?int $qaScore = null): void
    {
        $db = DB::get();
        $stmt = $db->prepare(
            "UPDATE ad_copy SET qa_status = ?, qa_issues = ?, qa_score = ?, updated_at = datetime('now') WHERE id = ?"
        );
        $stmt->execute([$qaStatus, json_encode($qaIssues), $qaScore, $id]);
    }

    /**
     * Set status for a copy item.
     */
    public function setStatus(int $id, string $status): void
    {
        $db = DB::get();
        $stmt = $db->prepare("UPDATE ad_copy SET status = ?, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$status, $id]);
    }

    /**
     * Approve a copy item.
     */
    public function approve(int $id): void
    {
        $this->setStatus($id, 'approved');
    }

    /**
     * Unapprove a copy item (revert to draft).
     */
    public function unapprove(int $id): void
    {
        $this->setStatus($id, 'draft');
    }

    /**
     * Reject a copy item with a reason.
     */
    public function reject(int $id, string $reason): void
    {
        $db = DB::get();
        $stmt = $db->prepare(
            "UPDATE ad_copy SET status = 'rejected', rejected_reason = ?, updated_at = datetime('now') WHERE id = ?"
        );
        $stmt->execute([$reason, $id]);
    }

    /**
     * Add feedback to a copy item.
     */
    public function addFeedback(int $id, string $feedback): void
    {
        $db = DB::get();
        $stmt = $db->prepare(
            "UPDATE ad_copy SET status = 'feedback', feedback = ?, updated_at = datetime('now') WHERE id = ?"
        );
        $stmt->execute([$feedback, $id]);
    }

    /**
     * Flag a copy item (e.g. after policy change re-check).
     */
    public function flag(int $id, string $reason): void
    {
        $db = DB::get();
        $stmt = $db->prepare(
            "UPDATE ad_copy SET status = 'flagged', qa_issues = ?, updated_at = datetime('now') WHERE id = ?"
        );
        $stmt->execute([$reason, $id]);
    }

    /**
     * Count copy items by status for a project.
     */
    public function countByStatus(int $projectId): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'SELECT status, COUNT(*) as count FROM ad_copy WHERE project_id = ? GROUP BY status'
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }
        return $counts;
    }

    /**
     * Check if copy already exists for a strategy (to avoid re-import).
     */
    public function existsForStrategy(int $strategyId): bool
    {
        $db = DB::get();
        $stmt = $db->prepare('SELECT COUNT(*) FROM ad_copy WHERE strategy_id = ?');
        $stmt->execute([$strategyId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Delete all copy for a strategy (used with --force re-import).
     */
    public function deleteForStrategy(int $strategyId): int
    {
        $db = DB::get();
        $stmt = $db->prepare('DELETE FROM ad_copy WHERE strategy_id = ?');
        $stmt->execute([$strategyId]);
        return $stmt->rowCount();
    }

    /**
     * Get all approved copy items for a platform (for policy re-check).
     */
    public function getApprovedByPlatform(string $platform): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            "SELECT * FROM ad_copy WHERE platform = ? AND status = 'approved' ORDER BY project_id, campaign_name"
        );
        $stmt->execute([$platform]);
        return $stmt->fetchAll();
    }
}
