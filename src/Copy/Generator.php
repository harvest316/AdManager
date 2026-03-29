<?php

namespace AdManager\Copy;

use AdManager\DB;
use AdManager\Locale;

/**
 * Ad copy generation service.
 *
 * Uses COPY.md prompt template with Claude Opus to generate fresh ad copy
 * variants for a project. Used for:
 * - Initial copy generation (alternative to parsing from strategy)
 * - Ongoing split testing (replace underperforming headlines)
 * - A/B variant generation for specific campaigns
 */
class Generator
{
    private string $claudeBin;
    private string $promptsDir;
    private int $timeout = 180;

    public function __construct()
    {
        $this->claudeBin = getenv('CLAUDE_BIN') ?: '/home/jason/.local/bin/claude';
        $this->promptsDir = dirname(__DIR__, 2) . '/prompts';
    }

    /**
     * Generate ad copy for a project/campaign.
     *
     * @param array  $project      Project row
     * @param string $platform     'google' | 'meta'
     * @param string $campaignType 'search' | 'display' | 'pmax' | 'traffic' | 'conversions'
     * @param array  $options      Additional context: target_audience, value_proposition, tone, key_messages, context
     * @return array Parsed copy items ready for Store::bulkInsert()
     */
    public function generate(array $project, string $platform, string $campaignType, array $options = []): array
    {
        $prompt = $this->buildPrompt($project, $platform, $campaignType, $options);
        $response = $this->callClaude($prompt);

        if ($response === null) {
            return [];
        }

        return $this->parseResponse($response, $platform, $campaignType);
    }

    /**
     * Generate replacement headlines for underperforming ones.
     *
     * @param array  $project       Project row
     * @param array  $weakHeadlines Headlines to replace (with performance data)
     * @param array  $strongHeadlines Top-performing headlines (for tone matching)
     * @param string $campaignName  Campaign name for context
     * @return array Parsed headline items
     */
    public function generateReplacements(
        array $project,
        array $weakHeadlines,
        array $strongHeadlines,
        string $campaignName,
        array $options = []
    ): array {
        $weakList = implode("\n", array_map(
            fn($h) => "- \"{$h['content']}\" (CTR: " . ($h['ctr'] ?? 'unknown') . ", label: " . ($h['label'] ?? 'Low') . ")",
            $weakHeadlines
        ));
        $strongList = implode("\n", array_map(
            fn($h) => "- \"{$h['content']}\" (CTR: " . ($h['ctr'] ?? 'unknown') . ", label: " . ($h['label'] ?? 'Best') . ")",
            $strongHeadlines
        ));

        $count = count($weakHeadlines);
        $productName = $project['display_name'] ?? $project['name'];
        $market = $options['market'] ?? 'all';
        $localeInstruction = Locale::promptInstruction($market);

        $prompt = <<<PROMPT
You are an expert advertising copywriter. Generate {$count} replacement Google Ads RSA headlines.

**Product:** {$productName} ({$project['website_url']})
**Campaign:** {$campaignName}
**Market:** {$market}
{$localeInstruction}

## Headlines to replace (underperforming):
{$weakList}

## Top-performing headlines (match this tone and approach):
{$strongList}

## Rules
- Each headline: max 30 characters (including spaces)
- Each must stand alone (RSA combines randomly)
- No exclamation marks
- No ALL CAPS (except acronyms like AI, SEO)
- Focus on different angles than the weak headlines
- Match the tone and energy of the strong headlines

## Output
Return ONLY a numbered list of headlines, one per line:
1. New headline here
2. Another headline
PROMPT;

        $response = $this->callClaude($prompt);
        if ($response === null) return [];

        return $this->parseHeadlineList($response);
    }

