<?php

namespace AdManager\Copy;

use AdManager\DB;
use AdManager\Locale;

/**
 * LLM-powered ad copy proofreader.
 *
 * Uses Claude Opus via CLI to evaluate copy for sales effectiveness,
 * platform policy compliance, and quality. Feeds platform policy docs
 * as context alongside the proofreading prompt.
 */
class Proofreader
{
    private string $claudeBin;
    private string $promptsDir;
    private string $policiesDir;
    private int $timeout = 180;

    public function __construct()
    {
        $this->claudeBin = getenv('CLAUDE_BIN') ?: '/home/jason/.local/bin/claude';
        $this->promptsDir = dirname(__DIR__, 2) . '/prompts';
        $this->policiesDir = dirname(__DIR__, 2) . '/policies';
    }

    private const BATCH_SIZE = 15;

    /**
     * Proofread a batch of copy items (auto-batches if > BATCH_SIZE).
     *
     * @param array  $items    Copy items from ad_copy table
     * @param array  $project  Project row (needs: display_name, website_url)
     * @param array  $strategy Strategy row (needs: target_audience, value_proposition)
     * @param string $market   Target market code
     * @return array|null  Parsed LLM response or null on failure
     */
    public function proofread(array $items, array $project, array $strategy, string $market = 'all'): ?array
    {
        if (count($items) <= self::BATCH_SIZE) {
            return $this->proofreadBatch($items, $project, $strategy, $market);
        }

        // Auto-batch: split into chunks and merge results
        $chunks = array_chunk($items, self::BATCH_SIZE);
        $allItems = [];
        $totalScore = 0;
        $batchCount = 0;

        foreach ($chunks as $i => $chunk) {
            echo "    Batch " . ($i + 1) . "/" . count($chunks) . " (" . count($chunk) . " items)...\n";
            $result = $this->proofreadBatch($chunk, $project, $strategy, $market);
            if ($result === null) {
                echo "    Batch " . ($i + 1) . " failed, skipping\n";
                continue;
            }
            $allItems = array_merge($allItems, $result['items'] ?? []);
            $totalScore += $result['overall_score'] ?? 0;
            $batchCount++;
        }

        if ($batchCount === 0) return null;

        return [
            'overall_score' => (int) round($totalScore / $batchCount),
            'items' => $allItems,
        ];
    }

    /**
     * Proofread a single batch of items.
     */
    private function proofreadBatch(array $items, array $project, array $strategy, string $market): ?array
    {
        $prompt = $this->buildPrompt($items, $project, $strategy, $market);
        $response = $this->callClaude($prompt);

        if ($response === null) {
            return null;
        }

        return $this->parseResponse($response);
    }

    /**
     * Build the full prompt with context and copy items.
     */
    private function buildPrompt(array $items, array $project, array $strategy, string $market): string
    {
        $template = file_get_contents($this->promptsDir . '/PROOFREAD.md');

        // Determine which platform policies to include
        $platforms = array_unique(array_column($items, 'platform'));
        $policies = $this->loadPolicies($platforms);

        $localeInstruction = Locale::promptInstruction($market);

        // Build copy items JSON for the prompt
        $copyItems = [];
        foreach ($items as $item) {
            $copyItems[] = [
                'id'           => $item['id'],
                'platform'     => $item['platform'],
                'campaign_name' => $item['campaign_name'],
                'copy_type'    => $item['copy_type'],
                'content'      => $item['content'],
                'char_limit'   => $item['char_limit'],
                'pin_position' => $item['pin_position'],
            ];
        }

        $replacements = [
            '{{PROJECT_NAME}}'       => $project['display_name'] ?? $project['name'],
            '{{WEBSITE}}'            => $project['website_url'] ?? '',
            '{{TARGET_AUDIENCE}}'    => $strategy['target_audience'] ?? 'General audience',
            '{{VALUE_PROPOSITION}}'  => $strategy['value_proposition'] ?? '',
            '{{TARGET_MARKET}}'      => $market,
            '{{LOCALE_INSTRUCTION}}' => $localeInstruction,
            '{{PLATFORM_POLICIES}}'  => $policies,
            '{{COPY_ITEMS}}'         => json_encode($copyItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Load platform policy docs for the given platforms.
     */
    private function loadPolicies(array $platforms): string
    {
        $sections = [];

        if (in_array('google', $platforms)) {
            $path = $this->policiesDir . '/google-ads-content-policy.md';
            if (file_exists($path)) {
                $sections[] = "### Google Ads Policies\n\n" . file_get_contents($path);
            }
        }

        if (in_array('meta', $platforms)) {
            $path = $this->policiesDir . '/meta-advertising-standards.md';
            if (file_exists($path)) {
                $sections[] = "### Meta Advertising Standards\n\n" . file_get_contents($path);
            }
        }

        if (empty($sections)) {
            return 'No platform policy documents available. Use general advertising best practices.';
        }

        return implode("\n\n---\n\n", $sections);
    }

    /**
     * Call Claude CLI with the prompt.
     */
    private function callClaude(string $prompt): ?string
    {
        $escapedPrompt = escapeshellarg($prompt);
        $cmd = "{$this->claudeBin} -p {$escapedPrompt} --model opus --output-format text";

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            echo "  Error: failed to start Claude CLI\n";
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);
            $out = stream_get_contents($pipes[1]);
            if ($out) $stdout .= $out;
            if (!$status['running']) break;
            if ((time() - $startTime) > $this->timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                echo "  Error: Claude CLI timed out after {$this->timeout}s\n";
                return null;
            }
            usleep(100_000);
        }

        $out = stream_get_contents($pipes[1]);
        if ($out) $stdout .= $out;

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            echo "  Error: Claude CLI exited with code {$exitCode}\n";
            if ($stderr) echo "  stderr: {$stderr}\n";
            return null;
        }

        return trim($stdout);
    }

    /**
     * Parse the LLM JSON response.
     */
    private function parseResponse(string $response): ?array
    {
        // Strip markdown fencing if present
        $response = preg_replace('/^```json?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/', '', $response);
        $response = trim($response);

        $result = json_decode($response, true);

        if (!is_array($result) || !isset($result['items'])) {
            echo "  Error: could not parse Claude response as JSON\n";
            echo "  Response preview: " . substr($response, 0, 200) . "\n";
            return null;
        }

        return $result;
    }
}
