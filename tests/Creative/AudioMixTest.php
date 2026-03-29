<?php

declare(strict_types=1);

namespace AdManager\Tests\Creative;

use AdManager\Creative\AudioMix;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Creative\AudioMix.
 *
 * AudioMix wraps ffmpeg/ffprobe and downloads music from Mixkit. We avoid all
 * real network calls and real ffmpeg execution by:
 *
 *  1. Testing pure-PHP logic (SRT formatting, SRT generation) directly.
 *  2. Using ReflectionMethod to exercise private helpers (formatSrtTime,
 *     resolveMusicPath, getVideoDuration) in isolation.
 *  3. Setting FFMPEG_PATH=/bin/false so that mix() reaches exec() and fails
 *     with a non-zero exit, letting us assert on the RuntimeException.
 *  4. Subclassing AudioMix to stub downloadTrack() so pickTrack() is fully
 *     testable without network I/O.
 */
class AudioMixTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/audiomix-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->tmpDir}/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        putenv('FFMPEG_PATH');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Call a private method via reflection. */
    private function callPrivate(AudioMix $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod(AudioMix::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    /** Read a private const via reflection. */
    private function getConst(string $name): mixed
    {
        $ref = new \ReflectionClassConstant(AudioMix::class, $name);
        return $ref->getValue();
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function testConstructorCreatesMusicDirectory(): void
    {
        $mix = new AudioMix();

        $refProp = new \ReflectionProperty(AudioMix::class, 'assetsDir');
        $refProp->setAccessible(true);
        $assetsDir = $refProp->getValue($mix);

        $this->assertDirectoryExists($assetsDir . '/music');
    }

    // -------------------------------------------------------------------------
    // availableTracks()
    // -------------------------------------------------------------------------

    public function testAvailableTracksReturnsAllTwelveTracks(): void
    {
        $tracks = AudioMix::availableTracks();

        $this->assertCount(12, $tracks);
        $this->assertContains('dreaming-of-you', $tracks);
        $this->assertContains('gear', $tracks);
    }

    public function testAvailableTracksReturnsStrings(): void
    {
        foreach (AudioMix::availableTracks() as $track) {
            $this->assertIsString($track);
        }
    }

    // -------------------------------------------------------------------------
    // FAMILY_TRACKS and ENERGETIC_TRACKS are subsets of MUSIC_TRACKS
    // -------------------------------------------------------------------------

    public function testFamilyTracksAreSubsetOfMusicTracks(): void
    {
        $all    = $this->getConst('MUSIC_TRACKS');
        $family = $this->getConst('FAMILY_TRACKS');

        foreach ($family as $track) {
            $this->assertArrayHasKey($track, $all, "FAMILY_TRACKS contains '{$track}' which is not in MUSIC_TRACKS");
        }
    }

    public function testEnergeticTracksAreSubsetOfMusicTracks(): void
    {
        $all       = $this->getConst('MUSIC_TRACKS');
        $energetic = $this->getConst('ENERGETIC_TRACKS');

        foreach ($energetic as $track) {
            $this->assertArrayHasKey($track, $all, "ENERGETIC_TRACKS contains '{$track}' which is not in MUSIC_TRACKS");
        }
    }

    public function testFamilyTracksCountIs5(): void
    {
        $this->assertCount(5, $this->getConst('FAMILY_TRACKS'));
    }

    public function testEnergeticTracksCountIs5(): void
    {
        $this->assertCount(5, $this->getConst('ENERGETIC_TRACKS'));
    }

    // -------------------------------------------------------------------------
    // formatSrtTime() — private, accessed via reflection
    // -------------------------------------------------------------------------

    public function testFormatSrtTimeZero(): void
    {
        $mix    = new AudioMix();
        $result = $this->callPrivate($mix, 'formatSrtTime', [0.0]);
        $this->assertSame('00:00:00,000', $result);
    }

    public function testFormatSrtTimeWholeSeconds(): void
    {
        $mix    = new AudioMix();
        $result = $this->callPrivate($mix, 'formatSrtTime', [3.0]);
        $this->assertSame('00:00:03,000', $result);
    }

    public function testFormatSrtTimeWithMilliseconds(): void
    {
        $mix    = new AudioMix();
        $result = $this->callPrivate($mix, 'formatSrtTime', [1.5]);
        $this->assertSame('00:00:01,500', $result);
    }

    public function testFormatSrtTimeWithMinutes(): void
    {
        $mix    = new AudioMix();
        $result = $this->callPrivate($mix, 'formatSrtTime', [90.0]);
        $this->assertSame('00:01:30,000', $result);
    }

    public function testFormatSrtTimeWithHours(): void
    {
        $mix    = new AudioMix();
        $result = $this->callPrivate($mix, 'formatSrtTime', [3661.25]);
        $this->assertSame('01:01:01,250', $result);
    }

    public function testFormatSrtTimeSubMillisecondRoundsCorrectly(): void
    {
        $mix    = new AudioMix();
        $result = $this->callPrivate($mix, 'formatSrtTime', [2.999]);
        // 0.999 * 1000 = 999ms
        $this->assertSame('00:00:02,999', $result);
    }

    // -------------------------------------------------------------------------
    // generateSrt() — SRT format validity
    // -------------------------------------------------------------------------

    public function testGenerateSrtSingleCaptionProducesValidSrt(): void
    {
        $mix      = new AudioMix();
        $srtPath  = "{$this->tmpDir}/captions.srt";
        $captions = [
            ['start' => 0.0, 'end' => 3.0, 'text' => 'Hello world'],
        ];

        $result = $mix->generateSrt($captions, $srtPath);

        $this->assertSame($srtPath, $result);
        $this->assertFileExists($srtPath);

        $content = file_get_contents($srtPath);
        $this->assertStringContainsString("1\n", $content);
        $this->assertStringContainsString('00:00:00,000 --> 00:00:03,000', $content);
        $this->assertStringContainsString('Hello world', $content);
    }

    public function testGenerateSrtMultipleCaptionsAreNumberedSequentially(): void
    {
        $mix      = new AudioMix();
        $srtPath  = "{$this->tmpDir}/multi.srt";
        $captions = [
            ['start' => 0.0,  'end' => 3.0,  'text' => 'First'],
            ['start' => 3.0,  'end' => 7.5,  'text' => 'Second'],
            ['start' => 7.5,  'end' => 12.0, 'text' => 'Third'],
        ];

        $mix->generateSrt($captions, $srtPath);

        $content = file_get_contents($srtPath);

        // All three sequence numbers must appear
        $this->assertStringContainsString("1\n00:00:00,000 --> 00:00:03,000\nFirst", $content);
        $this->assertStringContainsString("2\n00:00:03,000 --> 00:00:07,500\nSecond", $content);
        $this->assertStringContainsString("3\n00:00:07,500 --> 00:00:12,000\nThird", $content);
    }

    public function testGenerateSrtBlocksSeparatedByBlankLines(): void
    {
        $mix      = new AudioMix();
        $srtPath  = "{$this->tmpDir}/blank.srt";
        $captions = [
            ['start' => 0.0, 'end' => 2.0, 'text' => 'A'],
            ['start' => 2.0, 'end' => 4.0, 'text' => 'B'],
        ];

        $mix->generateSrt($captions, $srtPath);

        $content = file_get_contents($srtPath);
        // Each block ends with \n\n
        $this->assertSame(2, substr_count($content, "\n\n"));
    }

    public function testGenerateSrtAutoGeneratesOutputPathWhenEmpty(): void
    {
        $mix      = new AudioMix();
        $captions = [['start' => 0.0, 'end' => 1.0, 'text' => 'Auto path test']];

        $result = $mix->generateSrt($captions);

        $this->assertStringEndsWith('.srt', $result);
        $this->assertFileExists($result);

        // Cleanup
        @unlink($result);
        @rmdir(dirname($result));
    }

    // -------------------------------------------------------------------------
    // resolveMusicPath() — private, accessed via reflection
    // -------------------------------------------------------------------------

    public function testResolveMusicPathReturnsNullForNullInput(): void
    {
        $mix    = new AudioMix();
        $result = $this->callPrivate($mix, 'resolveMusicPath', [null, 'family']);
        $this->assertNull($result);
    }

    public function testResolveMusicPathReturnsFilePathForExistingFile(): void
    {
        $mix      = new AudioMix();
        $fakeMp3  = "{$this->tmpDir}/bg.mp3";
        file_put_contents($fakeMp3, str_repeat('X', 2000));  // >1000 bytes

        $result = $this->callPrivate($mix, 'resolveMusicPath', [$fakeMp3, 'family']);
        $this->assertSame($fakeMp3, $result);
    }

    public function testResolveMusicPathReturnsNullForUnknownNonExistentString(): void
    {
        $mix    = new AudioMix();
        $result = $this->callPrivate($mix, 'resolveMusicPath', ['/no/such/file.mp3', 'family']);
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // resolveMusicPath() with 'auto' delegates to pickTrack
    //
    // We use a subclass that overrides downloadTrack() to avoid network I/O.
    // -------------------------------------------------------------------------

    private function makeAudioMixWithFakeDownload(string $returnPath): AudioMix
    {
        return new class($returnPath) extends AudioMix {
            private string $fakePath;

            public function __construct(string $fakePath)
            {
                parent::__construct();
                $this->fakePath = $fakePath;
            }

            public function downloadTrack(string $trackName): string
            {
                return $this->fakePath;
            }
        };
    }

    public function testResolveMusicPathWithAutoCallsPickTrack(): void
    {
        $fakePath = "{$this->tmpDir}/fake.mp3";
        file_put_contents($fakePath, str_repeat('X', 2000));

        $mix    = $this->makeAudioMixWithFakeDownload($fakePath);
        $result = $this->callPrivate($mix, 'resolveMusicPath', ['auto', 'family']);

        // Should return a path (delegated to pickTrack → downloadTrack stub)
        $this->assertSame($fakePath, $result);
    }

    public function testResolveMusicPathWithEmptyStringCallsPickTrack(): void
    {
        $fakePath = "{$this->tmpDir}/fake2.mp3";
        file_put_contents($fakePath, str_repeat('X', 2000));

        $mix    = $this->makeAudioMixWithFakeDownload($fakePath);
        $result = $this->callPrivate($mix, 'resolveMusicPath', ['', 'energetic']);

        $this->assertSame($fakePath, $result);
    }

    // -------------------------------------------------------------------------
    // pickTrack() — determinism and mood selection
    // -------------------------------------------------------------------------

    public function testPickTrackWithSeedIsDeterministic(): void
    {
        $fakePath = "{$this->tmpDir}/deterministic.mp3";
        file_put_contents($fakePath, str_repeat('X', 2000));

        $mix = $this->makeAudioMixWithFakeDownload($fakePath);

        $first  = $mix->pickTrack('family', 3);
        $second = $mix->pickTrack('family', 3);

        $this->assertSame($first, $second);
    }

    public function testPickTrackWithFamilyMoodPicksFromFamilyTracks(): void
    {
        $capturedTrack = null;

        $mix = new class($capturedTrack) extends AudioMix {
            public ?string $lastTrack = null;

            public function __construct(?string &$captured)
            {
                parent::__construct();
            }

            public function downloadTrack(string $trackName): string
            {
                $this->lastTrack = $trackName;
                return '/fake/path.mp3';
            }
        };

        $mix->pickTrack('family', 0);

        $familyTracks = (new \ReflectionClassConstant(AudioMix::class, 'FAMILY_TRACKS'))->getValue();
        $this->assertContains($mix->lastTrack, $familyTracks);
    }

    public function testPickTrackWithEnergeticMoodPicksFromEnergeticTracks(): void
    {
        $mix = new class extends AudioMix {
            public ?string $lastTrack = null;

            public function __construct()
            {
                parent::__construct();
            }

            public function downloadTrack(string $trackName): string
            {
                $this->lastTrack = $trackName;
                return '/fake/path.mp3';
            }
        };

        $mix->pickTrack('energetic', 0);

        $energeticTracks = (new \ReflectionClassConstant(AudioMix::class, 'ENERGETIC_TRACKS'))->getValue();
        $this->assertContains($mix->lastTrack, $energeticTracks);
    }

    public function testPickTrackSeedSelectsCorrectIndexInPool(): void
    {
        $mix = new class extends AudioMix {
            public ?string $lastTrack = null;

            public function __construct()
            {
                parent::__construct();
            }

            public function downloadTrack(string $trackName): string
            {
                $this->lastTrack = $trackName;
                return '/fake/path.mp3';
            }
        };

        // Seed 0 → index 0 of FAMILY_TRACKS
        $mix->pickTrack('family', 0);
        $familyTracks = (new \ReflectionClassConstant(AudioMix::class, 'FAMILY_TRACKS'))->getValue();
        $this->assertSame($familyTracks[0], $mix->lastTrack);

        // Seed 2 → index 2 % count(FAMILY_TRACKS)
        $mix->pickTrack('family', 2);
        $expectedIdx = 2 % count($familyTracks);
        $this->assertSame($familyTracks[$expectedIdx], $mix->lastTrack);
    }

    // -------------------------------------------------------------------------
    // downloadTrack() — throws on unknown track
    // -------------------------------------------------------------------------

    public function testDownloadTrackThrowsOnUnknownTrackName(): void
    {
        $mix = new AudioMix();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown track/');

        $mix->downloadTrack('non-existent-track');
    }

    public function testDownloadTrackErrorMessageListsAvailableTracks(): void
    {
        $mix = new AudioMix();

        try {
            $mix->downloadTrack('totally-fake');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            // Should list at least some available track names
            $this->assertStringContainsString('dreaming-of-you', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // mix() — throws on missing video file
    // -------------------------------------------------------------------------

    public function testMixThrowsWhenVideoFileDoesNotExist(): void
    {
        $mix = new AudioMix();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Video file not found/');

        $mix->mix('/nonexistent/video.mp4', "{$this->tmpDir}/output.mp4");
    }

    public function testMixThrowsRuntimeExceptionWithVideoPath(): void
    {
        $mix = new AudioMix();
        $missingPath = '/absolutely/no/such/file.mp4';

        try {
            $mix->mix($missingPath, "{$this->tmpDir}/output.mp4");
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($missingPath, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // getVideoDuration() — returns default when ffprobe unavailable
    // -------------------------------------------------------------------------

    public function testGetVideoDurationReturnsDefaultWhenFfprobeNotAvailable(): void
    {
        // Point ffmpeg to /bin/false to ensure ffprobe also isn't found at a
        // valid path; we invoke getVideoDuration with a dummy path.
        putenv('FFMPEG_PATH=/bin/false');
        $mix = new AudioMix();

        // getVideoDuration uses shell_exec('ffprobe ...'), which will either
        // output nothing (not installed) or fail — both yield the default 15.0.
        $result = $this->callPrivate($mix, 'getVideoDuration', ['/nonexistent/video.mp4']);

        // Default is 15.0 when output is empty or non-numeric
        $this->assertSame(15.0, $result);
    }
}
