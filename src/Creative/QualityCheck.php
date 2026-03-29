<?php

namespace AdManager\Creative;

use AdManager\DB;

/**
 * Computer-vision QA pre-filter for creative assets.
 *
 * Uses Claude CLI with vision to check images/videos before human review.
 * Runs automatically when assets are generated — not a manual step.
 */
class QualityCheck
{
    private string $claudeBin;

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
        $this->claudeBin = getenv('CLAUDE_BIN') ?: '/home/jason/.local/bin/claude';
    }

    /**
     * Run QA check on an asset. Updates the DB with results.
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
            $result = $this->checkFile($asset['local_path']);
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
        }

        return $results;
    }

    /**
     * Check an image file using Claude CLI vision.
     */
    private function checkFile(string $path): array
    {
        if (!$path || !file_exists($path)) {
            return ['status' => 'warning', 'issues' => [
                ['category' => 'quality', 'severity' => 'warning', 'description' => 'File not found']
            ]];
        }

        return $this->callClaude($path);
    }

    /**
     * Extract a frame from video and check it.
     */
    private function checkVideoFrame(string $path): array
    {
        if (!$path || !file_exists($path)) {
            return ['status' => 'warning', 'issues' => [
                ['category' => 'quality', 'severity' => 'warning', 'description' => 'Video file not found']
            ]];
        }

        $framePath = sys_get_temp_dir() . '/admanager_qa_frame_' . uniqid() . '.jpg';
        $ffmpeg = getenv('FFMPEG_PATH') ?: 'ffmpeg';
        exec(sprintf(
            '%s -i %s -ss 1 -frames:v 1 -q:v 2 %s -y 2>/dev/null',
            escapeshellarg($ffmpeg), escapeshellarg($path), escapeshellarg($framePath)
        ), $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($framePath)) {
            return ['status' => 'warning', 'issues' => [
                ['category' => 'quality', 'severity' => 'warning', 'description' => 'Could not extract video frame']
            ]];
        }

        $result = $this->callClaude($framePath);
        @unlink($framePath);
        return $result;
    }

    /**
     * Shell out to Claude CLI with an image file for vision QA.
     */
    private function callClaude(string $imagePath): array
    {
        $prompt = escapeshellarg(self::PROMPT);
        $path = escapeshellarg($imagePath);

        // Claude CLI accepts image files directly with the prompt
        $cmd = "{$this->claudeBin} -p {$prompt} --model haiku --output-format text {$path}";

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['status' => 'warning', 'issues' => [
                ['category' => 'quality', 'severity' => 'warning', 'description' => 'Failed to start Claude CLI']
            ]];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $startTime = time();
        $timeout = 60;

        while (true) {
            $status = proc_get_status($process);
            $out = stream_get_contents($pipes[1]);
            if ($out) $stdout .= $out;
            if (!$status['running']) break;
            if ((time() - $startTime) > $timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return ['status' => 'warning', 'issues' => [
                    ['category' => 'quality', 'severity' => 'warning', 'description' => 'Claude CLI timed out']
                ]];
            }
            usleep(100_000);
        }

        $out = stream_get_contents($pipes[1]);
        if ($out) $stdout .= $out;
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $stdout = trim($stdout);

        // Strip markdown fencing
        $stdout = preg_replace('/^```json?\s*/i', '', $stdout);
        $stdout = preg_replace('/\s*```\s*$/', '', $stdout);
        $stdout = trim($stdout);

        $result = json_decode($stdout, true);

        if (!is_array($result) || !isset($result['status'])) {
            return ['status' => 'warning', 'issues' => [
                ['category' => 'quality', 'severity' => 'warning', 'description' => 'Could not parse Claude response']
            ]];
        }

        return $result;
    }
}
