<?php

namespace AdManager\Dashboard;

use AdManager\DB;

/**
 * Verifies conversion tracking setup by running Playwright against the project's website.
 * Checks for GA4 tags, Meta Pixel, GTM containers, and conversion events firing on trigger URLs.
 */
class ConversionVerifier
{
    private string $scriptPath;
    private string $nodeBin;

    public function __construct()
    {
        $this->scriptPath = dirname(__DIR__, 2) . '/bin/verify-conversions.js';
        $this->nodeBin = trim(shell_exec('which node') ?: 'node');
    }

    /**
     * Verify conversion tracking for a project.
     * Runs Playwright, then updates conversion_actions table with verification results.
     *
     * @return array Full verification report
     */
    public function verify(int $projectId): array
    {
        $db = DB::get();

        // Get project URL
        $proj = $db->prepare('SELECT * FROM projects WHERE id = ?');
        $proj->execute([$projectId]);
        $project = $proj->fetch();
        if (!$project || empty($project['website_url'])) {
            return ['ok' => false, 'error' => 'Project has no website URL'];
        }

        $url = $project['website_url'];

        // Get planned conversion actions to check trigger URLs
        $actions = $db->prepare('SELECT * FROM conversion_actions WHERE project_id = ? ORDER BY is_primary DESC');
        $actions->execute([$projectId]);
        $allActions = $actions->fetchAll();

        // Run Playwright verification on main URL
        $report = $this->runPlaywright($url);
        if (isset($report['error'])) {
            return ['ok' => false, 'error' => $report['error']];
        }

        // Also verify each trigger URL if configured
        foreach ($allActions as $action) {
            if ($action['trigger_type'] === 'url_match' && $action['trigger_value']) {
                $triggerReport = $this->runPlaywright($url, $action['trigger_value']);
                $report['trigger_checks'][$action['id']] = [
                    'action_name' => $action['name'],
                    'trigger_url' => $action['trigger_value'],
                    'events' => $triggerReport['trigger_check']['events_found'] ?? [],
                    'error' => $triggerReport['trigger_check']['error'] ?? null,
                ];
            }
        }

        // Update each conversion action's verification status
        foreach ($allActions as $action) {
            $verified = $this->isActionVerified($action, $report);
            $note = $this->buildVerificationNote($action, $report);
            $newStatus = $verified ? 'verified' : ($action['status'] === 'created' ? 'created' : $action['status']);

            $db->prepare(
                "UPDATE conversion_actions SET status = ?, verification_note = ?, verified_at = datetime('now'), updated_at = datetime('now') WHERE id = ?"
            )->execute([$newStatus, $note, $action['id']]);
        }

        // Log to changelog
        $verifiedCount = 0;
        foreach ($allActions as $a) {
            if ($this->isActionVerified($a, $report)) $verifiedCount++;
        }

        Changelog::log($projectId, 'strategy', 'verified',
            "Conversion verification: {$verifiedCount}/" . count($allActions) . " actions verified on {$url}",
            ['report' => $report, 'verified' => $verifiedCount, 'total' => count($allActions)],
            null, null, 'system');

        $report['ok'] = true;
        $report['verified_count'] = $verifiedCount;
        $report['total_actions'] = count($allActions);
        return $report;
    }

    /**
     * Run the Playwright verification script.
     */
    private function runPlaywright(string $url, ?string $triggerUrl = null): array
    {
        $cmd = sprintf('%s %s --url %s',
            escapeshellarg($this->nodeBin),
            escapeshellarg($this->scriptPath),
            escapeshellarg($url)
        );

        if ($triggerUrl) {
            $cmd .= ' --trigger-url ' . escapeshellarg($triggerUrl);
        }

        $output = shell_exec($cmd . ' 2>/dev/null');
        if (!$output) {
            return ['error' => 'Playwright script returned no output'];
        }

        $parsed = json_decode($output, true);
        if (!$parsed) {
            return ['error' => 'Failed to parse Playwright output: ' . substr($output, 0, 200)];
        }

        return $parsed;
    }

    /**
     * Determine if a specific conversion action is verified based on the Playwright report.
     */
    private function isActionVerified(array $action, array $report): bool
    {
        $platform = $action['platform'];
        $eventName = $action['event_name'];

        if ($platform === 'google' || $platform === 'ga4') {
            // Check if GA4 is loaded
            if (!($report['ga4']['found'] ?? false)) return false;
            // For Google Ads, also check the Ads tag
            if ($platform === 'google' && !($report['google_ads']['found'] ?? false)) {
                // GA4 + conversion linker is sufficient if conversions are imported from GA4
                if (!($report['conversion_linker'] ?? false)) return false;
            }
            return true; // GA4 tag present = baseline verified
        }

        if ($platform === 'meta') {
            return ($report['meta_pixel']['found'] ?? false);
        }

        return false;
    }

    /**
     * Build human-readable verification note for an action.
     */
    private function buildVerificationNote(array $action, array $report): string
    {
        $notes = [];
        $platform = $action['platform'];

        if ($platform === 'google' || $platform === 'ga4') {
            if ($report['ga4']['found'] ?? false) {
                $ids = implode(', ', $report['ga4']['measurement_ids'] ?? []);
                $notes[] = "GA4 found: {$ids}";
                $events = $report['ga4']['events'] ?? [];
                if (!empty($events)) $notes[] = "Events detected: " . implode(', ', $events);
            } else {
                $notes[] = "GA4 NOT found on page";
            }
            if ($report['gtm']['found'] ?? false) {
                $notes[] = "GTM: " . implode(', ', $report['gtm']['container_ids'] ?? []);
            }
            if ($report['google_ads']['found'] ?? false) {
                $notes[] = "Google Ads tag: " . implode(', ', $report['google_ads']['conversion_ids'] ?? []);
            }
            $notes[] = "Conversion linker: " . (($report['conversion_linker'] ?? false) ? 'YES' : 'NO');
        }

        if ($platform === 'meta') {
            if ($report['meta_pixel']['found'] ?? false) {
                $ids = implode(', ', $report['meta_pixel']['pixel_ids'] ?? []);
                $notes[] = "Meta Pixel found: {$ids}";
                $events = $report['meta_pixel']['events'] ?? [];
                if (!empty($events)) $notes[] = "Events: " . implode(', ', $events);
            } else {
                $notes[] = "Meta Pixel NOT found on page";
            }
        }

        // Check trigger URL results
        $triggerId = $action['id'];
        if (isset($report['trigger_checks'][$triggerId])) {
            $tc = $report['trigger_checks'][$triggerId];
            if (!empty($tc['events'])) {
                $evs = array_map(fn($e) => "{$e['type']}:{$e['event']}", $tc['events']);
                $notes[] = "Trigger URL {$tc['trigger_url']}: " . implode(', ', $evs);
            } elseif ($tc['error'] ?? false) {
                $notes[] = "Trigger URL error: {$tc['error']}";
            } else {
                $notes[] = "Trigger URL {$tc['trigger_url']}: no events detected";
            }
        }

        return implode("\n", $notes);
    }
}
