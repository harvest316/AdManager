#!/usr/bin/env php
<?php
/**
 * Start the creative review dashboard server.
 *
 * Usage:
 *   php bin/review-server.php [host] [port]
 *   php bin/review-server.php localhost 8080
 */

$host = $argv[1] ?? 'localhost';
$port = $argv[2] ?? '8080';

echo "AdManager Creative Review Dashboard\n";
echo "====================================\n";
echo "Server: http://{$host}:{$port}\n";
echo "Press Ctrl+C to stop.\n\n";

passthru("php -S {$host}:{$port} -t " . escapeshellarg(__DIR__ . '/../review/'));
