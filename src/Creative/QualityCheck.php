<?php

namespace AdManager\Creative;

use AdManager\DB;

/**
 * Computer-vision QA pre-filter for creative assets.
 *
 * Uses OpenRouter vision models to check images/videos before human review.
 * Checks: text legibility, brand consistency, ad policy compliance, AI artefacts,
 * correct dimensions, and overall quality.
 */
class QualityCheck
{
    private string $apiKey;
    private string $model;

    private const PROMPT = <<<'PROMPT'
You are a creative QA specialist reviewing an ad image/video frame for a paid media campaign.

Check for these issues and report ONLY problems found. If the image passes all checks, say "PASS".

1. TEXT LEGIBILITY: Is any overlay text cut off, too small, wrong contrast, or unreadable?
2. AI ARTEFACTS: Are there distorted faces, extra fingers, melted text, impossible geometry, or other AI generation failures?
3. DIMENSIONS: Does the image appear to be the wrong aspect ratio for ads (should be roughly 1:1, 4:5, 9:16, or 16:9)?
4. BRAND SAFETY: Does the image contain anything that would violate Meta or Google ad policies (violence, nudity, misleading claims, prohibited content)?
5. QUALITY: Is the image blurry, pixelated, over-compressed, or clearly low quality?
6. COMPOSITION: Is the subject matter clear? Would a viewer understand what this ad is about in under 2 seconds?

Respond in this exact JSON format (no markdown fencing):
{
  "status": "pass" | "fail" | "warning",
  "issues": [
    {"category": "text_legibility|ai_artefacts|dimensions|brand_safety|quality|composition", "severity": "fail|warning", "description": "specific issue found"}
  ]
}

If no issues, respond: {"status": "pass", "issues": []}
PROMPT;

    public function __construct()
    {
        $this->apiKey = $_ENV['OPENROUTER_API_KEY'] ?? getenv('OPENROUTER_API_KEY') ?: '';
        if ($this->apiKey === '') {
            throw new \RuntimeException('OPENROUTER_API_KEY is required for QA checks');
        }
        // Use a fast, cheap vision model for QA
        $this->model = 'google/gemini-flash-1.5';
    }

    /**
     * Run QA check on an asset. Updates the DB with results.
     *
     * @return array{status: string, issues: array}
     */
    public function check(int $assetId): array
    {
        $db = DB::get();
        $stmt = $db->prepare('SELECT * FROM assets WHERE id = ?');
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch();

        if (!$asset) {
            throw new \RuntimeException("Asset #{$assetId} not found");
        }

        if ($asset['type'] === 'image') {
            $result = $this->checkImage($asset['local_path']);
        } elseif ($asset['type'] === 'video') {
            $result = $this->checkVideoFrame($asset['local_path']);
        } else {
            $result = ['status' => 'pass', 'issues' => []];
        }

        // Save results to DB
        $update = $db->prepare(
            'UPDATE assets SET cv_qa_status = ?, cv_qa_issues = ?, cv_qa_checked_at = datetime("now") WHERE id = ?'
        );
        $update->execute([
            $result['status'],
            json_encode($result['issues']),
            $assetId,
        ]);

        // Auto-reject if any "fail" severity issues
        $hasFail = false;
        foreach ($result['issues'] as $issue) {
            if (($issue['severity'] ?? '') === 'fail') {
                $hasFail = true;
                break;
            }
        }

        if ($hasFail && $asset['status'] === 'draft') {
            $reasons = array_map(
                fn($i) => "[{$i['category']}] {$i['description']}",
                array_filter($result['issues'], fn($i) => ($i['severity'] ?? '') === 'fail')
            );
            $db->prepare('UPDATE assets SET status = ?, rejected_reason = ? WHERE id = ?')
               ->execute(['rejected', 'CV QA: ' . implode('; ', $reasons), $assetId]);
        }

        return $result;
    }

    /**
     * Run QA on all unchecked draft assets for a project.
     */
    public function checkAllDrafts(int $projectId): array
    {
        $db = DB::get();
        $stmt = $db->prepare(
            'SELECT id FROM assets WHERE project_id = ? AND status = ? AND cv_qa_status IS NULL'
        );
        $stmt->execute([$projectId, 'draft']);
        $assets = $stmt->fetchAll();

        $results = [];
        foreach ($assets as $asset) {
            echo "  Checking asset #{$asset['id']}...\n";
            $results[$asset['id']] = $this->check((int)$asset['id']);
            usleep(500_000); // rate limit
        }

        return $results;
    }

    private function checkImage(string $path): array
    {
        if (!$path || !file_exists($path)) {
            return ['status' => 'warning', 'issues' => [
                ['category' => 'quality', 'severity' => 'warning', 'description' => 'Image file not found at path']
            ]];
        }

        $imageData = base64_encode(file_get_contents($path));
        $mimeType = mime_content_type($path) ?: 'image/png';

        return $this->callVision($imageData, $mimeType);
    }

    private function checkVideoFrame(string $path): array
    {
        if (!$path || !file_exists($path)) {
            return ['status' => 'warning', 'issues' => [
                ['category' => 'quality', 'severity' => 'warning', 'description' => 'Video file not found at path']
            ]];
        }

        // Extract a frame at 1 second using ffmpeg
        $framePath = sys_get_temp_dir() . '/admanager_qa_frame_' . uniqid() . '.jpg';
        $ffmpeg = getenv('FFMPEG_PATH') ?: 'ffmpeg';
        $cmd = sprintf(
            '%s -i %s -ss 1 -frames:v 1 -q:v 2 %s -y 2>/dev/null',
            escapeshellarg($ffmpeg),
            escapeshellarg($path),
            escapeshellarg($framePath)
        );
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($framePath)) {
            return ['status' => 'warning', 'issues' => [
                ['category' => 'quality', 'severity' => 'warning', 'description' => 'Could not extract video frame for QA']
            ]];
        }

        $imageData = base64_encode(file_get_contents($framePath));
        $result = $this->callVision($imageData, 'image/jpeg');
        @unlink($framePath);

        return $result;
    }

    private function callVision(string $base64Image, string $mimeType): array
    {
        $payload = [
            'model'    => $this->model,
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => self::PROMPT],
                        ['type' => 'image_url', 'image_url' => [
                            'url' => "data:{$mimeType};base64,{$base64Image}",
                        ]],
                    ],
                ],
            ],
            'max_tokens'  => 500,
            'temperature' => 0,
        ];

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300 || !$body) {
            return ['status' => 'warning', 'issues' => [
                ['category' => 'quality', 'severity' => 'warning', 'description' => "Vision API error (HTTP {$httpCode})"]
            ]];
        }

        $response = json_decode($body, true);
        $content = $response['choices'][0]['message']['content'] ?? '';

        // Strip markdown fencing if present
        $content = preg_replace('/^```json?\s*/i', '', $content);
        $content = preg_replace('/\s*```\s*$/', '', $content);
        $content = trim($content);

        $result = json_decode($content, true);

        if (!is_array($result) || !isset($result['status'])) {
            return ['status' => 'warning', 'issues' => [
                ['category' => 'quality', 'severity' => 'warning', 'description' => 'Could not parse vision model response']
            ]];
        }

        return $result;
    }
}
