<?php

namespace AdManager\Creative;

class Overlay
{
    private string $ffmpeg;

    public function __construct()
    {
        $this->ffmpeg = getenv('FFMPEG_PATH') ?: 'ffmpeg';
    }

    /**
     * Add text overlay to an image or video.
     *
     * @param string $inputPath  Source file
     * @param string $outputPath Destination file
     * @param string $text       Text to render
     * @param array  $options    Rendering options:
     *   - x, y:         manual position (overrides 'position')
     *   - fontSize:      default 48
     *   - fontColor:     default 'white'
     *   - fontFile:      path to .ttf font file
     *   - boxColor:      background box color (e.g. 'black')
     *   - boxOpacity:    box opacity 0.0-1.0 (default 0.5)
     *   - position:      'center'|'top'|'bottom'|'top-left'|'bottom-right'
     */
    public function addText(string $inputPath, string $outputPath, string $text, array $options = []): void
    {
        $this->assertFileExists($inputPath);

        $fontSize  = $options['fontSize'] ?? 48;
        $fontColor = $options['fontColor'] ?? 'white';
        $position  = $options['position'] ?? 'center';

        // Escape text for ffmpeg drawtext filter
        $escapedText = $this->escapeText($text);

        // Build position coordinates
        [$x, $y] = $this->resolvePosition($position, $options);

        // Build drawtext filter
        $filter = "drawtext=text='{$escapedText}':fontsize={$fontSize}:fontcolor={$fontColor}:x={$x}:y={$y}";

        if (isset($options['fontFile'])) {
            $filter .= ":fontfile='{$options['fontFile']}'";
        }

        // Background box
        if (isset($options['boxColor'])) {
            $boxOpacity = $options['boxOpacity'] ?? 0.5;
            $filter .= ":box=1:boxcolor={$options['boxColor']}@{$boxOpacity}:boxborderw=10";
        }

        $cmd = sprintf(
            '%s -i %s -vf %s -y %s 2>&1',
            escapeshellarg($this->ffmpeg),
            escapeshellarg($inputPath),
            escapeshellarg($filter),
            escapeshellarg($outputPath)
        );

        $this->exec($cmd);
    }

    /**
     * Add a full-width banner with background to an image or video.
     *
     * @param string $inputPath  Source file
     * @param string $outputPath Destination file
     * @param string $text       Banner text
     * @param string $position   'top' or 'bottom'
     * @param array  $options    Rendering options:
     *   - bgColor:    banner background color (default 'black')
     *   - bgOpacity:  background opacity (default 0.7)
     *   - textColor:  text color (default 'white')
     *   - fontSize:   default 36
     */
    public function addBanner(string $inputPath, string $outputPath, string $text, string $position = 'bottom', array $options = []): void
    {
        $this->assertFileExists($inputPath);

        $bgColor   = $options['bgColor'] ?? 'black';
        $bgOpacity = $options['bgOpacity'] ?? 0.7;
        $textColor = $options['textColor'] ?? 'white';
        $fontSize  = $options['fontSize'] ?? 36;
        $bannerH   = (int)($fontSize * 2.5);

        $escapedText = $this->escapeText($text);

        // Calculate banner Y position
        if ($position === 'top') {
            $boxY = '0';
            $textY = (string)(int)($bannerH / 2 - $fontSize / 2);
        } else {
            $boxY = "ih-{$bannerH}";
            $textY = "h-{$bannerH}+" . (int)($bannerH / 2 - $fontSize / 2);
        }

        // Chain drawbox (background) + drawtext (text)
        $filter = "drawbox=x=0:y={$boxY}:w=iw:h={$bannerH}:color={$bgColor}@{$bgOpacity}:t=fill,"
                . "drawtext=text='{$escapedText}':fontsize={$fontSize}:fontcolor={$textColor}"
                . ":x=(w-text_w)/2:y={$textY}";

        $cmd = sprintf(
            '%s -i %s -vf %s -y %s 2>&1',
            escapeshellarg($this->ffmpeg),
            escapeshellarg($inputPath),
            escapeshellarg($filter),
            escapeshellarg($outputPath)
        );

        $this->exec($cmd);
    }

    /**
     * Composite one image on top of another (e.g. logo placement).
     *
     * @param string $backgroundPath Background image/video
     * @param string $overlayPath    Overlay image (PNG with transparency)
     * @param string $outputPath     Destination file
     * @param int    $x              X offset for overlay
     * @param int    $y              Y offset for overlay
     */
    public function composite(string $backgroundPath, string $overlayPath, string $outputPath, int $x = 0, int $y = 0): void
    {
        $this->assertFileExists($backgroundPath);
        $this->assertFileExists($overlayPath);

        $cmd = sprintf(
            '%s -i %s -i %s -filter_complex "overlay=%d:%d" -y %s 2>&1',
            escapeshellarg($this->ffmpeg),
            escapeshellarg($backgroundPath),
            escapeshellarg($overlayPath),
            $x,
            $y,
            escapeshellarg($outputPath)
        );

        $this->exec($cmd);
    }

    /**
     * Resolve a named position to ffmpeg x/y expressions.
     *
     * @return array{string, string} [x_expr, y_expr]
     */
    private function resolvePosition(string $position, array $options): array
    {
        // Manual x/y override
        if (isset($options['x']) && isset($options['y'])) {
            return [(string)$options['x'], (string)$options['y']];
        }

        return match ($position) {
            'center'       => ['(w-text_w)/2', '(h-text_h)/2'],
            'top'          => ['(w-text_w)/2', '40'],
            'bottom'       => ['(w-text_w)/2', 'h-th-40'],
            'top-left'     => ['40', '40'],
            'top-right'    => ['w-text_w-40', '40'],
            'bottom-left'  => ['40', 'h-th-40'],
            'bottom-right' => ['w-text_w-40', 'h-th-40'],
            default        => ['(w-text_w)/2', '(h-text_h)/2'],
        };
    }

    /**
     * Escape text for ffmpeg drawtext filter.
     */
    private function escapeText(string $text): string
    {
        // ffmpeg drawtext requires escaping: ' : \
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("'", "'\\''", $text);
        $text = str_replace(':', '\\:', $text);

        return $text;
    }

    /**
     * Assert that a file exists before processing.
     */
    private function assertFileExists(string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }
    }

    /**
     * Execute a shell command, throwing on failure.
     */
    private function exec(string $cmd): void
    {
        $output   = [];
        $exitCode = 0;

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $outputStr = implode("\n", $output);
            throw new \RuntimeException("ffmpeg command failed (exit {$exitCode}): {$outputStr}\nCommand: {$cmd}");
        }
    }
}
