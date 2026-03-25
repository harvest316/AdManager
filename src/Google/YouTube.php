<?php

namespace AdManager\Google;

class YouTube
{
    private const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';
    private const SCOPE      = 'https://www.googleapis.com/auth/youtube.upload';

    private string $clientId;
    private string $clientSecret;
    private string $refreshToken;

    public function __construct()
    {
        $this->clientId     = getenv('GOOGLE_ADS_CLIENT_ID') ?: '';
        $this->clientSecret = getenv('GOOGLE_ADS_CLIENT_SECRET') ?: '';
        $this->refreshToken = getenv('YOUTUBE_REFRESH_TOKEN') ?: '';

        if (!$this->clientId || !$this->clientSecret) {
            throw new \RuntimeException('GOOGLE_ADS_CLIENT_ID and GOOGLE_ADS_CLIENT_SECRET must be set');
        }
        if (!$this->refreshToken) {
            throw new \RuntimeException('YOUTUBE_REFRESH_TOKEN must be set');
        }
    }

    /**
     * Upload a video to YouTube using resumable upload.
     *
     * @param  string   $filePath    Local path to the video file
     * @param  string   $title       Video title
     * @param  string   $description Video description
     * @param  string   $privacy     'unlisted', 'private', or 'public'
     * @param  string[] $tags        Video tags
     * @return string                YouTube video ID
     */
    public function upload(string $filePath, string $title, string $description = '', string $privacy = 'unlisted', array $tags = []): string
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Video file not found: {$filePath}");
        }

        $accessToken = $this->getAccessToken();

        // Step 1: Initiate resumable upload — send metadata, get upload URI
        $metadata = [
            'snippet' => [
                'title'       => $title,
                'description' => $description,
                'tags'        => $tags,
                'categoryId'  => '22', // People & Blogs
            ],
            'status' => [
                'privacyStatus'           => $privacy,
                'selfDeclaredMadeForKids' => false,
            ],
        ];

        $initUrl = self::UPLOAD_URL . '?uploadType=resumable&part=snippet,status';

        $ch = curl_init($initUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($metadata),
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=UTF-8',
                'X-Upload-Content-Type: video/*',
                'X-Upload-Content-Length: ' . filesize($filePath),
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $initResponse = curl_exec($ch);
        $initHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($initHttpCode !== 200) {
            throw new \RuntimeException("YouTube upload init failed (HTTP {$initHttpCode}): {$initResponse}");
        }

        // Extract upload URI from Location header
        if (!preg_match('/^Location:\s*(.+)$/mi', $initResponse, $matches)) {
            throw new \RuntimeException('YouTube upload init did not return Location header');
        }
        $uploadUri = trim($matches[1]);

        // Step 2: Upload the file bytes via PUT
        $fileData = file_get_contents($filePath);
        if ($fileData === false) {
            throw new \RuntimeException("Failed to read video file: {$filePath}");
        }

        $ch = curl_init($uploadUri);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $fileData,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: video/*',
                'Content-Length: ' . strlen($fileData),
            ],
            CURLOPT_TIMEOUT => 600, // 10 minutes for large uploads
        ]);

        $uploadBody     = curl_exec($ch);
        $uploadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $uploadError    = curl_error($ch);
        curl_close($ch);

        if ($uploadBody === false) {
            throw new \RuntimeException("YouTube file upload failed: {$uploadError}");
        }

        if ($uploadHttpCode < 200 || $uploadHttpCode >= 300) {
            throw new \RuntimeException("YouTube file upload failed (HTTP {$uploadHttpCode}): {$uploadBody}");
        }

        // Step 3: Parse response for video ID
        $result = json_decode($uploadBody, true);
        if (!is_array($result) || !isset($result['id'])) {
            throw new \RuntimeException('YouTube upload response missing video ID: ' . $uploadBody);
        }

        return $result['id'];
    }

    /**
     * Get the public URL for a YouTube video.
     */
    public function getVideoUrl(string $videoId): string
    {
        return "https://www.youtube.com/watch?v={$videoId}";
    }

    /**
     * Exchange refresh token for an access token.
     */
    private function getAccessToken(): string
    {
        $payload = [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type'    => 'refresh_token',
        ];

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Google token exchange failed: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Google token exchange failed (HTTP {$httpCode}): {$body}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['access_token'])) {
            throw new \RuntimeException('Google token response missing access_token');
        }

        return $decoded['access_token'];
    }
}
