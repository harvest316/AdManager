<?php

namespace AdManager\Banner;

use AdManager\DB;

/**
 * Banner network reporting — DB-only, no API.
 *
 * import() bulk-inserts performance rows from external data (e.g. CSV imports).
 * campaignInsights() queries the local performance table.
 *
 * Results are normalised to a standard shape consistent with other platforms:
 *   impressions, clicks, cost_micros, conversions, conversion_value
 */
class Reports
{
    private Client $client;

    public function __construct()
    {
        $this->client = Client::get();
    }

    /**
     * Bulk-import performance rows into the DB.
     *
     * @param int   $campaignId Local campaign ID
     * @param array $rows       Each row: [
     *   'date'             => 'YYYY-MM-DD',
     *   'impressions'      => 10000,
     *   'clicks'           => 150,
     *   'cost_micros'      => 5000000,   // in micros (millionths of a dollar)
     *   'conversions'      => 3.0,
     *   'conversion_value' => 45.00,
     *   'ad_group_id'      => null,       // optional
     *   'ad_id'            => null,       // optional
     * ]
     * @return int Number of rows inserted
     */
    public function import(int $campaignId, array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $db = DB::get();
        $count = 0;

        $stmt = $db->prepare(
            'INSERT OR REPLACE INTO performance
               (campaign_id, ad_group_id, ad_id, date,
                impressions, clicks, cost_micros, conversions, conversion_value,
                created_at)
             VALUES
               (:campaign_id, :ad_group_id, :ad_id, :date,
                :impressions, :clicks, :cost_micros, :conversions, :conversion_value,
                datetime(\'now\'))'
        );

        foreach ($rows as $row) {
            $stmt->execute([
                ':campaign_id'      => $campaignId,
                ':ad_group_id'      => $row['ad_group_id'] ?? null,
                ':ad_id'            => $row['ad_id'] ?? null,
                ':date'             => $row['date'],
                ':impressions'      => (int) ($row['impressions'] ?? 0),
                ':clicks'           => (int) ($row['clicks'] ?? 0),
                ':cost_micros'      => (int) ($row['cost_micros'] ?? 0),
                ':conversions'      => (float) ($row['conversions'] ?? 0),
                ':conversion_value' => (float) ($row['conversion_value'] ?? 0),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Query performance data for a campaign within a date range.
     *
     * @param  int    $campaignId Local campaign ID
     * @param  string $startDate  Format: YYYY-MM-DD
     * @param  string $endDate    Format: YYYY-MM-DD
     * @return array  Normalised insight rows
     */
    public function campaignInsights(int $campaignId, string $startDate, string $endDate): array
    {
        $db = DB::get();

        $stmt = $db->prepare(
            'SELECT date, impressions, clicks, cost_micros, conversions, conversion_value
               FROM performance
              WHERE campaign_id = :campaign_id
                AND date >= :start_date
                AND date <= :end_date
              ORDER BY date ASC'
        );

        $stmt->execute([
            ':campaign_id' => $campaignId,
            ':start_date'  => $startDate,
            ':end_date'    => $endDate,
        ]);

        return array_map(function (array $row) use ($campaignId): array {
            return [
                'campaign_id'      => $campaignId,
                'date'             => $row['date'],
                'impressions'      => (int) $row['impressions'],
                'clicks'           => (int) $row['clicks'],
                'cost_micros'      => (int) $row['cost_micros'],
                'conversions'      => (float) $row['conversions'],
                'conversion_value' => (float) $row['conversion_value'],
            ];
        }, $stmt->fetchAll());
    }
}
