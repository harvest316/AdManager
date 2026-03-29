<?php

namespace AdManager\Google;

use Google\Ads\GoogleAds\V20\Common\UserListInfo;
use Google\Ads\GoogleAds\V20\Enums\CampaignCriterionStatusEnum\CampaignCriterionStatus;
use Google\Ads\GoogleAds\V20\Enums\CriterionTypeEnum\CriterionType;
use Google\Ads\GoogleAds\V20\Enums\UserListCriterionTypeEnum\UserListCriterionType;
use Google\Ads\GoogleAds\V20\Resources\CampaignCriterion;
use Google\Ads\GoogleAds\V20\Services\CampaignCriterionOperation;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignCriteriaRequest;
use Google\Ads\GoogleAds\V20\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\Util\FieldMasks;

/**
 * RLSA (Remarketing Lists for Search Ads) audience management.
 *
 * Attaches user lists to campaigns in OBSERVATION mode — audiences observe
 * and allow bid modifiers without restricting reach (unlike TARGETING mode
 * which limits serving to matched users only).
 */
class Audiences
{
    private string $customerId;

    public function __construct()
    {
        $this->customerId = Client::customerId();
    }

    /**
     * Attach a user list to a campaign as an OBSERVATION audience.
     *
     * OBSERVATION mode (not TARGETING) is used so reach is not restricted —
     * only the bid modifier adjusts for matched users.
     *
     * @param string $campaignResourceName  e.g. 'customers/123/campaigns/456'
     * @param string $userListResourceName  e.g. 'customers/123/userLists/789'
     * @param float  $bidModifier           1.0 = no change, 1.5 = +50%, 0.8 = -20%
     * @return string                       Resource name of the created CampaignCriterion
     */
    public function attachToCampaign(
        string $campaignResourceName,
        string $userListResourceName,
        float $bidModifier = 1.0
    ): string {
        $this->validateBidModifier($bidModifier);

        $criterion = new CampaignCriterion([
            'campaign'     => $campaignResourceName,
            'status'       => CampaignCriterionStatus::ENABLED,
            'bid_modifier' => $bidModifier,
            'user_list'    => new UserListInfo([
                'user_list' => $userListResourceName,
            ]),
        ]);

        $op = new CampaignCriterionOperation();
        $op->setCreate($criterion);

        $client   = Client::get();
        $service  = $client->getCampaignCriterionServiceClient();
        $response = $service->mutateCampaignCriteria(
            MutateCampaignCriteriaRequest::build($this->customerId, [$op])
        );

        return $response->getResults()[0]->getResourceName();
    }

    /**
     * Remove an audience criterion from a campaign.
     *
     * @param string $campaignResourceName   e.g. 'customers/123/campaigns/456'
     * @param string $criterionResourceName  e.g. 'customers/123/campaignCriteria/456~789'
     */
    public function detachFromCampaign(string $campaignResourceName, string $criterionResourceName): void
    {
        $op = new CampaignCriterionOperation();
        $op->setRemove($criterionResourceName);

        $client  = Client::get();
        $service = $client->getCampaignCriterionServiceClient();
        $service->mutateCampaignCriteria(
            MutateCampaignCriteriaRequest::build($this->customerId, [$op])
        );
    }

    /**
     * List all USER_LIST criteria attached to a campaign.
     *
     * @param string $campaignResourceName  e.g. 'customers/123/campaigns/456'
     * @return array  Each row: resource_name, user_list_resource_name, bid_modifier, status
     */
    public function listCampaignAudiences(string $campaignResourceName): array
    {
        $client  = Client::get();
        $service = $client->getGoogleAdsServiceClient();

        $query = <<<GAQL
            SELECT
                campaign_criterion.resource_name,
                campaign_criterion.user_list.user_list,
                campaign_criterion.bid_modifier,
                campaign_criterion.status
            FROM campaign_criterion
            WHERE campaign_criterion.campaign = '{$campaignResourceName}'
              AND campaign_criterion.type = 'USER_LIST'
            GAQL;

        $request = new SearchGoogleAdsRequest([
            'customer_id' => $this->customerId,
            'query'       => $query,
        ]);

        $rows = [];
        foreach ($service->search($request)->iterateAllElements() as $row) {
            $cc = $row->getCampaignCriterion();
            $rows[] = [
                'resource_name'          => $cc->getResourceName(),
                'user_list_resource_name' => $cc->getUserList()->getUserList(),
                'bid_modifier'           => $cc->getBidModifier(),
                'status'                 => $cc->getStatus(),
            ];
        }
        return $rows;
    }

    /**
     * List all user lists in the account with search-relevant metadata.
     *
     * @return array  Each row: id, name, size_for_search, membership_status, resource_name
     */
    public function listUserLists(): array
    {
        $client  = Client::get();
        $service = $client->getGoogleAdsServiceClient();

        $query = <<<GAQL
            SELECT
                user_list.id,
                user_list.name,
                user_list.size_for_search,
                user_list.membership_status,
                user_list.resource_name
            FROM user_list
            ORDER BY user_list.name
            GAQL;

        $request = new SearchGoogleAdsRequest([
            'customer_id' => $this->customerId,
            'query'       => $query,
        ]);

        $rows = [];
        foreach ($service->search($request)->iterateAllElements() as $row) {
            $ul = $row->getUserList();
            $rows[] = [
                'id'                => $ul->getId(),
                'name'              => $ul->getName(),
                'size_for_search'   => $ul->getSizeForSearch(),
                'membership_status' => $ul->getMembershipStatus(),
                'resource_name'     => $ul->getResourceName(),
            ];
        }
        return $rows;
    }

    /**
     * Update the bid modifier on an existing campaign audience criterion.
     *
     * @param string $criterionResourceName  e.g. 'customers/123/campaignCriteria/456~789'
     * @param float  $bidModifier            1.0 = no change, 1.5 = +50%, 0.8 = -20%
     */
    public function updateBidModifier(string $criterionResourceName, float $bidModifier): void
    {
        $this->validateBidModifier($bidModifier);

        $criterion = new CampaignCriterion([
            'resource_name' => $criterionResourceName,
            'bid_modifier'  => $bidModifier,
        ]);

        $op = new CampaignCriterionOperation();
        $op->setUpdate($criterion);
        $op->setUpdateMask(FieldMasks::allSetFieldsOf($criterion));

        $client  = Client::get();
        $service = $client->getCampaignCriterionServiceClient();
        $service->mutateCampaignCriteria(
            MutateCampaignCriteriaRequest::build($this->customerId, [$op])
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate bid modifier is in the acceptable Google Ads range (0.1 – 10.0).
     * A value of 0.0 is also valid (exclude audience entirely).
     *
     * @throws \InvalidArgumentException
     */
    private function validateBidModifier(float $bidModifier): void
    {
        // Google Ads allows 0.0 (exclude), or 0.1 – 10.0
        if ($bidModifier !== 0.0 && ($bidModifier < 0.1 || $bidModifier > 10.0)) {
            throw new \InvalidArgumentException(
                "bid_modifier must be 0.0 (exclude) or between 0.1 and 10.0, got {$bidModifier}."
            );
        }
    }
}
