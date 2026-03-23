<?php

namespace AdManager\Campaign;

use AdManager\Client;
use Google\Ads\GoogleAds\V18\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V18\Common\TargetCpa;
use Google\Ads\GoogleAds\V18\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V18\Enums\AdvertisingChannelSubTypeEnum\AdvertisingChannelSubType;
use Google\Ads\GoogleAds\V18\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod;
use Google\Ads\GoogleAds\V18\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V18\Resources\Campaign;
use Google\Ads\GoogleAds\V18\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V18\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V18\Services\CampaignOperation;

class Video
{
    private string $customerId;

    public function __construct()
    {
        $this->customerId = Client::customerId();
    }

    /**
     * Create a Video (YouTube) campaign.
     *
     * @param array $config [
     *   'name'             => 'Audit&Fix — Video — AU',
     *   'daily_budget_usd' => 5.00,
     *   'subtype'          => 'video_action' | 'video_reach',
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
            ->mutateCampaignBudgets($this->customerId, [$budgetOp])
            ->getResults()[0]->getResourceName();

        $subType = ($config['subtype'] ?? 'video_action') === 'video_reach'
            ? AdvertisingChannelSubType::VIDEO_OUTSTREAM
            : AdvertisingChannelSubType::VIDEO_ACTION;

        $campaign = new Campaign([
            'name'                         => $config['name'],
            'advertising_channel_type'     => AdvertisingChannelType::VIDEO,
            'advertising_channel_sub_type' => $subType,
            'status'                       => CampaignStatus::PAUSED,
            'campaign_budget'              => $budgetRn,
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
            ->mutateCampaigns($this->customerId, [$op])
            ->getResults()[0]->getResourceName();
    }
}
