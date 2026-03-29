<?php

namespace AdManager\Google\Ads;

use AdManager\Google\Client;
use Google\Ads\GoogleAds\V20\Common\AdTextAsset;
use Google\Ads\GoogleAds\V20\Common\ResponsiveSearchAdInfo;
use Google\Ads\GoogleAds\V20\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V20\Enums\ServedAssetFieldTypeEnum\ServedAssetFieldType;
use Google\Ads\GoogleAds\V20\Resources\Ad;
use Google\Ads\GoogleAds\V20\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V20\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V20\Services\MutateAdGroupAdsRequest;
use Google\Ads\GoogleAds\Util\V20\ResourceNames;

class ResponsiveSearch
{
    private string $customerId;

    public function __construct()
    {
        $this->customerId = Client::customerId();
    }

    /**
     * Create a Responsive Search Ad.
     *
     * @param string $adGroupId
     * @param array  $config [
     *   'final_url'    => 'https://auditandfix.com/scan',
     *   'display_path' => ['Free-Audit', ''],  // up to 2 path segments
     *   'headlines'    => [
     *     ['text' => 'Free Website Audit Tool', 'pin' => 1],  // pin is optional (1 or 2)
     *     ['text' => 'Score Your Site in 30 Secs', 'pin' => 2],
     *     ['text' => '10-Point Conversion Check'],
     *     // ... up to 15
     *   ],
     *   'descriptions' => [
     *     ['text' => 'Check your conversion score...', 'pin' => 1],
     *     ['text' => 'Most websites lose 60%+...'],
     *     // ... up to 4
     *   ],
     * ]
     */
    public function create(string $adGroupId, array $config): string
    {
        $client    = Client::get();
        $adGroupRn = ResourceNames::forAdGroup($this->customerId, $adGroupId);

        $headlines = [];
        foreach ($config['headlines'] as $h) {
            $asset = new AdTextAsset(['text' => $h['text']]);
            if (!empty($h['pin'])) {
                $pinField = $h['pin'] === 1
                    ? ServedAssetFieldType::HEADLINE_1
                    : ServedAssetFieldType::HEADLINE_2;
                $asset->setPinnedField($pinField);
            }
            $headlines[] = $asset;
        }

        $descriptions = [];
        foreach ($config['descriptions'] as $d) {
            $asset = new AdTextAsset(['text' => $d['text']]);
            if (!empty($d['pin'])) {
                $asset->setPinnedField(ServedAssetFieldType::DESCRIPTION_1);
            }
            $descriptions[] = $asset;
        }

        $paths = $config['display_path'] ?? [];
        $ad = new Ad([
            'final_urls'              => [$config['final_url']],
            'responsive_search_ad'   => new ResponsiveSearchAdInfo([
                'headlines'    => $headlines,
                'descriptions' => $descriptions,
                'path1'        => $paths[0] ?? '',
                'path2'        => $paths[1] ?? '',
            ]),
        ]);

        $adGroupAd = new AdGroupAd([
            'ad_group' => $adGroupRn,
            'ad'       => $ad,
            'status'   => AdGroupAdStatus::ENABLED,
        ]);

        $op = new AdGroupAdOperation();
        $op->setCreate($adGroupAd);

        $service  = $client->getAdGroupAdServiceClient();
        $response = $service->mutateAdGroupAds(
            MutateAdGroupAdsRequest::build($this->customerId, [$op])
        );

        return $response->getResults()[0]->getResourceName();
    }

    /**
     * Pause an existing AdGroupAd by its resource name.
     *
     * @param string $adGroupAdResourceName e.g. customers/123/adGroupAds/456~789
     */
    public function pause(string $adGroupAdResourceName): void
    {
        $this->setStatus($adGroupAdResourceName, AdGroupAdStatus::PAUSED);
    }

    /**
     * Enable (un-pause) an existing AdGroupAd by its resource name.
     *
     * @param string $adGroupAdResourceName e.g. customers/123/adGroupAds/456~789
     */
    public function enable(string $adGroupAdResourceName): void
    {
        $this->setStatus($adGroupAdResourceName, AdGroupAdStatus::ENABLED);
    }

    /**
     * Shared status-update helper.
     */
    private function setStatus(string $adGroupAdResourceName, int $status): void
    {
        $client = Client::get();

        $adGroupAd = new AdGroupAd([
            'resource_name' => $adGroupAdResourceName,
            'status'        => $status,
        ]);

        $op = new AdGroupAdOperation();
        $op->setUpdate($adGroupAd);
        $op->setUpdateMask(new \Google\Protobuf\FieldMask(['paths' => ['status']]));

        $service = $client->getAdGroupAdServiceClient();
        $service->mutateAdGroupAds(
            MutateAdGroupAdsRequest::build($this->customerId, [$op])
        );
    }
}
