<?php
/**
 * Serves asset files (images/videos) from the assets directory.
 * The PHP built-in server won't serve files outside docroot, so this
 * proxies them with proper MIME types and security checks.
 */

$path = $_GET['path'] ?? '';
$basePath = realpath(__DIR__ . '/../assets/');
$filePath = realpath(__DIR__ . '/../' . $path);

// Security: ensure file is within assets directory
if (!$filePath || !$basePath || !str_starts_with($filePath, $basePath)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

if (!is_file($filePath)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$mime = match (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
    'png'        => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'webp'       => 'image/webp',
    'gif'        => 'image/gif',
    'svg'        => 'image/svg+xml',
    'mp4'        => 'video/mp4',
    'webm'       => 'video/webm',
    'mov'        => 'video/quicktime',
    default      => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: public, max-age=3600');
readfile($filePath);
