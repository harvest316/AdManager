#!/usr/bin/env php
<?php
/**
 * AdManager cron runner — executes due cron_jobs as registered in the DB.
 *
 * Called by the mmo-platform cron dispatcher (services/cron/runner.js).
 * Reads cron_jobs table, checks which are due (last_run_at + interval < now),
 * and executes each command handler sequentially.
 *
 * Usage:
 *   php bin/cron-runner.php              # run all due jobs
 *   php bin/cron-runner.php --force      # run all enabled jobs regardless of schedule
 *   php bin/cron-runner.php --list       # list registered jobs
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\DB;

DB::init();
$db = DB::get();

// ── Arg parsing ──────────────────────────────────────────────────────────────

$force = in_array('--force', $argv);
$listOnly = in_array('--list', $argv);

// ── List mode ────────────────────────────────────────────────────────────────

if ($listOnly) {
    $stmt = $db->query('SELECT * FROM cron_jobs ORDER BY id');
    $jobs = $stmt->fetchAll();

    if (empty($jobs)) {
        echo "No cron jobs registered.\n";
        exit(0);
    }

    printf("%-4s %-30s %-25s %-12s %-8s %s\n", 'ID', 'Name', 'Task Key', 'Schedule', 'Enabled', 'Last Run');
    echo str_repeat('-', 100) . "\n";

    foreach ($jobs as $j) {
        printf(
            "%-4d %-30s %-25s %-12s %-8s %s\n",
            $j['id'],
            mb_substr($j['name'], 0, 30),
            $j['task_key'],
            $j['interval_value'] . ' ' . $j['interval_unit'],
            $j['enabled'] ? 'yes' : 'no',
            $j['last_run_at'] ?? 'never'
        );
    }
    exit(0);
}

// ── Run due jobs ─────────────────────────────────────────────────────────────

$stmt = $db->query('SELECT * FROM cron_jobs WHERE enabled = 1 ORDER BY id');
$jobs = $stmt->fetchAll();

if (empty($jobs)) {
    echo "[cron] No enabled jobs.\n";
    exit(0);
}

$logStmt = $db->prepare(
    'INSERT INTO cron_job_logs (job_id, task_key, started_at, status)
     VALUES (?, ?, datetime(\'now\'), \'running\')'
);

$updateLogStmt = $db->prepare(
    'UPDATE cron_job_logs
     SET finished_at = datetime(\'now\'), status = ?, output = ?, error = ?, duration_ms = ?
     WHERE id = ?'
);

$updateJobStmt = $db->prepare(
    'UPDATE cron_jobs SET last_run_at = datetime(\'now\'), updated_at = datetime(\'now\') WHERE id = ?'
);

$ran = 0;

foreach ($jobs as $job) {
    // Check if due
    if (!$force && $job['last_run_at']) {
        $lastRun = new DateTimeImmutable($job['last_run_at']);
        $interval = match ($job['interval_unit']) {
            'minutes' => new DateInterval("PT{$job['interval_value']}M"),
            'hours'   => new DateInterval("PT{$job['interval_value']}H"),
            'days'    => new DateInterval("P{$job['interval_value']}D"),
            'weeks'   => new DateInterval('P' . ($job['interval_value'] * 7) . 'D'),
            default   => new DateInterval("P{$job['interval_value']}D"),
        };
        $nextDue = $lastRun->add($interval);

        if ($nextDue > new DateTimeImmutable()) {
            continue; // not due yet
        }
    }

    echo "[cron] Running: {$job['name']} ({$job['task_key']})\n";

    // Log start
    $logStmt->execute([$job['id'], $job['task_key']]);
    $logId = (int) $db->lastInsertId();
    $startTime = microtime(true);

    $timeout = $job['timeout_seconds'] ?? 480;
    $status = 'success';
    $output = '';
    $error = '';

    if ($job['handler_type'] === 'command') {
        $cmd = $job['handler_value'];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));

        if (!is_resource($process)) {
            $status = 'error';
            $error = "Failed to start process: {$cmd}";
        } else {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            while (true) {
                $procStatus = proc_get_status($process);
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                if ($out) $output .= $out;
                if ($err) $error .= $err;

                if (!$procStatus['running']) break;

                if ((microtime(true) - $startTime) > $timeout) {
                    proc_terminate($process);
                    $status = 'timeout';
                    $error .= "\nKilled after {$timeout}s timeout.";
                    break;
                }

                usleep(100_000);
            }

            // Drain remaining
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            if ($out) $output .= $out;
            if ($err) $error .= $err;

            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0 && $status !== 'timeout') {
                $status = 'error';
            }
        }
    } else {
        $status = 'error';
        $error = "Unsupported handler_type: {$job['handler_type']} (PHP cron runner only supports 'command')";
    }

    $durationMs = (int) ((microtime(true) - $startTime) * 1000);

    // Update log
    $updateLogStmt->execute([$status, substr($output, 0, 10000), substr($error, 0, 5000), $durationMs, $logId]);

    // Update last_run_at
    $updateJobStmt->execute([$job['id']]);

    $statusLabel = strtoupper($status);
    echo "[cron] {$job['task_key']}: {$statusLabel} ({$durationMs}ms)\n";
    $ran++;
}

echo "[cron] Done. Ran {$ran} job(s).\n";
