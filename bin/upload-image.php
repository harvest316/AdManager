#!/usr/bin/env php
<?php
/**
 * Upload an image to a platform's asset library.
 *
 * Usage:
 *   php bin/upload-image.php <file_path> [--name "My Image"] [--platform google|meta]
 *
 * Defaults to Google if --platform is not specified.
 * Prints the asset resource name (Google) or image hash (Meta) on success.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\Google\Client;
use AdManager\Google\AssetUpload;
use AdManager\Meta\Assets as MetaAssets;

// ── Arg parsing ──────────────────────────────────────────────────────────────

$filePath = null;
$named    = [];

for ($i = 1; $i < count($argv); $i++) {
    if (str_starts_with($argv[$i], '--')) {
        $key = substr($argv[$i], 2);
        if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
            $named[$key] = $argv[$i + 1];
            $i++;
        } else {
            $named[$key] = true;
        }
    } elseif ($filePath === null) {
        $filePath = $argv[$i];
    }
}

$platform = strtolower($named['platform'] ?? 'google');
$name     = $named['name'] ?? '';

if (!$filePath) {
    echo "Usage:\n";
    echo "  php bin/upload-image.php <file_path> [--name \"My Image\"] [--platform google|meta]\n";
    echo "\nDefaults to Google. Prints asset resource name (Google) or image hash (Meta).\n";
    exit(1);
}

if (!file_exists($filePath)) {
    echo "Error: file not found: {$filePath}\n";
    exit(1);
}

if (!in_array($platform, ['google', 'meta'])) {
    echo "Error: platform must be 'google' or 'meta'.\n";
    exit(1);
}

$fileSize = filesize($filePath);
$fileSizeKb = round($fileSize / 1024, 1);

echo "Uploading: {$filePath} ({$fileSizeKb} KB)\n";
echo "Platform:  {$platform}\n";
if ($name) echo "Name:      {$name}\n";
echo "\n";

try {
    if ($platform === 'google') {
        Client::boot();

        $uploader = new AssetUpload();
        $assetRn  = $uploader->uploadImage($filePath, $name);

        echo "Upload successful.\n";
        echo "Asset resource name: {$assetRn}\n";
    } else {
        $metaAssets = new MetaAssets();
        $result     = $metaAssets->uploadImage($filePath);

        echo "Upload successful.\n";
        echo "Image hash: {$result['hash']}\n";
        if (!empty($result['url'])) {
            echo "URL:        {$result['url']}\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
