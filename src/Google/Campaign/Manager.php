<?php

namespace AdManager\Google\Campaign;

use AdManager\Google\Client;
use Google\Ads\GoogleAds\Util\FieldMasks;
use Google\Ads\GoogleAds\V20\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V20\Resources\Campaign;
use Google\Ads\GoogleAds\V20\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V20\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V20\Services\CampaignOperation;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignBudgetsRequest;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V20\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\Util\V20\ResourceNames;

class Manager
{
    private string $customerId;

    public function __construct()
    {
        $this->customerId = Client::customerId();
    }

    /**
     * Set a campaign's status to ENABLED.
     *
     * @param string $campaignId  Numeric campaign ID.
     */
    public function enable(string $campaignId): void
    {
        $this->setStatus($campaignId, CampaignStatus::ENABLED);
    }

    /**
     * Set a campaign's status to PAUSED.
     *
     * @param string $campaignId  Numeric campaign ID.
     */
    public function pause(string $campaignId): void
    {
        $this->setStatus($campaignId, CampaignStatus::PAUSED);
    }

    /**
     * Update a campaign budget's daily amount.
     *
     * @param string $budgetId   Numeric campaign budget ID (not the campaign ID).
     * @param float  $amountAud  New daily budget in AUD.
     */
    public function setDailyBudget(string $budgetId, float $amountAud): void
    {
        $client   = Client::get();
        $budgetRn = ResourceNames::forCampaignBudget($this->customerId, $budgetId);

        $budget = new CampaignBudget([
            'resource_name' => $budgetRn,
            'amount_micros' => (int) ($amountAud * 1_000_000),
        ]);

        $op = new CampaignBudgetOperation();
        $op->setUpdate($budget);
        $op->setUpdateMask(FieldMasks::allSetFieldsOf($budget));

        $service = $client->getCampaignBudgetServiceClient();
        $service->mutateCampaignBudgets(
            MutateCampaignBudgetsRequest::build($this->customerId, [$op])
        );
    }

    /**
     * Look up the budget ID attached to a campaign.
     *
     * @param string $campaignId  Numeric campaign ID.
     * @return string|null        Numeric budget ID, or null if not found.
     */
    public function getBudgetId(string $campaignId): ?string
    {
        $client  = Client::get();
        $service = $client->getGoogleAdsServiceClient();
        $query   = <<<GAQL
            SELECT
                campaign_budget.id
            FROM campaign
            WHERE campaign.id = {$campaignId}
            GAQL;

        $request = new SearchGoogleAdsRequest([
            'customer_id' => $this->customerId,
            'query'       => $query,
        ]);

        foreach ($service->search($request)->iterateAllElements() as $row) {
            return (string) $row->getCampaignBudget()->getId();
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function setStatus(string $campaignId, int $status): void
    {
        $client     = Client::get();
        $campaignRn = ResourceNames::forCampaign($this->customerId, $campaignId);

        $campaign = new Campaign([
            'resource_name' => $campaignRn,
            'status'        => $status,
        ]);

        $op = new CampaignOperation();
        $op->setUpdate($campaign);
        $op->setUpdateMask(FieldMasks::allSetFieldsOf($campaign));

        $service = $client->getCampaignServiceClient();
        $service->mutateCampaigns(
            MutateCampaignsRequest::build($this->customerId, [$op])
        );
    }
}
