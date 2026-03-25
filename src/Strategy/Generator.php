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
     * Generate a strategy by shelling out to Claude CLI.
     *
     * @return int The strategy ID
     */
    public function generate(int $projectId, string $platform, string $campaignType, array $context = []): int
    {
        $db = DB::get();

        // Load project data
        $stmt = $db->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            throw new RuntimeException("Project #{$projectId} not found.");
        }

        $prompt = $this->buildPrompt($project, $platform, $campaignType, $context);
        $strategy = $this->runClaude($prompt);

        // Generate a descriptive name
        $name = sprintf(
            '%s — %s %s',
            $project['display_name'] ?: $project['name'],
            ucfirst($platform),
            ucfirst($campaignType)
        );

        // Save to database
        $id = $this->store->save($projectId, $name, $platform, $campaignType, $strategy);

        // Save to filesystem
        $this->saveToFile($project['name'], $strategy);

        return $id;
    }

    /**
     * Build the prompt from template + project data.
     */
    public function buildPrompt(array $project, string $platform, string $campaignType, array $context): string
    {
        $templatePath = dirname(__DIR__, 2) . '/prompts/STRATEGY.md';

        if (!file_exists($templatePath)) {
            throw new RuntimeException("Prompt template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);
        $db = DB::get();

        // Get budget
        $budgetStmt = $db->prepare(
            'SELECT daily_budget_aud FROM budgets WHERE project_id = ? AND platform = ?'
        );
        $budgetStmt->execute([$project['id'], $platform]);
        $budgetRow = $budgetStmt->fetch();
        $budget = $budgetRow ? '$' . number_format($budgetRow['daily_budget_aud'], 2) : 'Not set';

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

        // Build context string
        $contextText = '';
        if (!empty($context)) {
            $contextText = "## Additional Context\n";
            foreach ($context as $key => $value) {
                if (is_int($key)) {
                    $contextText .= "- {$value}\n";
                } else {
                    $contextText .= "- **{$key}:** {$value}\n";
                }
            }
        }

        // Replace placeholders
        $replacements = [
            '{{PROJECT_NAME}}'  => $project['display_name'] ?: $project['name'],
            '{{WEBSITE}}'       => $project['website_url'] ?? 'Not specified',
            '{{PLATFORM}}'      => ucfirst($platform),
            '{{CAMPAIGN_TYPE}}' => ucfirst($campaignType),
            '{{BUDGET}}'        => $budget,
            '{{GOALS}}'         => trim($goalsText),
            '{{CONTEXT}}'       => $contextText,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
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

        $cmd = "claude -p {$escapedPrompt} --output-format text";
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
        $timeout = 120; // seconds

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
