<?php

namespace AdManager\Meta;

use CURLFile;
use RuntimeException;

/**
 * Meta ad image and video asset management.
 *
 * Images return a hash used in creatives.
 * Videos require processing time after upload.
 */
class Assets
{
    private Client $client;
    private string $adAccountId;

    public function __construct()
    {
        $this->client      = Client::get();
        $this->adAccountId = $this->client->adAccountId();
    }

    /**
     * Upload an image for use in ad creatives.
     *
     * @param  string $filePath Local path to image file (JPG/PNG)
     * @return array  ['hash' => image_hash, 'url' => permalink_url]
     */
    public function uploadImage(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Image file not found: {$filePath}");
        }

        $response = $this->client->postMultipart("{$this->adAccountId}/adimages", [
            'filename' => new CURLFile($filePath),
        ]);

        // Response: {"images": {"filename": {"hash": "...", "url": "..."}}}
        $images = $response['images'] ?? [];
        $imageData = reset($images);

        if (!$imageData || empty($imageData['hash'])) {
            throw new RuntimeException('Failed to extract image hash from upload response: ' . json_encode($response));
        }

        return [
            'hash' => $imageData['hash'],
            'url'  => $imageData['url'] ?? '',
        ];
    }

    /**
     * Upload a video for use in ad creatives.
     *
     * @param  string $filePath Local path to video file (MP4)
     * @param  string $title    Optional video title
     * @return string Video ID
     */
    public function uploadVideo(string $filePath, string $title = ''): string
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Video file not found: {$filePath}");
        }

        $data = [
            'source' => new CURLFile($filePath),
        ];

        if ($title) {
            $data['title'] = $title;
        }

        $response = $this->client->postMultipart("{$this->adAccountId}/advideos", $data);

        if (empty($response['id'])) {
            throw new RuntimeException('Failed to extract video ID from upload response: ' . json_encode($response));
        }

        return $response['id'];
    }

    /**
     * Wait for a video to finish processing.
     *
     * Meta processes uploaded videos asynchronously. This polls until
     * the video status is 'ready' or a timeout is reached.
     *
     * @param  string $videoId        Video ID to check
     * @param  int    $timeoutSeconds Max seconds to wait (default 300 = 5 min)
     * @param  int    $intervalSeconds Seconds between polls (default 10)
     * @return array  Video status data
     */
    public function waitForProcessing(string $videoId, int $timeoutSeconds = 300, int $intervalSeconds = 10): array
    {
        $start = time();

        while (true) {
            $status = $this->client->get_api($videoId, [
                'fields' => 'status,title,length,source',
            ]);

            $videoStatus = $status['status']['video_status'] ?? 'processing';

            if ($videoStatus === 'ready') {
                return $status;
            }

            if ($videoStatus === 'error') {
                throw new RuntimeException("Video processing failed for {$videoId}: " . json_encode($status['status']));
            }

            if ((time() - $start) >= $timeoutSeconds) {
                throw new RuntimeException(
                    "Video processing timeout ({$timeoutSeconds}s) for {$videoId}. "
                    . "Current status: {$videoStatus}"
                );
            }

            sleep($intervalSeconds);
        }
    }

    /**
     * List ad images for the account.
     */
    public function listImages(): array
    {
        $response = $this->client->get_api("{$this->adAccountId}/adimages", [
            'fields' => 'hash,name,permalink_url,width,height,created_time',
            'limit'  => 100,
        ]);

        return $response['data'] ?? [];
    }

    /**
     * List ad videos for the account.
     */
    public function listVideos(): array
    {
        $response = $this->client->get_api("{$this->adAccountId}/advideos", [
            'fields' => 'id,title,status,length,source,created_time',
            'limit'  => 100,
        ]);

        return $response['data'] ?? [];
    }
}
