<?php

namespace AdManager;

use Google\Ads\GoogleAds\V20\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V20\Enums\AdGroupTypeEnum\AdGroupType;
use Google\Ads\GoogleAds\V20\Resources\AdGroup as AdGroupProto;
use Google\Ads\GoogleAds\V20\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V20\Services\MutateAdGroupsRequest;
use Google\Ads\GoogleAds\V20\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\Util\V20\ResourceNames;

class AdGroup
{
    private string $customerId;

    public function __construct()
    {
        $this->customerId = Client::customerId();
    }

    /**
     * Create an ad group within a campaign.
     *
     * @param string $campaignId  Numeric campaign ID.
     * @param string $name        Ad group name.
     * @param int    $cpcMicros   CPC bid in micros; 0 = use campaign-level bidding.
     * @param int    $type        AdGroupType enum value; defaults to SEARCH_STANDARD.
     * @return string             Resource name of the created ad group.
     */
    public function create(
        string $campaignId,
        string $name,
        int $cpcMicros = 0,
        int $type = AdGroupType::SEARCH_STANDARD
    ): string {
        $client     = Client::get();
        $campaignRn = ResourceNames::forCampaign($this->customerId, $campaignId);

        $adGroup = new AdGroupProto([
            'name'     => $name,
            'campaign' => $campaignRn,
            'status'   => AdGroupStatus::ENABLED,
            'type'     => $type,
        ]);

        if ($cpcMicros > 0) {
            $adGroup->setCpcBidMicros($cpcMicros);
        }

        $op = new AdGroupOperation();
        $op->setCreate($adGroup);

        $service  = $client->getAdGroupServiceClient();
        $response = $service->mutateAdGroups(
            MutateAdGroupsRequest::build($this->customerId, [$op])
        );

        return $response->getResults()[0]->getResourceName();
    }

    /**
     * List all non-removed ad groups in a campaign.
     *
     * @param string $campaignId  Numeric campaign ID.
     * @return array              Array of ['id', 'name', 'status', 'cpc_micros'].
     */
    public function list(string $campaignId): array
    {
        $client  = Client::get();
        $service = $client->getGoogleAdsServiceClient();
        $query   = <<<GAQL
            SELECT
                ad_group.id,
                ad_group.name,
                ad_group.status,
                ad_group.cpc_bid_micros
            FROM ad_group
            WHERE ad_group.campaign = 'customers/{$this->customerId}/campaigns/{$campaignId}'
              AND ad_group.status != 'REMOVED'
            ORDER BY ad_group.name
            GAQL;

        $request = new SearchGoogleAdsRequest([
            'customer_id' => $this->customerId,
            'query'       => $query,
        ]);

        $rows = [];
        foreach ($service->search($request)->iterateAllElements() as $row) {
            $ag     = $row->getAdGroup();
            $rows[] = [
                'id'         => $ag->getId(),
                'name'       => $ag->getName(),
                'status'     => $ag->getStatus(),
                'cpc_micros' => $ag->getCpcBidMicros(),
            ];
        }
        return $rows;
    }

    /**
     * Find an ad group ID by name within a campaign.
     *
     * @param string $campaignId  Numeric campaign ID.
     * @param string $name        Ad group name to search for.
     * @return string|null        Numeric ad group ID, or null if not found.
     */
    public function getId(string $campaignId, string $name): ?string
    {
        foreach ($this->list($campaignId) as $ag) {
            if ($ag['name'] === $name) {
                return (string) $ag['id'];
            }
        }
        return null;
    }
}
