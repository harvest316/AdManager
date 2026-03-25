<?php

namespace AdManager\Creative;

use AdManager\DB;
use PDO;

class ReviewStore
{
    /**
     * List assets for a project, optionally filtered by status.
     */
    public function listByProject(int $projectId, ?string $status = null): array
    {
        $db = DB::get();

        $sql = 'SELECT * FROM assets WHERE project_id = :project_id';
        $params = [':project_id' => $projectId];

        if ($status !== null && $status !== '') {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * List assets linked to a strategy (via campaigns -> ad_groups -> ads -> ad_assets).
     */
    public function listByStrategy(int $strategyId): array
    {
        $db = DB::get();

        $sql = <<<'SQL'
            SELECT DISTINCT a.*
            FROM assets a
            INNER JOIN ad_assets aa ON aa.asset_id = a.id
            INNER JOIN ads ad ON ad.id = aa.ad_id
            INNER JOIN ad_groups ag ON ag.id = ad.ad_group_id
            INNER JOIN campaigns c ON c.id = ag.campaign_id
            WHERE c.strategy_id = :strategy_id
            ORDER BY a.created_at DESC
        SQL;

        $stmt = $db->prepare($sql);
        $stmt->execute([':strategy_id' => $strategyId]);
        return $stmt->fetchAll();
    }

    /**
     * Approve an asset.
     */
    public function approve(int $assetId): void
    {
        $db = DB::get();
        $stmt = $db->prepare('UPDATE assets SET status = :status WHERE id = :id');
        $stmt->execute([':status' => 'approved', ':id' => $assetId]);
    }

    /**
     * Reject an asset with a reason.
     */
    public function reject(int $assetId, string $reason): void
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'UPDATE assets SET status = :status, rejected_reason = :reason WHERE id = :id'
        );
        $stmt->execute([
            ':status' => 'rejected',
            ':reason' => $reason,
            ':id'     => $assetId,
        ]);
    }

    /**
     * Add feedback to an asset (sets status to 'feedback').
     */
    public function addFeedback(int $assetId, string $feedback): void
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'UPDATE assets SET status = :status, feedback = :feedback WHERE id = :id'
        );
        $stmt->execute([
            ':status'   => 'feedback',
            ':feedback' => $feedback,
            ':id'       => $assetId,
        ]);
    }

    /**
     * Mark an asset as overlaid (text/banner applied).
     */
    public function markOverlaid(int $assetId): void
    {
        $db = DB::get();
        $stmt = $db->prepare('UPDATE assets SET status = :status WHERE id = :id');
        $stmt->execute([':status' => 'overlaid', ':id' => $assetId]);
    }

    /**
     * Mark an asset as uploaded to platform.
     */
    public function markUploaded(int $assetId): void
    {
        $db = DB::get();
        $stmt = $db->prepare('UPDATE assets SET status = :status WHERE id = :id');
        $stmt->execute([':status' => 'uploaded', ':id' => $assetId]);
    }

    /**
     * Get a single asset by ID.
     */
    public function getById(int $assetId): ?array
    {
        $db = DB::get();
        $stmt = $db->prepare('SELECT * FROM assets WHERE id = :id');
        $stmt->execute([':id' => $assetId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get campaigns in 'paused' status for a project.
     */
    public function getPendingCampaigns(int $projectId): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'SELECT * FROM campaigns WHERE project_id = :project_id AND status = :status ORDER BY created_at DESC'
        );
        $stmt->execute([':project_id' => $projectId, ':status' => 'paused']);
        return $stmt->fetchAll();
    }

    /**
     * Enable a campaign (set status to 'active' in local DB).
     */
    public function enableCampaign(int $campaignId): void
    {
        $db = DB::get();
        $stmt = $db->prepare(
            "UPDATE campaigns SET status = :status, updated_at = datetime('now') WHERE id = :id"
        );
        $stmt->execute([':status' => 'active', ':id' => $campaignId]);
    }
}
