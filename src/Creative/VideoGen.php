<?php

namespace AdManager\Creative;

class VideoGen
{
    private const API_BASE = 'https://api.klingai.com/v1';
    private const POLL_INTERVAL = 5;   // seconds
    private const MAX_WAIT      = 300; // 5 minutes

    private string $accessKey;
    private string $secretKey;
    private string $assetsDir;

    public function __construct()
    {
        $this->accessKey = $_ENV['KLING_ACCESS_KEY'] ?? getenv('KLING_ACCESS_KEY') ?: '';
        $this->secretKey = $_ENV['KLING_SECRET_KEY'] ?? getenv('KLING_SECRET_KEY') ?: '';
        if (!$this->accessKey || !$this->secretKey) {
            throw new \RuntimeException('KLING_ACCESS_KEY and KLING_SECRET_KEY must be set');
        }

        $this->assetsDir = dirname(__DIR__, 2) . '/assets/videos';
        if (!is_dir($this->assetsDir)) {
            mkdir($this->assetsDir, 0755, true);
        }
    }

    /**
     * Generate a video from a text prompt via Kling API.
     *
     * @param  string $prompt          Text description of the desired video
     * @param  int    $durationSeconds Video duration (default 5s)
     * @param  string $aspectRatio     Aspect ratio (default '16:9')
     * @return string                  Local file path of the downloaded video
     */
    public function generate(string $prompt, int $durationSeconds = 5, string $aspectRatio = '16:9'): string
    {
        // Create video generation task
        $payload = [
            'prompt'       => $prompt,
            'duration'     => $durationSeconds,
            'aspect_ratio' => $aspectRatio,
        ];

        $response = $this->post('/videos/text2video', $payload);

        $taskId = $response['data']['task_id'] ?? ($response['task_id'] ?? null);
        if (!$taskId) {
            throw new \RuntimeException('Kling API did not return a task_id: ' . json_encode($response));
        }

        // Poll until complete or timeout
        $elapsed = 0;
        while ($elapsed < self::MAX_WAIT) {
            sleep(self::POLL_INTERVAL);
            $elapsed += self::POLL_INTERVAL;

            $status = $this->getStatus($taskId);

            if ($status['status'] === 'completed') {
                $videoUrl = $status['url'] ?? '';
                if (!$videoUrl) {
                    throw new \RuntimeException('Kling task completed but no video URL returned');
                }

                return $this->downloadVideo($videoUrl, $prompt);
            }

            if ($status['status'] === 'failed') {
                $reason = $status['error'] ?? 'unknown';
                throw new \RuntimeException("Kling video generation failed: {$reason}");
            }

            // Still processing — continue polling
        }

        throw new \RuntimeException("Kling video generation timed out after " . self::MAX_WAIT . " seconds (task: {$taskId})");
    }

    /**
     * Get the status of a video generation task.
     *
     * @return array{status: string, url: string|null, error: string|null}
     */
    public function getStatus(string $taskId): array
    {
        $response = $this->get("/videos/text2video/{$taskId}");

        $data = $response['data'] ?? $response;

        $status = $data['task_status'] ?? ($data['status'] ?? 'processing');

        // Normalise status values
        $statusMap = [
            'succeed'    => 'completed',
            'success'    => 'completed',
            'completed'  => 'completed',
            'failed'     => 'failed',
            'fail'       => 'failed',
        ];
        $normStatus = $statusMap[$status] ?? 'processing';

        // Extract video URL from various response shapes
        $url = null;
        if (isset($data['task_result']['videos'][0]['url'])) {
            $url = $data['task_result']['videos'][0]['url'];
        } elseif (isset($data['video_url'])) {
            $url = $data['video_url'];
        } elseif (isset($data['url'])) {
            $url = $data['url'];
        }

        return [
            'status' => $normStatus,
            'url'    => $url,
            'error'  => $data['error'] ?? ($data['task_status_msg'] ?? null),
        ];
    }

    /**
     * POST to Kling API.
     */
    private function post(string $path, array $payload): array
    {
        $url = self::API_BASE . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->generateJwt(),
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        return $this->handleResponse($ch);
    }

    /**
     * GET from Kling API.
     */
    private function get(string $path): array
    {
        $url = self::API_BASE . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->generateJwt(),
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        return $this->handleResponse($ch);
    }

    /**
     * Execute curl and parse JSON response.
     */
    private function handleResponse($ch): array
    {
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Kling API request failed: {$error}");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("Kling API error (HTTP {$httpCode}): {$body}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Failed to parse Kling API response as JSON');
        }

        return $decoded;
    }

    /**
     * Download a video from URL and save locally.
     */
    private function downloadVideo(string $url, string $prompt): string
    {
        $videoData = file_get_contents($url);
        if ($videoData === false) {
            throw new \RuntimeException("Failed to download video from {$url}");
        }

        $timestamp = date('Ymd-His');
        $hash      = substr(md5($prompt . microtime()), 0, 8);
        $filename  = "{$timestamp}-{$hash}.mp4";
        $filePath  = "{$this->assetsDir}/{$filename}";

        if (file_put_contents($filePath, $videoData) === false) {
            throw new \RuntimeException("Failed to write video to {$filePath}");
        }

        return $filePath;
    }

    /**
     * Generate a short-lived JWT for Kling API auth (HS256).
     */
    private function generateJwt(): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $now = time();
        $payload = [
            'iss' => $this->accessKey,
            'exp' => $now + 1800, // 30 min
            'nbf' => $now - 5,
            'iat' => $now,
        ];

        $b64 = fn(array $d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
        $segments = $b64($header) . '.' . $b64($payload);
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $segments, $this->secretKey, true)), '+/', '-_'), '=');

        return $segments . '.' . $sig;
    }
}
