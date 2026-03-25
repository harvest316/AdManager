<?php

namespace AdManager\Google\Campaign;

use AdManager\Google\Client;
use Google\Ads\GoogleAds\V20\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V20\Common\TargetCpa;
use Google\Ads\GoogleAds\V20\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V20\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod;
use Google\Ads\GoogleAds\V20\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V20\Resources\Campaign;
use Google\Ads\GoogleAds\V20\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V20\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V20\Services\CampaignOperation;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignBudgetsRequest;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignsRequest;

class Display
{
    private string $customerId;

    public function __construct()
    {
        $this->customerId = Client::customerId();
    }

    /**
     * Create a Display campaign.
     *
     * @param array $config [
     *   'name'             => 'Audit&Fix — Display — Remarketing',
     *   'daily_budget_usd' => 5.00,
     *   'bidding'          => 'maximize_conversions' | 'target_cpa',
     *   'target_cpa_usd'   => 150.00,
     * ]
     */
    public function create(array $config): string
    {
        $client = Client::get();

        $budgetMicros = (int) ($config['daily_budget_usd'] * 1_000_000);
        $budget = new CampaignBudget([
            'name'            => $config['name'] . ' Budget',
            'amount_micros'   => $budgetMicros,
            'delivery_method' => BudgetDeliveryMethod::STANDARD,
        ]);
        $budgetOp = new CampaignBudgetOperation();
        $budgetOp->setCreate($budget);

        $budgetRn = $client->getCampaignBudgetServiceClient()
            ->mutateCampaignBudgets(
                MutateCampaignBudgetsRequest::build($this->customerId, [$budgetOp])
            )
            ->getResults()[0]->getResourceName();

        $campaign = new Campaign([
            'name'                     => $config['name'],
            'advertising_channel_type' => AdvertisingChannelType::DISPLAY,
            'status'                   => CampaignStatus::PAUSED,
            'campaign_budget'          => $budgetRn,
        ]);

        if (($config['bidding'] ?? 'maximize_conversions') === 'target_cpa') {
            $campaign->setTargetCpa(new TargetCpa([
                'target_cpa_micros' => (int) (($config['target_cpa_usd'] ?? 150) * 1_000_000),
            ]));
        } else {
            $campaign->setMaximizeConversions(new MaximizeConversions());
        }

        $op = new CampaignOperation();
        $op->setCreate($campaign);

        return $client->getCampaignServiceClient()
            ->mutateCampaigns(
                MutateCampaignsRequest::build($this->customerId, [$op])
            )
            ->getResults()[0]->getResourceName();
    }
}
