<?php

namespace AdManager\Creative;

/**
 * Audio mixing for ad videos using ffmpeg.
 *
 * Combines video + background music + optional voiceover + optional captions.
 * All inputs are local files; output is a single .mp4 ready for upload.
 */
class AudioMix
{
    private string $ffmpeg;
    private string $assetsDir;

    /** Mixkit tracks — royalty-free, commercial use, no attribution. */
    private const MUSIC_TRACKS = [
        'dreaming-of-you'  => 'https://assets.mixkit.co/music/952/952.mp3',
        'mountains'        => 'https://assets.mixkit.co/music/187/187.mp3',
        'rising-forest'    => 'https://assets.mixkit.co/music/471/471.mp3',
        'i-believe-in-us'  => 'https://assets.mixkit.co/music/1030/1030.mp3',
        'pop-one'          => 'https://assets.mixkit.co/music/664/664.mp3',
        'talent-in-air'    => 'https://assets.mixkit.co/music/473/473.mp3',
        'uplifting-bass'   => 'https://assets.mixkit.co/music/726/726.mp3',
        'focus-on-yourself' => 'https://assets.mixkit.co/music/568/568.mp3',
        'close-up'         => 'https://assets.mixkit.co/music/1167/1167.mp3',
        'what-about-action' => 'https://assets.mixkit.co/music/474/474.mp3',
        'pop-track-03'     => 'https://assets.mixkit.co/music/729/729.mp3',
        'gear'             => 'https://assets.mixkit.co/music/180/180.mp3',
    ];

    /** Tracks suited for parenting/family content (softer, warmer). */
    private const FAMILY_TRACKS = ['dreaming-of-you', 'mountains', 'rising-forest', 'i-believe-in-us', 'close-up'];

    /** Tracks suited for energetic/action content. */
    private const ENERGETIC_TRACKS = ['pop-one', 'what-about-action', 'uplifting-bass', 'gear', 'talent-in-air'];

    public function __construct()
    {
        $this->ffmpeg = getenv('FFMPEG_PATH') ?: 'ffmpeg';
        $this->assetsDir = dirname(__DIR__, 2) . '/assets';
        $musicDir = $this->assetsDir . '/music';
        if (!is_dir($musicDir)) mkdir($musicDir, 0755, true);
    }

    /**
     * Mix video with background music and optional voiceover.
     *
     * @param string      $videoPath  Input video (silent or with existing audio)
     * @param string      $outputPath Output .mp4 path
     * @param array       $options    [
     *   'music'         => 'dreaming-of-you' | path to .mp3 | null (no music),
     *   'music_volume'  => 0.15 (default),
     *   'voiceover'     => path to .mp3/.wav | null,
     *   'vo_volume'     => 0.8 (default),
     *   'captions'      => path to .srt file | null,
     *   'caption_style' => 'FontSize=20,PrimaryColour=&HFFFFFF&' (ASS style),
     *   'fade_music'    => true (fade out last 2s),
     *   'mood'          => 'family' | 'energetic' (auto-pick track if music not specified),
     * ]
     */
    public function mix(string $videoPath, string $outputPath, array $options = []): void
    {
        if (!file_exists($videoPath)) {
            throw new \RuntimeException("Video file not found: {$videoPath}");
        }

        $musicVolume = $options['music_volume'] ?? 0.15;
        $voVolume = $options['vo_volume'] ?? 0.8;
        $fadeLast = $options['fade_music'] ?? true;

        // Resolve music track
        $musicPath = $this->resolveMusicPath($options['music'] ?? null, $options['mood'] ?? 'family');

        // Build ffmpeg command
        $inputs = ['-i ' . escapeshellarg($videoPath)];
        $filterParts = [];
        $inputIdx = 1;

        // Get video duration for fade calculation
        $duration = $this->getVideoDuration($videoPath);

        // Music input
        if ($musicPath) {
            $inputs[] = '-i ' . escapeshellarg($musicPath);
            $musicFilter = "[{$inputIdx}:a]atrim=0:{$duration},asetpts=PTS-STARTPTS,volume={$musicVolume}";
            if ($fadeLast && $duration > 2) {
                $fadeStart = $duration - 2;
                $musicFilter .= ",afade=t=out:st={$fadeStart}:d=2";
            }
            $musicFilter .= '[music]';
            $filterParts[] = $musicFilter;
            $inputIdx++;
        }

        // Voiceover input
        $voPath = $options['voiceover'] ?? null;
        if ($voPath && file_exists($voPath)) {
            $inputs[] = '-i ' . escapeshellarg($voPath);
            $filterParts[] = "[{$inputIdx}:a]volume={$voVolume}[vo]";
            $inputIdx++;
        }

        // Build audio mix
        $audioStreams = [];
        if ($musicPath) $audioStreams[] = '[music]';
        if ($voPath && file_exists($voPath)) $audioStreams[] = '[vo]';

        if (count($audioStreams) > 1) {
            $filterParts[] = implode('', $audioStreams) . "amix=inputs=" . count($audioStreams) . ":duration=first[mixed]";
            $audioMap = '[mixed]';
        } elseif (count($audioStreams) === 1) {
            $audioMap = $audioStreams[0];
        } else {
            $audioMap = null;
        }

        // Captions (burn-in via subtitles filter)
        $captionPath = $options['captions'] ?? null;
        $videoFilter = '[0:v]copy[vout]';
        if ($captionPath && file_exists($captionPath)) {
            $style = $options['caption_style'] ?? 'FontSize=22,PrimaryColour=&HFFFFFF&,OutlineColour=&H000000&,Outline=2,MarginV=30';
            $escapedPath = str_replace([':', "'", "\\"], ["\\:", "\\'", "\\\\"], $captionPath);
            $videoFilter = "[0:v]subtitles='{$escapedPath}':force_style='{$style}'[vout]";
        }
        $filterParts[] = $videoFilter;

        // Assemble command
        $filter = implode('; ', $filterParts);
        $maps = '-map [vout]';
        if ($audioMap) $maps .= ' -map ' . $audioMap;

        $cmd = sprintf(
            '%s %s -filter_complex %s %s -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k -movflags +faststart -y %s 2>&1',
            $this->ffmpeg,
            implode(' ', $inputs),
            escapeshellarg($filter),
            $maps,
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException("ffmpeg mix failed (exit {$exitCode}): " . implode("\n", array_slice($output, -5)));
        }
    }

