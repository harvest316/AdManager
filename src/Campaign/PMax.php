<?php

namespace AdManager\Campaign;

use AdManager\Client;
use Google\Ads\GoogleAds\V18\Common\MaximizeConversionValue;
use Google\Ads\GoogleAds\V18\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V18\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V18\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod;
use Google\Ads\GoogleAds\V18\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V18\Resources\Campaign;
use Google\Ads\GoogleAds\V18\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V18\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V18\Services\CampaignOperation;

class PMax
{
    private string $customerId;

    public function __construct()
    {
        $this->customerId = Client::customerId();
    }

    /**
     * Create a Performance Max campaign.
     *
     * @param array $config [
     *   'name'             => 'Audit&Fix — PMax — AU',
     *   'daily_budget_usd' => 10.00,
     *   'bidding'          => 'maximize_conversions' | 'maximize_conversion_value',
     *   'target_roas'      => 3.0,  // optional, for maximize_conversion_value
     * ]
     */
    public function create(array $config): string
    {
        $client = Client::get();

        // Budget
        $budgetMicros = (int) ($config['daily_budget_usd'] * 1_000_000);
        $budget = new CampaignBudget([
            'name'            => $config['name'] . ' Budget',
            'amount_micros'   => $budgetMicros,
            'delivery_method' => BudgetDeliveryMethod::STANDARD,
            'explicitly_shared' => false,
        ]);
        $budgetOp = new CampaignBudgetOperation();
        $budgetOp->setCreate($budget);

        $budgetRn = $client->getCampaignBudgetServiceClient()
            ->mutateCampaignBudgets($this->customerId, [$budgetOp])
            ->getResults()[0]->getResourceName();

        // Campaign
        $campaign = new Campaign([
            'name'                     => $config['name'],
            'advertising_channel_type' => AdvertisingChannelType::PERFORMANCE_MAX,
            'status'                   => CampaignStatus::PAUSED,
            'campaign_budget'          => $budgetRn,
        ]);

        if (($config['bidding'] ?? 'maximize_conversions') === 'maximize_conversion_value') {
            $pmax = new MaximizeConversionValue();
            if (!empty($config['target_roas'])) {
                $pmax->setTargetRoas((float) $config['target_roas']);
            }
            $campaign->setMaximizeConversionValue($pmax);
        } else {
            $campaign->setMaximizeConversions(new MaximizeConversions());
        }

        $op = new CampaignOperation();
        $op->setCreate($campaign);

        return $client->getCampaignServiceClient()
            ->mutateCampaigns($this->customerId, [$op])
            ->getResults()[0]->getResourceName();
    }
}
