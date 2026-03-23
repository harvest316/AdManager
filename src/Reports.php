<?php

namespace AdManager;

class Reports
{
    private string $customerId;

    public function __construct()
    {
        $this->customerId = Client::customerId();
    }

    /**
     * Campaign performance report.
     *
     * @param string $dateRange e.g. 'LAST_7_DAYS', 'LAST_30_DAYS', 'THIS_MONTH'
     */
    public function campaigns(string $dateRange = 'LAST_7_DAYS'): array
    {
        $query = <<<GAQL
            SELECT
                campaign.id,
                campaign.name,
                campaign.status,
                metrics.impressions,
                metrics.clicks,
                metrics.ctr,
                metrics.average_cpc,
                metrics.cost_micros,
                metrics.conversions,
                metrics.cost_per_conversion
            FROM campaign
            WHERE segments.date DURING {$dateRange}
            ORDER BY metrics.cost_micros DESC
            GAQL;

        return $this->query($query);
    }

    /**
     * Ad group performance report.
     */
    public function adGroups(string $dateRange = 'LAST_7_DAYS'): array
    {
        $query = <<<GAQL
            SELECT
                campaign.name,
                ad_group.id,
                ad_group.name,
                ad_group.status,
                metrics.impressions,
                metrics.clicks,
                metrics.ctr,
                metrics.average_cpc,
                metrics.cost_micros,
                metrics.conversions
            FROM ad_group
            WHERE segments.date DURING {$dateRange}
            ORDER BY metrics.cost_micros DESC
            GAQL;

        return $this->query($query);
    }

    /**
     * Keyword performance report.
     */
    public function keywords(string $dateRange = 'LAST_7_DAYS'): array
    {
        $query = <<<GAQL
            SELECT
                campaign.name,
                ad_group.name,
                ad_group_criterion.keyword.text,
                ad_group_criterion.keyword.match_type,
                metrics.impressions,
                metrics.clicks,
                metrics.ctr,
                metrics.average_cpc,
                metrics.cost_micros,
                metrics.conversions
            FROM keyword_view
            WHERE segments.date DURING {$dateRange}
              AND ad_group_criterion.status != 'REMOVED'
            ORDER BY metrics.cost_micros DESC
            GAQL;

        return $this->query($query);
    }

    /**
     * Search terms report — what people actually searched.
     */
    public function searchTerms(string $dateRange = 'LAST_7_DAYS'): array
    {
        $query = <<<GAQL
            SELECT
                campaign.name,
                ad_group.name,
                search_term_view.search_term,
                search_term_view.status,
                metrics.impressions,
                metrics.clicks,
                metrics.ctr,
                metrics.average_cpc,
                metrics.cost_micros,
                metrics.conversions
            FROM search_term_view
            WHERE segments.date DURING {$dateRange}
            ORDER BY metrics.cost_micros DESC
            GAQL;

        return $this->query($query);
    }

    /**
     * Ad performance report.
     */
    public function ads(string $dateRange = 'LAST_7_DAYS'): array
    {
        $query = <<<GAQL
            SELECT
                campaign.name,
                ad_group.name,
                ad_group_ad.ad.id,
                ad_group_ad.ad.type,
                ad_group_ad.status,
                ad_group_ad.ad_strength,
                metrics.impressions,
                metrics.clicks,
                metrics.ctr,
                metrics.cost_micros,
                metrics.conversions
            FROM ad_group_ad
            WHERE segments.date DURING {$dateRange}
              AND ad_group_ad.status != 'REMOVED'
            ORDER BY metrics.cost_micros DESC
            GAQL;

        return $this->query($query);
    }

    private function query(string $gaql): array
    {
        $client  = Client::get();
        $service = $client->getGoogleAdsServiceClient();
        $stream  = $service->search($this->customerId, $gaql);

        $rows = [];
        foreach ($stream->iterateAllElements() as $row) {
            $rows[] = $row;
        }
        return $rows;
    }
}
