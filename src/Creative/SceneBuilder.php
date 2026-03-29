<?php

namespace AdManager\Creative;

use AdManager\DB;

/**
 * Multi-scene video ad builder.
 *
 * Takes a scene definition, generates each clip via Kling, concatenates them,
 * then runs AudioMix for music + captions. Output is a single .mp4 ready for upload.
 *
 * Typical 15s ad structure:
 *   Scene 1 (0-5s)  — Hook: grab attention
 *   Scene 2 (5-10s) — Story: show benefit / demonstrate product
 *   Scene 3 (10-15s) — CTA: end card with brand + call to action
 */
class SceneBuilder
{
    private VideoGen $videoGen;
    private AudioMix $audioMix;
    private Overlay $overlay;
    private string $ffmpeg;
    private string $assetsDir;

    public function __construct()
    {
        $this->videoGen = new VideoGen();
        $this->audioMix = new AudioMix();
        $this->overlay = new Overlay();
        $this->ffmpeg = getenv('FFMPEG_PATH') ?: 'ffmpeg';
        $this->assetsDir = dirname(__DIR__, 2) . '/assets/videos';
        if (!is_dir($this->assetsDir)) mkdir($this->assetsDir, 0755, true);
    }

    /**
     * Build a multi-scene video ad.
     *
     * @param array $scenes Each scene: [
     *   'prompt'   => 'Kling prompt for this scene',
     *   'duration' => 5,           // seconds (Kling: 5 or 10)
     *   'caption'  => 'Text shown during this scene',  // optional
     * ]
     * @param array $options [
     *   'aspect_ratio'  => '9:16',          // portrait (reels/stories)
     *   'music'         => 'dreaming-of-you', // track name, path, or 'auto'
     *   'music_volume'  => 0.15,
     *   'voiceover'     => '/path/to/vo.mp3', // optional
     *   'vo_volume'     => 0.8,
     *   'end_card'      => ['text' => 'Try Colormora', 'duration' => 3], // optional static end card
     *   'project_id'    => 1,               // for DB tracking
     * ]
     * @return string Path to final .mp4
     */
    public function build(array $scenes, array $options = []): string
    {
        if (empty($scenes)) {
            throw new \RuntimeException('At least one scene is required');
        }

        $aspectRatio = $options['aspect_ratio'] ?? '9:16';
        $projectId = $options['project_id'] ?? null;
        $timestamp = date('Ymd-His');
        $hash = substr(md5(json_encode($scenes) . microtime()), 0, 6);
        $workDir = $this->assetsDir . "/build-{$timestamp}-{$hash}";
        mkdir($workDir, 0755, true);

        $clipPaths = [];
        $captions = [];
        $timeOffset = 0.0;

        // Generate each scene
        foreach ($scenes as $i => $scene) {
            $sceneNum = $i + 1;
            $duration = $scene['duration'] ?? 5;
            $prompt = $scene['prompt'];

            echo "  Scene {$sceneNum}/" . count($scenes) . ": generating {$duration}s clip...\n";

            try {
                $clipPath = $this->videoGen->generate($prompt, $duration, $aspectRatio);
                $clipPaths[] = $clipPath;
                echo "    Saved: {$clipPath}\n";
            } catch (\Exception $e) {
                echo "    ERROR: {$e->getMessage()}\n";
                // Clean up and bail
                $this->cleanup($workDir, $clipPaths);
                throw new \RuntimeException("Scene {$sceneNum} generation failed: {$e->getMessage()}");
            }

            // Build caption timing
            if (!empty($scene['caption'])) {
                $captions[] = [
                    'start' => $timeOffset,
                    'end'   => $timeOffset + $duration,
                    'text'  => $scene['caption'],
                ];
            }

            $timeOffset += $duration;
        }

        // Optional end card (static image with text, converted to video)
        if (!empty($options['end_card'])) {
            $endCard = $options['end_card'];
            $endDuration = $endCard['duration'] ?? 3;
            $endText = $endCard['text'] ?? '';
            $endPath = $this->generateEndCard($workDir, $endText, $endDuration, $aspectRatio);
            $clipPaths[] = $endPath;

            if (!empty($endText)) {
                $captions[] = [
                    'start' => $timeOffset,
                    'end'   => $timeOffset + $endDuration,
                    'text'  => $endText,
                ];
            }
            $timeOffset += $endDuration;
        }

        // Concatenate clips
        echo "  Concatenating " . count($clipPaths) . " clips...\n";
        $concatPath = "{$workDir}/concat.mp4";
        $this->concatenate($clipPaths, $concatPath);

        // Generate SRT if we have captions
        $srtPath = null;
        if (!empty($captions)) {
            $srtPath = $this->audioMix->generateSrt($captions, "{$workDir}/captions.srt");
        }

        // Final mix: music + captions
        $finalPath = "{$this->assetsDir}/ad-{$timestamp}-{$hash}.mp4";
        echo "  Mixing audio + captions...\n";
        $this->audioMix->mix($concatPath, $finalPath, [
            'music'        => $options['music'] ?? 'auto',
            'music_volume' => $options['music_volume'] ?? 0.15,
            'voiceover'    => $options['voiceover'] ?? null,
            'vo_volume'    => $options['vo_volume'] ?? 0.8,
            'captions'     => $srtPath,
            'mood'         => $options['mood'] ?? 'family',
        ]);

        // Clean up work directory
        $this->cleanup($workDir, []);

        // Save to DB
        if ($projectId) {
            $totalDuration = $timeOffset;
            $scenesSummary = implode(' | ', array_map(fn($s) => $s['caption'] ?? $s['prompt'], $scenes));
            $db = DB::get();
            $db->prepare('INSERT INTO assets (project_id, type, platform, local_path, duration_seconds, generation_prompt, generation_model, generation_cost_usd, status) VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([
                   $projectId, 'video', 'local', $finalPath,
                   $totalDuration, $scenesSummary, 'kling+ffmpeg',
                   count($clipPaths) * 0.10, 'draft',
               ]);
            echo "  Asset #" . $db->lastInsertId() . "\n";
        }

