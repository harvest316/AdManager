<?php

namespace AdManager\Banner;

use AdManager\DB;
use RuntimeException;

/**
 * Banner network campaign management — DB-only, no API.
 *
 * All operations read/write the local AdManager SQLite database.
 * The 'platform' column is set to the network name from Client (default 'banner').
 */
class Campaign
{
    private Client $client;

    public function __construct()
    {
        $this->client = Client::get();
    }

    /**
     * Create a new banner campaign record in the DB.
     *
     * @param array $config [
     *   'name'            => 'MyBrand — Banner — AU',
     *   'project_id'      => 1,
     *   'type'            => 'display',
     *   'daily_budget'    => 50.00,       // AUD
     *   'status'          => 'paused',    // default 'paused'
     *   'bid_strategy'    => 'manual_cpc',
     * ]
     * @return int Local campaign ID
     */
    public function create(array $config): int
    {
        $db = DB::get();

        $stmt = $db->prepare(
            'INSERT INTO campaigns
               (project_id, platform, name, type, status, daily_budget_aud, bid_strategy, created_at, updated_at)
             VALUES
               (:project_id, :platform, :name, :type, :status, :daily_budget_aud, :bid_strategy, datetime(\'now\'), datetime(\'now\'))'
        );

        $stmt->execute([
            ':project_id'       => $config['project_id'] ?? null,
            ':platform'         => $this->client->networkName(),
            ':name'             => $config['name'],
            ':type'             => $config['type'] ?? 'display',
            ':status'           => $config['status'] ?? 'paused',
            ':daily_budget_aud' => $config['daily_budget'] ?? null,
            ':bid_strategy'     => $config['bid_strategy'] ?? 'manual_cpc',
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Pause a campaign (update status to 'paused' in DB).
     */
    public function pause(int $campaignId): void
    {
        $this->updateStatus($campaignId, 'paused');
    }

    /**
     * Enable (activate) a campaign (update status to 'active' in DB).
     */
    public function enable(int $campaignId): void
    {
        $this->updateStatus($campaignId, 'active');
    }

    /**
     * List all banner campaigns from the DB.
     *
     * @return array Array of campaign rows
     */
    public function list(): array
    {
        $db = DB::get();

        $stmt = $db->prepare(
            'SELECT * FROM campaigns WHERE platform = :platform ORDER BY created_at DESC'
        );
        $stmt->execute([':platform' => $this->client->networkName()]);

        return $stmt->fetchAll();
    }

    /**
     * Get a single campaign by local ID.
     *
     * @throws RuntimeException if not found
     */
    public function get(int $campaignId): array
    {
        $db = DB::get();

        $stmt = $db->prepare(
            'SELECT * FROM campaigns WHERE id = :id AND platform = :platform'
        );
        $stmt->execute([
            ':id'       => $campaignId,
            ':platform' => $this->client->networkName(),
        ]);

        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException("Banner campaign {$campaignId} not found");
        }

        return $row;
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    private function updateStatus(int $campaignId, string $status): void
    {
        $db = DB::get();

        $stmt = $db->prepare(
            'UPDATE campaigns
                SET status = :status, updated_at = datetime(\'now\')
              WHERE id = :id AND platform = :platform'
        );
        $stmt->execute([
            ':status'   => $status,
            ':id'       => $campaignId,
            ':platform' => $this->client->networkName(),
        ]);
    }
}