    /**
     * Generate an SRT caption file from timed text entries.
     *
     * @param array $captions [['start' => 0.0, 'end' => 3.0, 'text' => 'Hello'], ...]
     * @return string Path to generated .srt file
     */
    public function generateSrt(array $captions, string $outputPath = ''): string
    {
        if (!$outputPath) {
            $outputPath = $this->assetsDir . '/captions/' . date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6) . '.srt';
            $dir = dirname($outputPath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }

        $srt = '';
        foreach ($captions as $i => $c) {
            $num = $i + 1;
            $start = $this->formatSrtTime($c['start']);
            $end = $this->formatSrtTime($c['end']);
            $text = $c['text'];
            $srt .= "{$num}\n{$start} --> {$end}\n{$text}\n\n";
        }

        file_put_contents($outputPath, $srt);
        return $outputPath;
    }

    /**
     * Download a music track by name if not cached locally.
     */
    public function downloadTrack(string $trackName): string
    {
        if (!isset(self::MUSIC_TRACKS[$trackName])) {
            throw new \RuntimeException("Unknown track: {$trackName}. Available: " . implode(', ', array_keys(self::MUSIC_TRACKS)));
        }

        $localPath = $this->assetsDir . "/music/{$trackName}.mp3";
        if (file_exists($localPath) && filesize($localPath) > 1000) {
            return $localPath;
        }

        $url = self::MUSIC_TRACKS[$trackName];
        $data = file_get_contents($url);
        if ($data === false) {
            throw new \RuntimeException("Failed to download music track: {$url}");
        }

        file_put_contents($localPath, $data);
        return $localPath;
    }

    /**
     * Pick a track suited to the mood and download it.
     */
    public function pickTrack(string $mood = 'family', ?int $seed = null): string
    {
        $pool = $mood === 'energetic' ? self::ENERGETIC_TRACKS : self::FAMILY_TRACKS;
        $idx = $seed !== null ? $seed % count($pool) : array_rand($pool);
        $name = $pool[$idx];
        return $this->downloadTrack($name);
    }

    /**
     * List available track names.
     */
    public static function availableTracks(): array
    {
        return array_keys(self::MUSIC_TRACKS);
    }

    private function resolveMusicPath(?string $music, string $mood): ?string
    {
        if ($music === null) return null;
        if ($music === '' || $music === 'auto') return $this->pickTrack($mood);
        if (file_exists($music)) return $music;
        if (isset(self::MUSIC_TRACKS[$music])) return $this->downloadTrack($music);
        return null;
    }

    private function getVideoDuration(string $path): float
    {
        $cmd = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
            escapeshellarg($path)
        );
        $output = trim(shell_exec($cmd) ?? '');
        return (float)$output ?: 15.0;
    }

    private function formatSrtTime(float $seconds): string
    {
        $total = (int) floor($seconds);
        $h = intdiv($total, 3600);
        $m = intdiv($total % 3600, 60);
        $s = $total % 60;
        $ms = (int) round(($seconds - $total) * 1000);
        return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $ms);
    }
}
