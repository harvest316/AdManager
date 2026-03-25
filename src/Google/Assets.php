<?php

namespace AdManager\Google;

use Google\Ads\GoogleAds\V20\Common\SitelinkAsset;
use Google\Ads\GoogleAds\V20\Common\CalloutAsset;
use Google\Ads\GoogleAds\V20\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V20\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V20\Resources\Asset;
use Google\Ads\GoogleAds\V20\Resources\CampaignAsset;
use Google\Ads\GoogleAds\V20\Services\AssetOperation;
use Google\Ads\GoogleAds\V20\Services\CampaignAssetOperation;
use Google\Ads\GoogleAds\V20\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V20\Services\MutateCampaignAssetsRequest;
use Google\Ads\GoogleAds\V20\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\Util\V20\ResourceNames;

class Assets
{
    private string $customerId;

    public function __construct()
    {
        $this->customerId = Client::customerId();
    }

    /**
     * Create a sitelink asset and attach it to a campaign.
     *
     * @param string $campaignId  Numeric campaign ID.
     * @param string $linkText    Sitelink display text (max 25 chars).
     * @param string $finalUrl    Destination URL.
     * @param string $desc1       Optional description line 1 (max 35 chars).
     * @param string $desc2       Optional description line 2 (max 35 chars).
     * @return string             Resource name of the created asset.
     */
    public function addSitelink(
        string $campaignId,
        string $linkText,
        string $finalUrl,
        string $desc1 = '',
        string $desc2 = ''
    ): string {
        $client  = Client::get();
        $service = $client->getAssetServiceClient();

        $sitelink = new SitelinkAsset([
            'link_text' => $linkText,
        ]);

        if ($desc1 !== '') {
            $sitelink->setDescription1($desc1);
        }
        if ($desc2 !== '') {
            $sitelink->setDescription2($desc2);
        }

        $asset = new Asset([
            'type'           => AssetType::SITELINK,
            'sitelink_asset' => $sitelink,
            'final_urls'     => [$finalUrl],
        ]);

        $op = new AssetOperation();
        $op->setCreate($asset);

        $response = $service->mutateAssets(
            MutateAssetsRequest::build($this->customerId, [$op])
        );
        $assetRn  = $response->getResults()[0]->getResourceName();

        $this->linkAssetToCampaign($assetRn, $campaignId, AssetFieldType::SITELINK);

        return $assetRn;
    }

    /**
     * Create a callout asset and attach it to a campaign.
     *
     * @param string $campaignId  Numeric campaign ID.
     * @param string $text        Callout text (max 25 chars).
     * @return string             Resource name of the created asset.
     */
    public function addCallout(string $campaignId, string $text): string
    {
        $client  = Client::get();
        $service = $client->getAssetServiceClient();

        $asset = new Asset([
            'type'           => AssetType::CALLOUT,
            'callout_asset'  => new CalloutAsset([
                'callout_text' => $text,
            ]),
        ]);

        $op = new AssetOperation();
        $op->setCreate($asset);

        $response = $service->mutateAssets(
            MutateAssetsRequest::build($this->customerId, [$op])
        );
        $assetRn  = $response->getResults()[0]->getResourceName();

        $this->linkAssetToCampaign($assetRn, $campaignId, AssetFieldType::CALLOUT);

        return $assetRn;
    }

    /**
     * List all active assets attached to a campaign.
     *
     * @param string $campaignId  Numeric campaign ID.
     * @return array              Array of ['resource_name', 'type', 'text', 'field_type'].
     */
    public function listCampaignAssets(string $campaignId): array
    {
        $client     = Client::get();
        $service    = $client->getGoogleAdsServiceClient();
        $campaignRn = ResourceNames::forCampaign($this->customerId, $campaignId);

        $query = <<<GAQL
            SELECT
                asset.resource_name,
                asset.type,
                asset.sitelink_asset.link_text,
                asset.callout_asset.callout_text,
                campaign_asset.field_type
            FROM campaign_asset
            WHERE campaign_asset.campaign = '{$campaignRn}'
              AND campaign_asset.status != 'REMOVED'
            GAQL;

        $request = new SearchGoogleAdsRequest([
            'customer_id' => $this->customerId,
            'query'       => $query,
        ]);

        $rows = [];
        foreach ($service->search($request)->iterateAllElements() as $row) {
            $asset = $row->getAsset();
            $type  = $asset->getType();

            $text = match ($type) {
                AssetType::SITELINK => $asset->getSitelinkAsset()?->getLinkText() ?? '',
                AssetType::CALLOUT  => $asset->getCalloutAsset()?->getCalloutText() ?? '',
                default             => '',
            };

            $rows[] = [
                'resource_name' => $asset->getResourceName(),
                'type'          => $type,
                'text'          => $text,
                'field_type'    => $row->getCampaignAsset()->getFieldType(),
            ];
        }
        return $rows;
    }

    /**
     * Attach an asset to a campaign with the given field type.
     */
    private function linkAssetToCampaign(
        string $assetResourceName,
        string $campaignId,
        int $fieldType
    ): void {
        $client     = Client::get();
        $service    = $client->getCampaignAssetServiceClient();
        $campaignRn = ResourceNames::forCampaign($this->customerId, $campaignId);

        $campaignAsset = new CampaignAsset([
            'asset'      => $assetResourceName,
            'campaign'   => $campaignRn,
            'field_type' => $fieldType,
        ]);

        $op = new CampaignAssetOperation();
        $op->setCreate($campaignAsset);

        $service->mutateCampaignAssets(
            MutateCampaignAssetsRequest::build($this->customerId, [$op])
        );
    }
}
