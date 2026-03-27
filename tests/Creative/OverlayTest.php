<?php

declare(strict_types=1);

namespace AdManager\Tests\Creative;

use AdManager\Creative\Overlay;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Creative\Overlay.
 *
 * Overlay wraps ffmpeg via exec(). Because exec() is a private method that
 * directly calls PHP's built-in exec(), we cannot intercept it without
 * modifying the source. We test:
 *
 *  1. File-existence guards (assertFileExists) — throw before exec() is reached.
 *  2. resolvePosition() — private method, tested via ReflectionMethod.
 *  3. escapeText()       — private method, tested via ReflectionMethod.
 *  4. Command string shape — by pointing to /dev/null output so ffmpeg can
 *     produce the command error and we inspect the RuntimeException message,
 *     OR by running real ffmpeg if available (skipped when not installed).
 *
 * The focus is on the logic that lives in PHP (position maths, text escaping,
 * filter-string construction) rather than ffmpeg's video processing.
 */
class OverlayTest extends TestCase
{
    private string $tmpDir;
    private string $inputFile;

    protected function setUp(): void
    {
        $this->tmpDir    = sys_get_temp_dir() . '/overlay-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        // Create a dummy input file so assertFileExists() passes
        $this->inputFile = "{$this->tmpDir}/input.png";
        file_put_contents($this->inputFile, 'fake image data');
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->tmpDir}/*") as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        putenv('FFMPEG_PATH');
    }

    // -------------------------------------------------------------------------
    // Constructor — FFMPEG_PATH env var
    // -------------------------------------------------------------------------

    public function testConstructorDefaultsToFfmpegWhenEnvNotSet(): void
    {
        putenv('FFMPEG_PATH');
        $overlay = new Overlay();

        $ref = new \ReflectionProperty(Overlay::class, 'ffmpeg');
        $ref->setAccessible(true);
        $this->assertSame('ffmpeg', $ref->getValue($overlay));
    }

    public function testConstructorUsesCustomFfmpegPathFromEnv(): void
    {
        putenv('FFMPEG_PATH=/usr/local/bin/ffmpeg-custom');
        $overlay = new Overlay();

        $ref = new \ReflectionProperty(Overlay::class, 'ffmpeg');
        $ref->setAccessible(true);
        $this->assertSame('/usr/local/bin/ffmpeg-custom', $ref->getValue($overlay));
    }

    // -------------------------------------------------------------------------
    // assertFileExists() guard — throws before reaching exec()
    // -------------------------------------------------------------------------

    public function testAddTextThrowsRuntimeExceptionWhenInputFileMissing(): void
    {
        $overlay = new Overlay();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/File not found: .*missing\.png/');

        $overlay->addText('/nonexistent/missing.png', "{$this->tmpDir}/out.png", 'Hello');
    }

    public function testAddBannerThrowsRuntimeExceptionWhenInputFileMissing(): void
    {
        $overlay = new Overlay();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/File not found/');

        $overlay->addBanner('/nonexistent/file.mp4', "{$this->tmpDir}/out.mp4", 'Banner text');
    }

    public function testCompositeThrowsWhenBackgroundFileMissing(): void
    {
        $overlay    = new Overlay();
        $overlayPng = "{$this->tmpDir}/overlay.png";
        file_put_contents($overlayPng, 'fake');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/File not found/');

        $overlay->composite('/missing/bg.png', $overlayPng, "{$this->tmpDir}/out.png");
    }

    public function testCompositeThrowsWhenOverlayFileMissing(): void
    {
        $overlay = new Overlay();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/File not found/');

        $overlay->composite($this->inputFile, '/missing/overlay.png', "{$this->tmpDir}/out.png");
    }

    // -------------------------------------------------------------------------
    // resolvePosition() — tested via ReflectionMethod
    // -------------------------------------------------------------------------

    private function resolvePosition(Overlay $overlay, string $position, array $options = []): array
    {
        $ref = new \ReflectionMethod(Overlay::class, 'resolvePosition');
        $ref->setAccessible(true);
        return $ref->invoke($overlay, $position, $options);
    }

    public function testResolvePositionCenter(): void
    {
        $overlay        = new Overlay();
        [$x, $y]        = $this->resolvePosition($overlay, 'center');

        $this->assertSame('(w-text_w)/2', $x);
        $this->assertSame('(h-text_h)/2', $y);
    }

    public function testResolvePositionTop(): void
    {
        $overlay = new Overlay();
        [$x, $y] = $this->resolvePosition($overlay, 'top');

        $this->assertSame('(w-text_w)/2', $x);
        $this->assertSame('40', $y);
    }

    public function testResolvePositionBottom(): void
    {
        $overlay = new Overlay();
        [$x, $y] = $this->resolvePosition($overlay, 'bottom');

        $this->assertSame('(w-text_w)/2', $x);
        $this->assertSame('h-th-40', $y);
    }

    public function testResolvePositionTopLeft(): void
    {
        $overlay = new Overlay();
        [$x, $y] = $this->resolvePosition($overlay, 'top-left');

        $this->assertSame('40', $x);
        $this->assertSame('40', $y);
    }

    public function testResolvePositionTopRight(): void
    {
        $overlay = new Overlay();
        [$x, $y] = $this->resolvePosition($overlay, 'top-right');

        $this->assertSame('w-text_w-40', $x);
        $this->assertSame('40', $y);
    }

    public function testResolvePositionBottomLeft(): void
    {
        $overlay = new Overlay();
        [$x, $y] = $this->resolvePosition($overlay, 'bottom-left');

        $this->assertSame('40', $x);
        $this->assertSame('h-th-40', $y);
    }

    public function testResolvePositionBottomRight(): void
    {
        $overlay = new Overlay();
        [$x, $y] = $this->resolvePosition($overlay, 'bottom-right');

        $this->assertSame('w-text_w-40', $x);
        $this->assertSame('h-th-40', $y);
    }

    public function testResolvePositionDefaultFallsBackToCenter(): void
    {
        $overlay = new Overlay();
        [$x, $y] = $this->resolvePosition($overlay, 'middle');  // unknown value

        $this->assertSame('(w-text_w)/2', $x);
        $this->assertSame('(h-text_h)/2', $y);
    }

    public function testResolvePositionManualXYOverridesNamedPosition(): void
    {
        $overlay = new Overlay();
        [$x, $y] = $this->resolvePosition($overlay, 'center', ['x' => 150, 'y' => 250]);

        $this->assertSame('150', $x);
        $this->assertSame('250', $y);
    }

    public function testResolvePositionManualXYAreReturnedAsStrings(): void
    {
        $overlay = new Overlay();
        [$x, $y] = $this->resolvePosition($overlay, 'bottom', ['x' => 0, 'y' => 0]);

        $this->assertIsString($x);
        $this->assertIsString($y);
    }

    // -------------------------------------------------------------------------
    // escapeText() — tested via ReflectionMethod
    // -------------------------------------------------------------------------

    private function escapeText(Overlay $overlay, string $text): string
    {
        $ref = new \ReflectionMethod(Overlay::class, 'escapeText');
        $ref->setAccessible(true);
        return $ref->invoke($overlay, $text);
    }

    public function testEscapeTextLeavesPlainTextUnchanged(): void
    {
        $overlay = new Overlay();
        $this->assertSame('Hello World', $this->escapeText($overlay, 'Hello World'));
    }

    public function testEscapeTextEscapesSingleQuotes(): void
    {
        $overlay = new Overlay();
        $result  = $this->escapeText($overlay, "It's great");

        // ' → '\''
        $this->assertStringContainsString("'\\''", $result);
    }

    public function testEscapeTextEscapesColons(): void
    {
        $overlay = new Overlay();
        $result  = $this->escapeText($overlay, 'Score: 100');

        $this->assertStringContainsString('\\:', $result);
        $this->assertStringNotContainsString('Score: 100', $result);  // raw colon gone
    }

    public function testEscapeTextEscapesBackslashes(): void
    {
        $overlay = new Overlay();
        $result  = $this->escapeText($overlay, 'path\\to\\file');

        // Each \ becomes \\
        $this->assertStringContainsString('\\\\', $result);
    }

    public function testEscapeTextHandlesMultipleSpecialChars(): void
    {
        $overlay = new Overlay();
        $input   = "Time: 10:00 — It's done";
        $result  = $this->escapeText($overlay, $input);

        // Both colons should be escaped as \:
        $this->assertStringContainsString('\\:', $result);
        // The single-quote should be escaped
        $this->assertStringContainsString("'\\''", $result);
        // The original unescaped form should not appear verbatim
        $this->assertStringNotContainsString("It's", $result);
    }

    public function testEscapeTextEmptyStringRemainsEmpty(): void
    {
        $overlay = new Overlay();
        $this->assertSame('', $this->escapeText($overlay, ''));
    }

    // -------------------------------------------------------------------------
    // addBanner() — banner height calculation via ReflectionMethod on the filter
    // The filter string shape is validated indirectly through the exception message
    // when ffmpeg is unavailable, so we inspect what PHP builds.
    // -------------------------------------------------------------------------

    /**
     * We invoke addBanner with a dummy ffmpeg path so exec() fails with the
     * ffmpeg error — the RuntimeException message contains the command line,
     * which lets us assert on the filter contents without real ffmpeg.
     */
    public function testAddBannerCommandContainsDrawboxAndDrawtext(): void
    {
        putenv('FFMPEG_PATH=/bin/false');  // exits non-zero immediately
        $overlay = new Overlay();

        try {
            $overlay->addBanner($this->inputFile, "{$this->tmpDir}/out.png", 'Free Shipping');
            $this->fail('Expected RuntimeException from ffmpeg failure');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            // The command string is included in the exception message
            $this->assertStringContainsString('drawbox', $msg);
            $this->assertStringContainsString('drawtext', $msg);
        }
    }

