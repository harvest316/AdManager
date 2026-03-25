#!/usr/bin/env php
<?php
/**
 * YouTube video upload CLI.
 *
 * Usage:
 *   php bin/upload-youtube.php <video_file> --title "<title>" [--description "..."] [--privacy unlisted|private|public] [--tags "tag1,tag2"]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\Google\YouTube;

$videoFile = $argv[1] ?? null;

if (!$videoFile || str_starts_with($videoFile, '--')) {
    echo "Usage:\n";
    echo "  php bin/upload-youtube.php <video_file> --title \"<title>\" [--description \"...\"] [--privacy unlisted|private|public] [--tags \"tag1,tag2\"]\n";
    exit(1);
}

// Parse --key value pairs
$args = [];
for ($i = 2; $i < count($argv); $i++) {
    if (str_starts_with($argv[$i], '--')) {
        $key = substr($argv[$i], 2);
        $val = $argv[$i + 1] ?? '';
        $args[$key] = $val;
        $i++;
    }
}

$title = $args['title'] ?? null;
if (!$title) {
    echo "Error: --title is required.\n";
    echo "Usage: php bin/upload-youtube.php <video_file> --title \"<title>\"\n";
    exit(1);
}

$description = $args['description'] ?? '';
$privacy     = $args['privacy'] ?? 'unlisted';
$tags        = [];

if (isset($args['tags']) && $args['tags'] !== '') {
    $tags = array_map('trim', explode(',', $args['tags']));
}

if (!in_array($privacy, ['unlisted', 'private', 'public'], true)) {
    echo "Error: --privacy must be 'unlisted', 'private', or 'public'.\n";
    exit(1);
}

try {
    $yt = new YouTube();

    echo "Uploading {$videoFile} to YouTube...\n";
    echo "  Title:   {$title}\n";
    echo "  Privacy: {$privacy}\n";
    if ($tags) {
        echo "  Tags:    " . implode(', ', $tags) . "\n";
    }
    echo "\n";

    $videoId = $yt->upload($videoFile, $title, $description, $privacy, $tags);
    $url     = $yt->getVideoUrl($videoId);

    echo "Upload complete!\n";
    echo "Video ID: {$videoId}\n";
    echo "URL:      {$url}\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
