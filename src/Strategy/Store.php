<?php

namespace AdManager\Strategy;

use AdManager\DB;

class Store
{
    /**
     * Save a strategy to the database.
     *
     * @return int The new strategy ID
     */
    public function save(
        int $projectId,
        string $name,
        string $platform,
        string $campaignType,
        string $fullStrategy,
        string $model = 'claude'
    ): int {
        $db = DB::get();
        $stmt = $db->prepare(
            'INSERT INTO strategies (project_id, name, platform, campaign_type, full_strategy, model)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$projectId, $name, $platform, $campaignType, $fullStrategy, $model]);

        return (int) $db->lastInsertId();
    }

    /**
     * Get a single strategy by ID.
     */
    public function get(int $id): ?array
    {
        $db = DB::get();
        $stmt = $db->prepare('SELECT * FROM strategies WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * List all strategies for a project.
     */
    public function listByProject(int $projectId): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'SELECT id, name, platform, campaign_type, model, created_at
             FROM strategies
             WHERE project_id = ?
             ORDER BY created_at DESC'
        );
        $stmt->execute([$projectId]);

        return $stmt->fetchAll();
    }

    /**
     * Get the latest strategy for a project + platform combination.
     */
    public function getLatest(int $projectId, string $platform): ?array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'SELECT * FROM strategies
             WHERE project_id = ? AND platform = ?
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $stmt->execute([$projectId, $platform]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Delete a strategy by ID.
     */
    public function delete(int $id): void
    {
        $db = DB::get();

        // Unlink any campaigns referencing this strategy
        $db->prepare('UPDATE campaigns SET strategy_id = NULL WHERE strategy_id = ?')
           ->execute([$id]);

        $db->prepare('DELETE FROM strategies WHERE id = ?')
           ->execute([$id]);
    }
}
