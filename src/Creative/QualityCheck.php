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
    private string $promptsDir;
    private string $policiesDir;

    private const GOOGLE_VISUAL_POLICIES = <<<'POLICY'
### Google Ads Visual Policy Rules
12. **MISLEADING UI**: Does the image contain fake buttons, fake cursor icons, fake form fields, or other non-functional interactive elements that trick users into clicking?
13. **BEFORE/AFTER**: Does the image show before/after comparison for health, weight loss, or cosmetic procedures without proper disclaimers?
14. **TEXT COVERAGE**: Does text cover more than approximately 20% of the image area? (Google PMax and Display strongly penalise text-heavy images.)
15. **GIMMICKY FORMATTING**: Does the image use flashing, pulsing, or otherwise attention-grabbing animated elements?
POLICY;

    private const META_VISUAL_POLICIES = <<<'POLICY'
### Meta Visual Policy Rules
12. **BEFORE/AFTER**: Does the image show before/after for health, weight loss, cosmetics, or body modification? Meta prohibits this in many categories.
13. **PERSONAL ATTRIBUTES**: Does the image assert or imply the viewer has a specific personal attribute (health condition, body type, financial status, etc.)?
14. **PLATFORM ENDORSEMENT**: Does the image imply endorsement by Facebook, Instagram, or Meta?
15. **FAKE UI ELEMENTS**: Does the image contain non-functional play buttons, notification badges, close buttons, or other fake interactive elements?
16. **SENSATIONAL IMAGERY**: Does the image use exploitative, graphic, or exaggerated imagery to provoke shock or fear?
POLICY;

    public function __construct()
    {
        $this->claudeBin = getenv('CLAUDE_BIN') ?: '/home/jason/.local/bin/claude';
        $this->promptsDir = dirname(__DIR__, 2) . '/prompts';
        $this->policiesDir = dirname(__DIR__, 2) . '/policies';
    }

    /**
     * Build the QA prompt with platform-specific policy rules.
     */
    private function buildPrompt(string $platform = 'all'): string
    {
        $templatePath = $this->promptsDir . '/IMAGE-POLICY-CHECK.md';
        if (!file_exists($templatePath)) {
            // Fallback to basic prompt if template missing
            return $this->fallbackPrompt();
        }

        $template = file_get_contents($templatePath);

        // Select platform-specific policy rules
        $policyRules = '';
        if ($platform === 'google' || $platform === 'all') {
            $policyRules .= self::GOOGLE_VISUAL_POLICIES . "\n";
        }
        if ($platform === 'meta' || $platform === 'all') {
            $policyRules .= self::META_VISUAL_POLICIES . "\n";
        }

        return str_replace('{{PLATFORM_POLICIES}}', $policyRules, $template);
    }

    private function fallbackPrompt(): string
    {
        return 'You are a creative QA specialist. Check this ad image for: text legibility, AI artefacts, dimensions, brand safety, quality, composition. Respond in JSON: {"status": "pass"|"fail"|"warning", "issues": [{"category":"...", "severity":"fail|warning", "description":"..."}]}';
    }

    /**
     * Run QA check on an asset. Updates the DB with results.
     *
     * @param string $platform Target ad platform ('google', 'meta', 'all') for policy-specific checks
     */
    public function check(int $assetId, string $platform = 'all'): array
    {
        $db = DB::get();
        $stmt = $db->prepare('SELECT * FROM assets WHERE id = ?');
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch();

        if (!$asset) {
            throw new \RuntimeException("Asset #{$assetId} not found");
        }

        if ($asset['type'] === 'image') {
            $result = $this->checkFile($asset['local_path'], $platform);
        } elseif ($asset['type'] === 'video') {
            $result = $this->checkVideoFrame($asset['local_path'], $platform);
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
     *
     * @param string $platform Target ad platform for policy checks
     */
    public function checkAllDrafts(int $projectId, string $platform = 'all'): array
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
            $results[$asset['id']] = $this->check((int)$asset['id'], $platform);
        }

        return $results;
    }

    /**
     * Check an image file using Claude CLI vision.
     */
    private function checkFile(string $path, string $platform = 'all'): array
    {
        if (!$path || !file_exists($path)) {
            return ['status' => 'warning', 'issues' => [
                ['category' => 'quality', 'severity' => 'warning', 'description' => 'File not found']
            ]];
        }

        return $this->callClaude($path, $platform);
    }

    /**
     * Extract a frame from video and check it.
     */
    private function checkVideoFrame(string $path, string $platform = 'all'): array
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

        $result = $this->callClaude($framePath, $platform);
        @unlink($framePath);
        return $result;
    }

    /**
     * Shell out to Claude CLI with an image file for vision QA.
     */
    private function callClaude(string $imagePath, string $platform = 'all'): array
    {
        $prompt = escapeshellarg($this->buildPrompt($platform));
        $path = escapeshellarg($imagePath);

        // Claude CLI accepts image files directly with the prompt
        // Using Sonnet for policy compliance checks (higher stakes than artifact detection)
        $cmd = "{$this->claudeBin} -p {$prompt} --model sonnet --output-format text {$path}";

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
