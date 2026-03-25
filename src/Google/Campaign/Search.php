<?php

namespace AdManager\Google\Campaign;

use AdManager\Google\Client;
use Google\Ads\GoogleAds\V20\Common\ManualCpc;
use Google\Ads\GoogleAds\V20\Common\TargetCpa;
use Google\Ads\GoogleAds\V20\Common\MaximizeConversions;
use Google\Ads\GoogleAds\V20\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V20\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod;
use Google\Ads\GoogleAds\V20\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V20\Resources\Campaign;
use Google\Ads\GoogleAds\V20\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V20\Resources\Campaign\NetworkSettings;
use Google\Ads\GoogleAds\V20\Services\CampaignBudgetOperation;
use Google\Ads\GoogleAds\V20\Services\CampaignOperation;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignBudgetsRequest;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignsRequest;
use Google\Ads\GoogleAds\V20\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\Util\V20\ResourceNames;

class Search
{
    private string $customerId;

    public function __construct()
    {
        $this->customerId = Client::customerId();
    }

    /**
     * Create a Search campaign.
     *
     * @param array $config [
     *   'name'              => 'Audit&Fix — Search — AU',
     *   'daily_budget_usd'  => 6.70,
     *   'bidding'           => 'maximize_conversions' | 'manual_cpc' | 'target_cpa',
     *   'target_cpa_usd'    => 150.00,       // only for target_cpa
     *   'search_partners'   => false,
     *   'display_network'   => false,
     *   'start_date'        => 'YYYY-MM-DD', // optional
     * ]
     */
    public function create(array $config): string
    {
        $client = Client::get();

        // 1. Create budget
        $budgetMicros = (int) ($config['daily_budget_usd'] * 1_000_000);
        $budget = new CampaignBudget([
            'name'            => $config['name'] . ' Budget',
            'amount_micros'   => $budgetMicros,
            'delivery_method' => BudgetDeliveryMethod::STANDARD,
        ]);
        $budgetOp = new CampaignBudgetOperation();
        $budgetOp->setCreate($budget);

        $budgetService  = $client->getCampaignBudgetServiceClient();
        $budgetResponse = $budgetService->mutateCampaignBudgets(
            MutateCampaignBudgetsRequest::build($this->customerId, [$budgetOp])
        );
        $budgetRn       = $budgetResponse->getResults()[0]->getResourceName();

        // 2. Create campaign
        $campaign = new Campaign([
            'name'                     => $config['name'],
            'advertising_channel_type' => AdvertisingChannelType::SEARCH,
            'status'                   => CampaignStatus::PAUSED, // start paused — enable manually
            'campaign_budget'          => $budgetRn,
            'network_settings'         => new NetworkSettings([
                'target_google_search'         => true,
                'target_search_network'        => $config['search_partners'] ?? false,
                'target_content_network'       => $config['display_network'] ?? false,
                'target_partner_search_network'=> false,
            ]),
        ]);

        // Bidding strategy
        match ($config['bidding'] ?? 'maximize_conversions') {
            'manual_cpc'          => $campaign->setManualCpc(new ManualCpc(['enhanced_cpc_enabled' => false])),
            'target_cpa'          => $campaign->setTargetCpa(new TargetCpa([
                'target_cpa_micros' => (int) (($config['target_cpa_usd'] ?? 150) * 1_000_000),
            ])),
            default               => $campaign->setMaximizeConversions(new MaximizeConversions()),
        };

        if (!empty($config['start_date'])) {
            $campaign->setStartDate(str_replace('-', '', $config['start_date']));
        }

        $campaignOp = new CampaignOperation();
        $campaignOp->setCreate($campaign);

        $campaignService  = $client->getCampaignServiceClient();
        $campaignResponse = $campaignService->mutateCampaigns(
            MutateCampaignsRequest::build($this->customerId, [$campaignOp])
        );

        return $campaignResponse->getResults()[0]->getResourceName();
    }

    /**
     * List all search campaigns.
     */
    public function list(): array
    {
        $client  = Client::get();
        $service = $client->getGoogleAdsServiceClient();
        $query   = <<<GAQL
            SELECT
                campaign.id,
                campaign.name,
                campaign.status,
                campaign.bidding_strategy_type,
                campaign_budget.amount_micros
            FROM campaign
            WHERE campaign.advertising_channel_type = 'SEARCH'
              AND campaign.status != 'REMOVED'
            ORDER BY campaign.name
            GAQL;

        $rows = [];
        $request = new SearchGoogleAdsRequest([
            'customer_id' => $this->customerId,
            'query'       => $query,
        ]);
        foreach ($service->search($request)->iterateAllElements() as $row) {
            $rows[] = [
                'id'       => $row->getCampaign()->getId(),
                'name'     => $row->getCampaign()->getName(),
                'status'   => $row->getCampaign()->getStatus(),
                'bidding'  => $row->getCampaign()->getBiddingStrategyType(),
                'budget'   => $row->getCampaignBudget()->getAmountMicros() / 1_000_000,
            ];
        }
        return $rows;
    }
}
