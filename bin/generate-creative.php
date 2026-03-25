#!/usr/bin/env php
<?php
/**
 * Creative generation CLI — images, videos, and overlays.
 *
 * Usage:
 *   php bin/generate-creative.php image "<prompt>" [--mode draft|production] [--width 1200] [--height 628]
 *   php bin/generate-creative.php video "<prompt>" [--duration 5] [--aspect 16:9]
 *   php bin/generate-creative.php overlay <input> <output> --text "<text>" [--position center] [--font-size 48]
 *   php bin/generate-creative.php banner <input> <output> --text "<text>" [--position bottom]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdManager\Creative\ImageGen;
use AdManager\Creative\VideoGen;
use AdManager\Creative\Overlay;

$command = $argv[1] ?? null;

if (!$command) {
    printUsage();
    exit(1);
}

/**
 * Parse --key value pairs from argv.
 */
function parseArgs(array $argv): array
{
    $args = [];
    for ($i = 0; $i < count($argv); $i++) {
        if (str_starts_with($argv[$i], '--')) {
            $key = substr($argv[$i], 2);
            $val = $argv[$i + 1] ?? true;
            if (is_string($val) && str_starts_with($val, '--')) {
                $args[$key] = true;
            } else {
                $args[$key] = $val;
                $i++;
            }
        }
    }
    return $args;
}

function printUsage(): void
{
    echo "Usage:\n";
    echo "  php bin/generate-creative.php image \"<prompt>\" [--mode draft|production] [--width 1200] [--height 628]\n";
    echo "  php bin/generate-creative.php video \"<prompt>\" [--duration 5] [--aspect 16:9]\n";
    echo "  php bin/generate-creative.php overlay <input> <output> --text \"<text>\" [--position center] [--font-size 48]\n";
    echo "  php bin/generate-creative.php banner <input> <output> --text \"<text>\" [--position bottom]\n";
}

try {
    $args = parseArgs($argv);

    switch ($command) {
        case 'image':
            $prompt = $argv[2] ?? null;
            if (!$prompt) {
                echo "Error: image requires a prompt.\n";
                printUsage();
                exit(1);
            }

            $mode   = $args['mode'] ?? 'draft';
            $width  = (int)($args['width'] ?? 1200);
            $height = (int)($args['height'] ?? 628);

            $gen  = new ImageGen();
            $cost = $gen->estimateCost($mode);
            echo "Generating {$mode} image (~\${$cost})...\n";

            $path = $gen->generate($prompt, $mode, $width, $height);
            echo "Image saved: {$path}\n";
            break;

        case 'video':
            $prompt = $argv[2] ?? null;
            if (!$prompt) {
                echo "Error: video requires a prompt.\n";
                printUsage();
                exit(1);
            }

            $duration = (int)($args['duration'] ?? 5);
            $aspect   = $args['aspect'] ?? '16:9';

            $gen = new VideoGen();
            echo "Generating video ({$duration}s, {$aspect})...\n";

            $path = $gen->generate($prompt, $duration, $aspect);
            echo "Video saved: {$path}\n";
            break;

        case 'overlay':
            $input  = $argv[2] ?? null;
            $output = $argv[3] ?? null;
            $text   = $args['text'] ?? null;

            if (!$input || !$output || !$text) {
                echo "Error: overlay requires <input> <output> --text \"<text>\".\n";
                printUsage();
                exit(1);
            }

            $options = [];
            if (isset($args['position']))  $options['position']  = $args['position'];
            if (isset($args['font-size'])) $options['fontSize']  = (int)$args['font-size'];
            if (isset($args['font-color'])) $options['fontColor'] = $args['font-color'];
            if (isset($args['box-color'])) $options['boxColor']   = $args['box-color'];

            $overlay = new Overlay();
            $overlay->addText($input, $output, $text, $options);
            echo "Overlay applied: {$output}\n";
            break;

        case 'banner':
            $input  = $argv[2] ?? null;
            $output = $argv[3] ?? null;
            $text   = $args['text'] ?? null;

            if (!$input || !$output || !$text) {
                echo "Error: banner requires <input> <output> --text \"<text>\".\n";
                printUsage();
                exit(1);
            }

            $position = $args['position'] ?? 'bottom';
            $options  = [];
            if (isset($args['font-size'])) $options['fontSize']   = (int)$args['font-size'];
            if (isset($args['bg-color']))  $options['bgColor']    = $args['bg-color'];
            if (isset($args['text-color'])) $options['textColor'] = $args['text-color'];

            $overlay = new Overlay();
            $overlay->addBanner($input, $output, $text, $position, $options);
            echo "Banner applied: {$output}\n";
            break;

        default:
            echo "Unknown command: {$command}\n";
            printUsage();
            exit(1);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
