<?php

namespace AdManager;

use Google\Ads\GoogleAds\V18\Common\KeywordInfo;
use Google\Ads\GoogleAds\V18\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V18\Enums\AdGroupCriterionStatusEnum\AdGroupCriterionStatus;
use Google\Ads\GoogleAds\V18\Enums\CampaignCriterionStatusEnum\CampaignCriterionStatus;
use Google\Ads\GoogleAds\V18\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V18\Resources\CampaignCriterion;
use Google\Ads\GoogleAds\V18\Services\AdGroupCriterionOperation;
use Google\Ads\GoogleAds\V18\Services\CampaignCriterionOperation;
use Google\Ads\GoogleAds\Util\V18\ResourceNames;

class Keywords
{
    private string $customerId;

    public function __construct()
    {
        $this->customerId = Client::customerId();
    }

    /**
     * Add keywords to an ad group.
     *
     * @param string $adGroupId
     * @param array  $keywords  [['text' => 'website audit', 'match_type' => 'EXACT', 'cpc_micros' => 2500000], ...]
     */
    public function addToAdGroup(string $adGroupId, array $keywords): array
    {
        $client     = Client::get();
        $service    = $client->getAdGroupCriterionServiceClient();
        $adGroupRn  = ResourceNames::forAdGroup($this->customerId, $adGroupId);
        $operations = [];

        foreach ($keywords as $kw) {
            $matchType = match (strtoupper($kw['match_type'] ?? 'EXACT')) {
                'BROAD'  => KeywordMatchType::BROAD,
                'PHRASE' => KeywordMatchType::PHRASE,
                default  => KeywordMatchType::EXACT,
            };

            $criterion = new AdGroupCriterion([
                'ad_group'  => $adGroupRn,
                'status'    => AdGroupCriterionStatus::ENABLED,
                'keyword'   => new KeywordInfo([
                    'text'       => $kw['text'],
                    'match_type' => $matchType,
                ]),
            ]);

            if (!empty($kw['cpc_micros'])) {
                $criterion->setCpcBidMicros((int) $kw['cpc_micros']);
            }

            $op = new AdGroupCriterionOperation();
            $op->setCreate($criterion);
            $operations[] = $op;
        }

        $response = $service->mutateAdGroupCriteria($this->customerId, $operations);
        $results  = [];
        foreach ($response->getResults() as $result) {
            $results[] = $result->getResourceName();
        }
        return $results;
    }

    /**
     * Add negative keywords to a campaign.
     *
     * @param string $campaignId
     * @param array  $keywords   [['text' => 'free', 'match_type' => 'BROAD'], ...]
     */
    public function addNegativesToCampaign(string $campaignId, array $keywords): array
    {
        $client     = Client::get();
        $service    = $client->getCampaignCriterionServiceClient();
        $campaignRn = ResourceNames::forCampaign($this->customerId, $campaignId);
        $operations = [];

        foreach ($keywords as $kw) {
            $matchType = match (strtoupper($kw['match_type'] ?? 'BROAD')) {
                'EXACT'  => KeywordMatchType::EXACT,
                'PHRASE' => KeywordMatchType::PHRASE,
                default  => KeywordMatchType::BROAD,
            };

            $criterion = new CampaignCriterion([
                'campaign' => $campaignRn,
                'negative' => true,
                'keyword'  => new KeywordInfo([
                    'text'       => $kw['text'],
                    'match_type' => $matchType,
                ]),
            ]);

            $op = new CampaignCriterionOperation();
            $op->setCreate($criterion);
            $operations[] = $op;
        }

        $response = $service->mutateCampaignCriteria($this->customerId, $operations);
        $results  = [];
        foreach ($response->getResults() as $result) {
            $results[] = $result->getResourceName();
        }
        return $results;
    }

    /**
     * Load keywords CSV (docs/google-ads/keywords.csv format).
     */
    public static function loadCsv(string $path): array
    {
        $rows = [];
        $fh   = fopen($path, 'r');
        $header = fgetcsv($fh); // skip header
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 5) continue;
            [$campaign, $adGroup, $keyword, $matchType, $maxCpc] = $row;
            $rows[] = [
                'campaign'   => $campaign,
                'ad_group'   => $adGroup,
                'text'       => $keyword,
                'match_type' => $matchType,
                'cpc_micros' => (int) (floatval($maxCpc) * 1_000_000),
            ];
        }
        fclose($fh);
        return $rows;
    }

    /**
     * Load negative keywords CSV (docs/google-ads/negative-keywords.csv format).
     */
    public static function loadNegativesCsv(string $path): array
    {
        $rows = [];
        $fh   = fopen($path, 'r');
        fgetcsv($fh); // skip header
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 4) continue;
            [$level, $campaign, $keyword, $matchType] = $row;
            $rows[] = [
                'level'      => $level,
                'campaign'   => $campaign,
                'text'       => $keyword,
                'match_type' => $matchType,
            ];
        }
        fclose($fh);
        return $rows;
    }
}