        echo "  Final: {$finalPath} (" . round(filesize($finalPath) / 1024 / 1024, 1) . "MB)\n";
        return $finalPath;
    }

    /**
     * Concatenate multiple video clips using ffmpeg concat demuxer.
     */
    private function concatenate(array $clipPaths, string $outputPath): void
    {
        // Write concat list
        $listPath = dirname($outputPath) . '/concat-list.txt';
        $lines = array_map(fn($p) => "file " . escapeshellarg($p), $clipPaths);
        file_put_contents($listPath, implode("\n", $lines));

        $cmd = sprintf(
            '%s -f concat -safe 0 -i %s -c copy -y %s 2>&1',
            $this->ffmpeg,
            escapeshellarg($listPath),
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException("ffmpeg concat failed: " . implode("\n", array_slice($output, -5)));
        }
    }

    /**
     * Generate a static end card as a short video clip.
     */
    private function generateEndCard(string $workDir, string $text, int $duration, string $aspectRatio): string
    {
        // Parse aspect ratio to dimensions
        [$w, $h] = match ($aspectRatio) {
            '9:16'  => [1080, 1920],
            '16:9'  => [1920, 1080],
            '1:1'   => [1080, 1080],
            '4:5'   => [1080, 1350],
            default => [1080, 1920],
        };

        $endPath = "{$workDir}/endcard.mp4";

        // Create a dark gradient background with centered text
        $escapedText = str_replace(["'", ':', '\\'], ["\\'", "\\:", "\\\\"], $text);
        $cmd = sprintf(
            '%s -f lavfi -i "color=c=0x111827:s=%dx%d:d=%d,format=yuv420p" ' .
            '-vf "drawtext=text=\'%s\':fontsize=56:fontcolor=white:x=(w-text_w)/2:y=(h-text_h)/2:font=sans" ' .
            '-c:v libx264 -preset fast -crf 23 -y %s 2>&1',
            $this->ffmpeg, $w, $h, $duration,
            $escapedText,
            escapeshellarg($endPath)
        );

        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException("End card generation failed: " . implode("\n", array_slice($output, -3)));
        }

        return $endPath;
    }

    private function cleanup(string $workDir, array $extraFiles): void
    {
        // Remove work directory contents
        if (is_dir($workDir)) {
            $files = glob("{$workDir}/*");
            foreach ($files as $f) @unlink($f);
            @rmdir($workDir);
        }
    }
}
