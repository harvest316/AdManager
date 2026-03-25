<?php

namespace AdManager\Google;

use Google\Ads\GoogleAds\V20\Resources\Asset;
use Google\Ads\GoogleAds\V20\Common\ImageAsset;
use Google\Ads\GoogleAds\V20\Common\YoutubeVideoAsset;
use Google\Ads\GoogleAds\V20\Services\AssetOperation;
use Google\Ads\GoogleAds\V20\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V20\Services\SearchGoogleAdsRequest;

/**
 * Upload images and YouTube videos to Google Ads asset library
 * for use in Display, DemandGen, and PMax campaigns.
 */
class AssetUpload
{
    private string $customerId;

    public function __construct()
    {
        $this->customerId = Client::customerId();
    }

    /**
     * Upload a local image file to Google Ads as an image asset.
     *
     * @param  string $filePath Local path to the image file (JPG/PNG).
     * @param  string $name     Asset name; defaults to the filename without extension.
     * @return string           Resource name of the created asset.
     */
    public function uploadImage(string $filePath, string $name = ''): string
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Image file not found: {$filePath}");
        }

        $imageData = file_get_contents($filePath);
        if ($imageData === false) {
            throw new \RuntimeException("Failed to read image file: {$filePath}");
        }

        if ($name === '') {
            $name = pathinfo($filePath, PATHINFO_FILENAME);
        }

        $client  = Client::get();
        $service = $client->getAssetServiceClient();

        $asset = new Asset([
            'name'        => $name,
            'image_asset' => new ImageAsset([
                'data' => $imageData,
            ]),
        ]);

        $op = new AssetOperation();
        $op->setCreate($asset);

        $response = $service->mutateAssets(
            MutateAssetsRequest::build($this->customerId, [$op])
        );

        return $response->getResults()[0]->getResourceName();
    }

    /**
     * Create a YouTube video asset for use in video/DemandGen campaigns.
     *
     * @param  string $youtubeVideoId YouTube video ID (e.g. 'dQw4w9WgXcQ').
     * @return string                 Resource name of the created asset.
     */
    public function uploadYouTubeVideo(string $youtubeVideoId): string
    {
        $client  = Client::get();
        $service = $client->getAssetServiceClient();

        $asset = new Asset([
            'youtube_video_asset' => new YoutubeVideoAsset([
                'youtube_video_id' => $youtubeVideoId,
            ]),
        ]);

        $op = new AssetOperation();
        $op->setCreate($asset);

        $response = $service->mutateAssets(
            MutateAssetsRequest::build($this->customerId, [$op])
        );

        return $response->getResults()[0]->getResourceName();
    }

    /**
     * List all image assets in the account.
     *
     * @return array Array of ['resource_name', 'name', 'type', 'file_size'].
     */
    public function listImageAssets(): array
    {
        $client  = Client::get();
        $service = $client->getGoogleAdsServiceClient();

        $query = <<<GAQL
            SELECT
                asset.resource_name,
                asset.name,
                asset.type,
                asset.image_asset.file_size
            FROM asset
            WHERE asset.type = 'IMAGE'
            GAQL;

        $request = new SearchGoogleAdsRequest([
            'customer_id' => $this->customerId,
            'query'       => $query,
        ]);

        $rows = [];
        foreach ($service->search($request)->iterateAllElements() as $row) {
            $asset = $row->getAsset();
            $rows[] = [
                'resource_name' => $asset->getResourceName(),
                'name'          => $asset->getName(),
                'type'          => $asset->getType(),
                'file_size'     => $asset->getImageAsset()?->getFileSize() ?? 0,
            ];
        }
        return $rows;
    }
}
