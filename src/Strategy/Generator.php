<?php

namespace AdManager\Strategy;

use AdManager\DB;
use RuntimeException;

class Generator
{
    private Store $store;

    public function __construct()
    {
        $this->store = new Store();
    }

    /**
     * Generate a strategy by crawling the site and shelling out to Claude CLI.
     *
     * @return int The strategy ID
     */
    public function generate(int $projectId, array $context = []): int
    {
        $db = DB::get();

        // Load project data
        $stmt = $db->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            throw new RuntimeException("Project #{$projectId} not found.");
        }

        // Auto-crawl the site if we have a URL and no context override
        if (!empty($project['website_url']) && empty($context['site_summary'])) {
            echo "  Crawling {$project['website_url']}...\n";
            $context['site_summary'] = $this->crawlSite($project['website_url']);
        }

        $prompt = $this->buildPrompt($project, $context);
        $strategy = $this->runClaude($prompt);

        $name = sprintf('%s — Full Strategy', $project['display_name'] ?: $project['name']);

        // Save to database
        $id = $this->store->save($projectId, $name, 'all', 'full', $strategy);

        // Save to filesystem
        $this->saveToFile($project['name'], $strategy);

        return $id;
    }

    /**
     * Generate from just a domain — auto-creates project if needed.
     */
    public function generateFromDomain(string $domain): int
    {
        $db = DB::get();

        // Normalise domain
        $domain = preg_replace('#^https?://#', '', rtrim($domain, '/'));
        $url = "https://{$domain}";
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(explode('.', $domain)[0]));

        // Find or create project
        $stmt = $db->prepare('SELECT * FROM projects WHERE website_url = ? OR name = ?');
        $stmt->execute([$url, $slug]);
        $project = $stmt->fetch();

        if (!$project) {
            echo "  Creating project '{$slug}' for {$url}...\n";
            $db->prepare('INSERT INTO projects (name, website_url) VALUES (?, ?)')
               ->execute([$slug, $url]);
            $projectId = (int) $db->lastInsertId();
        } else {
            $projectId = (int) $project['id'];
        }

        return $this->generate($projectId);
    }

    /**
     * Build the prompt from template + project data.
     */
    public function buildPrompt(array $project, array $context): string
    {
        $templatePath = dirname(__DIR__, 2) . '/prompts/STRATEGY.md';

        if (!file_exists($templatePath)) {
            throw new RuntimeException("Prompt template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);
        $db = DB::get();

        // Get all budgets for this project
        $budgetStmt = $db->prepare(
            'SELECT platform, daily_budget_aud FROM budgets WHERE project_id = ?'
        );
        $budgetStmt->execute([$project['id']]);
        $budgets = $budgetStmt->fetchAll();

        $monthlyTotal = 0;
        $budgetLines = [];
        foreach ($budgets as $b) {
            $monthly = $b['daily_budget_aud'] * 30;
            $monthlyTotal += $monthly;
            $budgetLines[] = ucfirst($b['platform']) . ': $' . number_format($monthly, 0) . '/month ($' . number_format($b['daily_budget_aud'], 2) . '/day)';
        }
        $budgetText = $monthlyTotal > 0
            ? '$' . number_format($monthlyTotal, 0) . '/month total (' . implode(', ', $budgetLines) . ')'
            : 'Not set';

        // Get goals
        $goalsStmt = $db->prepare(
            'SELECT metric, target_value, platform FROM goals WHERE project_id = ?'
        );
        $goalsStmt->execute([$project['id']]);
        $goals = $goalsStmt->fetchAll();

        $goalsText = '';
        if (empty($goals)) {
            $goalsText = 'No specific goals defined yet.';
        } else {
            foreach ($goals as $g) {
                $plat = $g['platform'] ? " ({$g['platform']})" : '';
                $goalsText .= "- {$g['metric']}: {$g['target_value']}{$plat}\n";
            }
        }

        // Build context string — site_summary goes first if present
        $contextText = '';
        if (!empty($context['site_summary'])) {
            $contextText .= "## Site Analysis (auto-crawled)\n\n{$context['site_summary']}\n\n";
        }
        $extraContext = array_filter($context, fn($k) => !in_array($k, [
            'site_summary', 'account_maturity', 'pricing_model',
            'primary_conversion', 'secondary_conversions', 'target_markets',
        ]), ARRAY_FILTER_USE_KEY);
        if (!empty($extraContext)) {
            $contextText .= "## Additional Context\n";
            foreach ($extraContext as $key => $value) {
                if (is_int($key)) {
                    $contextText .= "- {$value}\n";
                } else {
                    $contextText .= "- **{$key}:** {$value}\n";
                }
            }
        }

        // Replace placeholders — new template vars
        $replacements = [
            '{{PROJECT_NAME}}'           => $project['display_name'] ?: $project['name'],
            '{{WEBSITE}}'                => $project['website_url'] ?? 'Not specified',
            '{{BUDGET}}'                 => $budgetText,
            '{{GOALS}}'                  => trim($goalsText),
            '{{ACCOUNT_MATURITY}}'       => $context['account_maturity'] ?? 'new account with zero history',
            '{{PRICING_MODEL}}'          => $context['pricing_model'] ?? 'Not specified',
            '{{PRIMARY_PERSONA}}'        => $context['primary_persona'] ?? 'Not specified — infer from site analysis',
            '{{PRIMARY_CONVERSION}}'     => $context['primary_conversion'] ?? 'purchase',
            '{{SECONDARY_CONVERSIONS}}'  => $context['secondary_conversions'] ?? 'sign_up, add_to_cart',
            '{{TARGET_MARKETS}}'         => $context['target_markets'] ?? 'Australia (English)',
            '{{DATE}}'                   => date('Y-m-d'),
            '{{CONTEXT}}'               => $contextText,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Crawl a site's sitemap and key pages to understand the business.
     */
    private function crawlSite(string $url): string
    {
        $domain = parse_url($url, PHP_URL_HOST) ?: $url;
        $baseUrl = rtrim($url, '/');
        $summary = [];

        // 1. Try sitemap.xml
        $sitemapUrls = [];
        $sitemapXml = $this->httpGet("{$baseUrl}/sitemap.xml");
        if ($sitemapXml && stripos($sitemapXml, '<urlset') !== false) {
            preg_match_all('#<loc>([^<]+)</loc>#i', $sitemapXml, $matches);
            $sitemapUrls = $matches[1] ?? [];
            $summary[] = "**Sitemap:** " . count($sitemapUrls) . " URLs found";
            // List up to 30 URLs for context
            $displayUrls = array_slice($sitemapUrls, 0, 30);
            $summary[] = "**Pages:**\n" . implode("\n", array_map(fn($u) => "- {$u}", $displayUrls));
            if (count($sitemapUrls) > 30) {
                $summary[] = "... and " . (count($sitemapUrls) - 30) . " more";
            }
        }

        // 2. Crawl homepage
        $homepage = $this->httpGet($baseUrl);
        if ($homepage) {
            // Extract title
            if (preg_match('#<title[^>]*>([^<]+)</title>#i', $homepage, $m)) {
                $summary[] = "**Title:** " . trim($m[1]);
            }
            // Extract meta description
            if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']#i', $homepage, $m)) {
                $summary[] = "**Meta Description:** " . trim($m[1]);
            }
            // Extract headings
            preg_match_all('#<h[1-3][^>]*>([^<]+)</h[1-3]>#i', $homepage, $headings);
            if (!empty($headings[1])) {
                $hdgs = array_slice(array_map('trim', $headings[1]), 0, 10);
                $summary[] = "**Key Headings:** " . implode(' | ', $hdgs);
            }
            // Extract visible text (rough — strip tags, collapse whitespace)
            $text = strip_tags($homepage);
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim(mb_substr($text, 0, 2000));
            if ($text) {
                $summary[] = "**Homepage Text (first 2000 chars):**\n{$text}";
            }
        }

        // 3. Crawl a few key pages if sitemap found them
        $keyPages = [];
        foreach ($sitemapUrls as $sUrl) {
            $path = parse_url($sUrl, PHP_URL_PATH) ?? '';
            if (preg_match('#/(pricing|about|features|plans|products|services|how-it-works)#i', $path)) {
                $keyPages[] = $sUrl;
            }
            if (count($keyPages) >= 3) break;
        }

        foreach ($keyPages as $pageUrl) {
            $pageHtml = $this->httpGet($pageUrl);
            if ($pageHtml) {
                $path = parse_url($pageUrl, PHP_URL_PATH);
                if (preg_match('#<title[^>]*>([^<]+)</title>#i', $pageHtml, $m)) {
                    $summary[] = "\n**Page {$path} title:** " . trim($m[1]);
                }
                $text = strip_tags($pageHtml);
                $text = preg_replace('/\s+/', ' ', $text);
                $text = trim(mb_substr($text, 0, 1000));
                if ($text) {
                    $summary[] = "**{$path} text (first 1000 chars):**\n{$text}";
                }
            }
        }

        return implode("\n\n", $summary) ?: "Could not crawl {$url} — site may be down or blocking bots.";
    }

    /**
     * Simple HTTP GET with curl. Returns body or empty string on failure.
     */
    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'AdManager/1.0 (strategy-crawler)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($code >= 200 && $code < 400) ? ($body ?: '') : '';
    }

    /**
     * Execute Claude CLI and return stdout.
     *
     * @throws RuntimeException on non-zero exit or timeout
     */
    private function runClaude(string $prompt): string
    {
        $escapedPrompt = escapeshellarg($prompt);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $claudeBin = getenv('CLAUDE_BIN') ?: '/home/jason/.local/bin/claude';
        $cmd = "{$claudeBin} -p {$escapedPrompt} --output-format text --model opus --verbose";
        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start Claude CLI process.');
        }

        fclose($pipes[0]); // close stdin

        // Set non-blocking and read with timeout
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();
        $timeout = 300; // 5 minutes — Opus with thinking needs longer

        while (true) {
            $status = proc_get_status($process);

            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            if ($out) $stdout .= $out;
            if ($err) $stderr .= $err;

            if (!$status['running']) {
                break;
            }

            if ((time() - $startTime) > $timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException("Claude CLI timed out after {$timeout}s.");
            }

            usleep(100_000); // 100ms
        }

        // Final read
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        if ($out) $stdout .= $out;
        if ($err) $stderr .= $err;

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "Claude CLI exited with code {$exitCode}. Stderr: {$stderr}"
            );
        }

        $stdout = trim($stdout);
        if (empty($stdout)) {
            throw new RuntimeException('Claude CLI returned empty output.');
        }

        return $stdout;
    }

    /**
     * Save strategy markdown to the strategies/ directory.
     */
    private function saveToFile(string $projectName, string $strategy): string
    {
        $dir = dirname(__DIR__, 2) . '/strategies';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($projectName));
        $filename = sprintf('%s-%s.md', $slug, date('Y-m-d-His'));
        $path = "{$dir}/{$filename}";

        file_put_contents($path, $strategy);

        return $path;
    }
}