    /**
     * Build the COPY.md prompt with project context.
     */
    private function buildPrompt(array $project, string $platform, string $campaignType, array $options): string
    {
        $template = file_get_contents($this->promptsDir . '/COPY.md');

        $productName = $project['display_name'] ?? $project['name'];
        $market = $options['market'] ?? 'all';

        $replacements = [
            '{{PROJECT_NAME}}'      => $productName,
            '{{WEBSITE}}'           => $project['website_url'] ?? '',
            '{{PLATFORM}}'          => $platform,
            '{{CAMPAIGN_TYPE}}'     => $campaignType,
            '{{TARGET_AUDIENCE}}'   => $options['target_audience'] ?? 'General audience',
            '{{VALUE_PROPOSITION}}' => $options['value_proposition'] ?? '',
            '{{TONE}}'              => $options['tone'] ?? 'Professional, benefit-focused',
            '{{KEY_MESSAGES}}'      => $options['key_messages'] ?? '',
            '{{CONTEXT}}'           => $options['context'] ?? Locale::promptInstruction($market),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Parse COPY.md format output into structured copy items.
     */
    private function parseResponse(string $response, string $platform, string $campaignType): array
    {
        $items = [];

        if ($platform === 'google') {
            $items = array_merge($items, $this->parseGoogleCopyResponse($response, $campaignType));
        } elseif ($platform === 'meta') {
            $items = array_merge($items, $this->parseMetaCopyResponse($response));
        }

        return $items;
    }

    /**
     * Parse Google ad copy from COPY.md output.
     */
    private function parseGoogleCopyResponse(string $response, string $campaignType): array
    {
        $items = [];

        // Parse headlines section
        if (preg_match('/## Headlines\s*\n((?:\d+\..*\n?)+)/i', $response, $m)) {
            $lines = array_filter(explode("\n", trim($m[1])));
            foreach ($lines as $line) {
                if (preg_match('/^\d+\.\s*\[?(.+?)\]?\s*\(\d+ chars?\)\s*(?:\[PIN:\s*position\s*(\d)\])?/i', $line, $lm)) {
                    $items[] = [
                        'platform'      => 'google',
                        'campaign_name' => null,
                        'ad_group_name' => null,
                        'copy_type'     => 'headline',
                        'content'       => trim($lm[1]),
                        'char_limit'    => 30,
                        'pin_position'  => isset($lm[2]) ? (int) $lm[2] : null,
                    ];
                }
            }
        }

        // Parse descriptions section
        if (preg_match('/## Descriptions\s*\n((?:\d+\..*\n?)+)/i', $response, $m)) {
            $lines = array_filter(explode("\n", trim($m[1])));
            foreach ($lines as $line) {
                if (preg_match('/^\d+\.\s*\[?(.+?)\]?\s*\(\d+ chars?\)/i', $line, $lm)) {
                    $items[] = [
                        'platform'      => 'google',
                        'campaign_name' => null,
                        'ad_group_name' => null,
                        'copy_type'     => 'description',
                        'content'       => trim($lm[1]),
                        'char_limit'    => $campaignType === 'display' ? 90 : 90,
                        'pin_position'  => null,
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * Parse Meta ad copy from COPY.md output.
     */
    private function parseMetaCopyResponse(string $response): array
    {
        $items = [];
        $sections = [
            'primary text' => ['type' => 'primary_text', 'limit' => 2000],
            'headline'     => ['type' => 'headline', 'limit' => 40],
            'description'  => ['type' => 'description', 'limit' => 30],
        ];

        foreach ($sections as $label => $config) {
            $pattern = '/##\s*' . preg_quote($label, '/') . 's?\s*(?:options?)?\s*\n((?:\d+\..*\n?)+)/i';
            if (preg_match($pattern, $response, $m)) {
                $lines = array_filter(explode("\n", trim($m[1])));
                foreach ($lines as $line) {
                    if (preg_match('/^\d+\.\s*\[?(.+?)\]?\s*(?:\(\d+ chars?\))?/i', $line, $lm)) {
                        $items[] = [
                            'platform'      => 'meta',
                            'campaign_name' => null,
                            'ad_group_name' => null,
                            'copy_type'     => $config['type'],
                            'content'       => trim($lm[1]),
                            'char_limit'    => $config['limit'],
                            'pin_position'  => null,
                        ];
                    }
                }
            }
        }

        return $items;
    }

    /**
     * Parse a simple numbered headline list.
     */
    private function parseHeadlineList(string $response): array
    {
        $items = [];
        $lines = explode("\n", trim($response));
        foreach ($lines as $line) {
            if (preg_match('/^\d+\.\s*(.+)/', $line, $m)) {
                $content = trim($m[1], '"\'` ');
                if ($content !== '' && mb_strlen($content) <= 30) {
                    $items[] = [
                        'platform'      => 'google',
                        'campaign_name' => null,
                        'ad_group_name' => null,
                        'copy_type'     => 'headline',
                        'content'       => $content,
                        'char_limit'    => 30,
                        'pin_position'  => null,
                    ];
                }
            }
        }
        return $items;
    }

    /**
     * Shell out to Claude CLI.
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
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim($stdout) ?: null;
    }
}
