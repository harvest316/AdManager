<?php

namespace AdManager\Dashboard;

use AdManager\DB;
use RuntimeException;

/**
 * Background sync job management. Launches bin/sync-performance.php via shell_exec.
 */
class SyncRunner
{
    private string $jobDir;

    public function __construct()
    {
        $this->jobDir = dirname(__DIR__, 2) . '/tmp/sync-jobs';
        if (!is_dir($this->jobDir)) {
            mkdir($this->jobDir, 0755, true);
        }
    }

    /**
     * Start a background sync for a project.
     *
     * @return int The sync job ID
     */
    public function start(int $projectId, string $platform = 'all', int $days = 7): int
    {
        if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
            throw new RuntimeException('Sync not available on this host (shell_exec disabled).');
        }

        $db = DB::get();

        // Prevent concurrent syncs
        $running = $db->prepare(
            "SELECT id FROM sync_jobs WHERE project_id = ? AND status IN ('pending', 'running')"
        );
        $running->execute([$projectId]);
        if ($running->fetch()) {
            throw new RuntimeException('Sync already in progress for this project.');
        }

        // Resolve project name
        $proj = $db->prepare('SELECT name FROM projects WHERE id = ?');
        $proj->execute([$projectId]);
        $projectName = $proj->fetchColumn();
        if (!$projectName) {
            throw new RuntimeException("Project #{$projectId} not found.");
        }

        // Create job record
        $stmt = $db->prepare(
            "INSERT INTO sync_jobs (project_id, platform, days, status, started_at)
             VALUES (?, ?, ?, 'running', datetime('now'))"
        );
        $stmt->execute([$projectId, $platform, $days]);
        $jobId = (int) $db->lastInsertId();

        // Launch background process
        $binPath = dirname(__DIR__, 2) . '/bin/sync-performance.php';
        $outFile = "{$this->jobDir}/{$jobId}.log";
        $pidFile = "{$this->jobDir}/{$jobId}.pid";

        $cmd = sprintf(
            'php %s --project %s --platform %s --days %d > %s 2>&1 & echo $!',
            escapeshellarg($binPath),
            escapeshellarg($projectName),
            escapeshellarg($platform),
            $days,
            escapeshellarg($outFile)
        );

        $pid = trim(shell_exec($cmd));
        file_put_contents($pidFile, $pid);

        return $jobId;
    }

    /**
     * Poll a sync job's status.
     */
    public function poll(int $jobId): array
    {
        $db = DB::get();
        $stmt = $db->prepare('SELECT * FROM sync_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if (!$job) {
            throw new RuntimeException('Job not found.');
        }

        if ($job['status'] === 'complete' || $job['status'] === 'failed') {
            return [
                'status' => $job['status'],
                'output' => $job['output'],
                'duration' => $job['completed_at'] && $job['started_at']
                    ? strtotime($job['completed_at']) - strtotime($job['started_at'])
                    : null,
            ];
        }

        // Check if process is still running
        $pidFile = "{$this->jobDir}/{$jobId}.pid";
        $outFile = "{$this->jobDir}/{$jobId}.log";
        $pid = file_exists($pidFile) ? trim(file_get_contents($pidFile)) : null;
        $isRunning = $pid && file_exists("/proc/{$pid}");
        $output = file_exists($outFile) ? file_get_contents($outFile) : '';

        if (!$isRunning) {
            $status = str_contains($output, 'Error:') || str_contains($output, 'Fatal') ? 'failed' : 'complete';
            $db->prepare(
                "UPDATE sync_jobs SET status = ?, output = ?, completed_at = datetime('now') WHERE id = ?"
            )->execute([$status, $output, $jobId]);

            // Log to changelog
            Changelog::log(
                (int) $job['project_id'],
                'system',
                'synced',
                "Performance sync {$status} ({$job['platform']}, {$job['days']} days)",
                ['job_id' => $jobId, 'platform' => $job['platform'], 'days' => (int) $job['days'], 'status' => $status]
            );

            @unlink($pidFile);

            return ['status' => $status, 'output' => $output, 'duration' => null];
        }

        return ['status' => 'running', 'output' => $output, 'duration' => null];
    }
}
