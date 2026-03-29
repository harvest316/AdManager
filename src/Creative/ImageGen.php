<?php

namespace AdManager\Creative;

class ImageGen
{
    private const MODELS = [
        'draft'      => 'google/gemini-2.5-flash-image',
        'production' => 'black-forest-labs/flux-1.1-pro',
    ];

    private const COSTS = [
        'draft'      => 0.00,
        'production' => 0.04,
    ];

    private string $apiKey;
    private string $assetsDir;

    public function __construct()
    {
        $this->apiKey = $_ENV['OPENROUTER_API_KEY'] ?? getenv('OPENROUTER_API_KEY') ?: '';
        if (!$this->apiKey) {
            throw new \RuntimeException('OPENROUTER_API_KEY not set');
        }

        $this->assetsDir = dirname(__DIR__, 2) . '/assets/images';
        if (!is_dir($this->assetsDir)) {
            mkdir($this->assetsDir, 0755, true);
        }
    }

    /**
     * Generate an image from a text prompt.
     *
     * @param  string $prompt  Text description of the desired image
     * @param  string $mode    'draft' (free, Gemini Flash) or 'production' (FLUX, ~$0.04)
     * @param  int    $width   Image width in pixels
     * @param  int    $height  Image height in pixels
     * @return string          Local file path of the saved image
     */
    public function generate(string $prompt, string $mode = 'draft', int $width = 1200, int $height = 628): string
    {
        if (!isset(self::MODELS[$mode])) {
            throw new \InvalidArgumentException("Invalid mode '{$mode}'. Use 'draft' or 'production'.");
        }

        $model = self::MODELS[$mode];

        if ($mode === 'draft') {
            // Gemini Flash Image — generates images natively
            $messages = [
                [
                    'role'    => 'user',
                    'content' => "Generate an image: {$prompt}. Dimensions: {$width}x{$height} pixels.",
                ],
            ];

            $response = $this->callOpenRouter($model, $messages);

            $message = $response['choices'][0]['message'] ?? [];
            $content = $message['content'] ?? '';
            $imageData = '';

            // Gemini image models return content as an array of parts
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (isset($part['type']) && $part['type'] === 'image_url' && isset($part['image_url']['url'])) {
                        $dataUrl = $part['image_url']['url'];
                        if (preg_match('#^data:image/[a-z]+;base64,(.+)$#s', $dataUrl, $m)) {
                            $imageData = base64_decode($m[1], true);
                            break;
                        }
                    }
                }
            }

            // Fallback: content might be a plain base64 string or data URL
            if (!$imageData && is_string($content)) {
                $content = preg_replace('/^```[a-z]*\s*/i', '', $content);
                $content = preg_replace('/\s*```\s*$/', '', $content);
                $content = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $content);
                $content = trim($content);
                if ($content !== '') {
                    $imageData = base64_decode($content, true);
                }
            }

            if (!$imageData) {
                throw new \RuntimeException('Failed to extract image data from model response');
            }
        } else {
            // FLUX production model — image generation via chat completions
            $messages = [
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ];

            $extra = [
                'response_format' => ['type' => 'image'],
                'image_size'      => "{$width}x{$height}",
            ];

            $response = $this->callOpenRouter($model, $messages, $extra);

            // FLUX returns base64 image in the response
            $content = $response['choices'][0]['message']['content'] ?? '';

            // Handle potential URL response
            if (filter_var($content, FILTER_VALIDATE_URL)) {
                $imageData = file_get_contents($content);
                if ($imageData === false) {
                    throw new \RuntimeException('Failed to download generated image from URL');
                }
            } else {
                $content = preg_replace('/^data:image\/[a-z]+;base64,/i', '', trim($content));
                $imageData = base64_decode($content, true);
                if ($imageData === false) {
                    throw new \RuntimeException('Failed to decode image data from FLUX response');
                }
            }
        }

        // Sanity check — don't save empty files
        if (strlen($imageData) < 100) {
            throw new \RuntimeException('Image data too small (' . strlen($imageData) . ' bytes) — likely not a real image');
        }

        // Save to file
        $timestamp = date('Ymd-His');
        $hash      = substr(md5($prompt . $mode . microtime()), 0, 8);
        $filename  = "{$timestamp}-{$hash}.png";
        $filePath  = "{$this->assetsDir}/{$filename}";

        if (file_put_contents($filePath, $imageData) === false) {
            throw new \RuntimeException("Failed to write image to {$filePath}");
        }

        return $filePath;
    }

    /**
     * Estimated USD cost per image for the given mode.
     */
    public function estimateCost(string $mode): float
    {
        if (!isset(self::COSTS[$mode])) {
            throw new \InvalidArgumentException("Invalid mode '{$mode}'. Use 'draft' or 'production'.");
        }

        return self::COSTS[$mode];
    }

    /**
     * Call the OpenRouter chat completions API.
     */
    private function callOpenRouter(string $model, array $messages, array $extra = []): array
    {
        $payload = array_merge([
            'model'    => $model,
            'messages' => $messages,
        ], $extra);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'HTTP-Referer: https://auditandfix.com',
                'X-Title: AdManager',
            ],
            CURLOPT_TIMEOUT => 120,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("OpenRouter request failed: {$error}");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("OpenRouter API error (HTTP {$httpCode}): {$body}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Failed to parse OpenRouter response as JSON');
        }

        if (isset($decoded['error'])) {
            throw new \RuntimeException('OpenRouter error: ' . ($decoded['error']['message'] ?? json_encode($decoded['error'])));
        }

        return $decoded;
    }
}