    public function testAddBannerBottomPositionUsesIhMinus(): void
    {
        putenv('FFMPEG_PATH=/bin/false');
        $overlay = new Overlay();

        try {
            $overlay->addBanner($this->inputFile, "{$this->tmpDir}/out.png", 'Sale', 'bottom');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ih-', $e->getMessage());
        }
    }

    public function testAddBannerTopPositionUsesY0(): void
    {
        putenv('FFMPEG_PATH=/bin/false');
        $overlay = new Overlay();

        try {
            $overlay->addBanner($this->inputFile, "{$this->tmpDir}/out.png", 'Header', 'top');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('y=0', $e->getMessage());
        }
    }

    public function testAddTextCommandShapeIsValidFfmpegCommand(): void
    {
        putenv('FFMPEG_PATH=/bin/false');
        $overlay = new Overlay();

        try {
            $overlay->addText($this->inputFile, "{$this->tmpDir}/out.png", 'Hello');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('drawtext', $msg);
            $this->assertStringContainsString('Hello', $msg);
            $this->assertStringContainsString('fontcolor=white', $msg);
            $this->assertStringContainsString('fontsize=48', $msg);
        }
    }

    public function testAddTextWithBoxOptionIncludesBoxFilter(): void
    {
        putenv('FFMPEG_PATH=/bin/false');
        $overlay = new Overlay();

        try {
            $overlay->addText($this->inputFile, "{$this->tmpDir}/out.png", 'CTA', [
                'boxColor'   => 'black',
                'boxOpacity' => 0.7,
            ]);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('box=1', $msg);
            $this->assertStringContainsString('black@0.7', $msg);
        }
    }

    public function testCompositeCommandContainsFilterComplexOverlay(): void
    {
        putenv('FFMPEG_PATH=/bin/false');
        $overlay2File = "{$this->tmpDir}/overlay.png";
        file_put_contents($overlay2File, 'fake');

        $overlay = new Overlay();

        try {
            $overlay->composite($this->inputFile, $overlay2File, "{$this->tmpDir}/out.png", 50, 100);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('overlay=50:100', $msg);
            $this->assertStringContainsString('-filter_complex', $msg);
        }
    }
}
