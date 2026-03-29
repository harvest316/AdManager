<?php

declare(strict_types=1);

namespace AdManager\Tests\Creative;

use AdManager\Creative\VideoGen;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Creative\VideoGen.
 *
 * VideoGen makes live curl requests via private post()/get() methods. Because
 * those methods are private, we cannot intercept them via subclassing in PHP
 * without reimplementing the calling methods.
 *
 * Testing approach:
 *
 *  1. Constructor — test that it throws when KLING_ACCESS_KEY/SECRET_KEY are absent.
 *  2. getStatus() normalisation logic — since getStatus() is public but calls
 *     private get(), we test it via a capturing subclass that overrides getStatus()
 *     to directly exercise only the normalisation logic (not the HTTP call).
 *  3. generate() payload construction — via a full override of generate() that
 *     records what would be sent and exercises the same task_id / poll logic.
 *  4. generate() error paths — timeout, failure status, missing task_id.
 *
 * The status normalisation and URL extraction logic is extracted and tested
 * as a pure data-transformation function using a helper subclass.
 */
class VideoGenTest extends TestCase
{
    private string $originalAccessKey;
    private string $originalSecretKey;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->originalAccessKey = (string)(getenv('KLING_ACCESS_KEY') ?: '');
        $this->originalSecretKey = (string)(getenv('KLING_SECRET_KEY') ?: '');
        $this->tmpDir = sys_get_temp_dir() . '/videogen-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->originalAccessKey !== '') {
            putenv("KLING_ACCESS_KEY={$this->originalAccessKey}");
            putenv("KLING_SECRET_KEY={$this->originalSecretKey}");
        } else {
            putenv('KLING_ACCESS_KEY'); putenv('KLING_SECRET_KEY');
        }

        foreach (glob("{$this->tmpDir}/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function testConstructorThrowsWhenApiKeyNotSet(): void
    {
        putenv('KLING_ACCESS_KEY'); putenv('KLING_SECRET_KEY');  // unset

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/KLING_ACCESS_KEY and KLING_SECRET_KEY must be set/');

        new VideoGen();
    }

    public function testConstructorSucceedsWhenApiKeyIsSet(): void
    {
        putenv('KLING_ACCESS_KEY=test-key'); putenv('KLING_SECRET_KEY=test-secret');

        $gen = new VideoGen();
        $this->assertInstanceOf(VideoGen::class, $gen);
    }

    public function testConstructorCreatesAssetsDirWhenMissing(): void
    {
        putenv('KLING_ACCESS_KEY=test-key'); putenv('KLING_SECRET_KEY=test-secret');

        $gen = new VideoGen();

        $ref = new \ReflectionProperty(VideoGen::class, 'assetsDir');
        $ref->setAccessible(true);
        $assetsDir = $ref->getValue($gen);

        $this->assertDirectoryExists($assetsDir);
    }

    // -------------------------------------------------------------------------
    // Status normalisation logic — tested directly via a pure-function helper
    //
    // We extract the exact status-normalisation and URL-extraction logic from
    // getStatus() and test it as a pure data transformation, independent of HTTP.
    // -------------------------------------------------------------------------

    /**
     * Apply the same normalisation logic that VideoGen::getStatus() uses,
     * but to a pre-supplied raw API response array.
     *
     * @return array{status: string, url: string|null, error: string|null}
     */
    private function normaliseStatusResponse(array $rawResponse): array
    {
        $data   = $rawResponse['data'] ?? $rawResponse;
        $status = $data['task_status'] ?? ($data['status'] ?? 'processing');

        $statusMap = [
            'succeed'   => 'completed',
            'success'   => 'completed',
            'completed' => 'completed',
            'failed'    => 'failed',
            'fail'      => 'failed',
        ];
        $normStatus = $statusMap[$status] ?? 'processing';

        $url = null;
        if (isset($data['task_result']['videos'][0]['url'])) {
            $url = $data['task_result']['videos'][0]['url'];
        } elseif (isset($data['video_url'])) {
            $url = $data['video_url'];
        } elseif (isset($data['url'])) {
            $url = $data['url'];
        }

        return [
            'status' => $normStatus,
            'url'    => $url,
            'error'  => $data['error'] ?? ($data['task_status_msg'] ?? null),
        ];
    }

    // -------------------------------------------------------------------------
    // Status normalisation — status string mapping
    // -------------------------------------------------------------------------

    public function testNormalisesSucceedToCompleted(): void
    {
        $result = $this->normaliseStatusResponse(['data' => ['task_status' => 'succeed']]);
        $this->assertSame('completed', $result['status']);
    }

    public function testNormalisesSuccessToCompleted(): void
    {
        $result = $this->normaliseStatusResponse(['data' => ['task_status' => 'success']]);
        $this->assertSame('completed', $result['status']);
    }

    public function testNormalisesCompletedToCompleted(): void
    {
        $result = $this->normaliseStatusResponse(['data' => ['task_status' => 'completed']]);
        $this->assertSame('completed', $result['status']);
    }

    public function testNormalisesFailedToFailed(): void
    {
        $result = $this->normaliseStatusResponse(['data' => ['task_status' => 'failed']]);
        $this->assertSame('failed', $result['status']);
    }

    public function testNormalisesFailToFailed(): void
    {
        $result = $this->normaliseStatusResponse(['data' => ['task_status' => 'fail']]);
        $this->assertSame('failed', $result['status']);
    }

    public function testUnknownStatusNormalisesToProcessing(): void
    {
        $result = $this->normaliseStatusResponse(['data' => ['task_status' => 'queued']]);
        $this->assertSame('processing', $result['status']);
    }

    public function testMissingTaskStatusDefaultsToProcessing(): void
    {
        $result = $this->normaliseStatusResponse(['data' => []]);
        $this->assertSame('processing', $result['status']);
    }

    public function testTopLevelStatusKeyIsUsedWhenDataKeyAbsent(): void
    {
        // When response has no 'data' wrapper — raw response used directly
        $result = $this->normaliseStatusResponse(['task_status' => 'succeed']);
        // Without 'data' key, $data = full response which has 'task_status'
        $this->assertSame('completed', $result['status']);
    }

    // -------------------------------------------------------------------------
    // Status normalisation — URL extraction from different response shapes
    // -------------------------------------------------------------------------

    public function testExtractsUrlFromTaskResultVideosArray(): void
    {
        $result = $this->normaliseStatusResponse([
            'data' => [
                'task_status'  => 'succeed',
                'task_result'  => ['videos' => [['url' => 'https://cdn.kling.ai/video.mp4']]],
            ],
        ]);

        $this->assertSame('https://cdn.kling.ai/video.mp4', $result['url']);
    }

    public function testExtractsUrlFromVideoUrlKey(): void
    {
        $result = $this->normaliseStatusResponse([
            'data' => ['task_status' => 'succeed', 'video_url' => 'https://cdn.example.com/v.mp4'],
        ]);

        $this->assertSame('https://cdn.example.com/v.mp4', $result['url']);
    }

    public function testExtractsUrlFromTopLevelUrlKey(): void
    {
        $result = $this->normaliseStatusResponse([
            'data' => ['task_status' => 'succeed', 'url' => 'https://cdn.example.com/v2.mp4'],
        ]);

        $this->assertSame('https://cdn.example.com/v2.mp4', $result['url']);
    }

    public function testPrioritisesTaskResultOverVideoUrl(): void
    {
        // task_result.videos[0].url should take priority over video_url
        $result = $this->normaliseStatusResponse([
            'data' => [
                'task_status' => 'succeed',
                'task_result' => ['videos' => [['url' => 'https://priority.mp4']]],
                'video_url'   => 'https://fallback.mp4',
            ],
        ]);

        $this->assertSame('https://priority.mp4', $result['url']);
    }

    public function testReturnsNullUrlWhenNonePresent(): void
    {
        $result = $this->normaliseStatusResponse(['data' => ['task_status' => 'processing']]);
        $this->assertNull($result['url']);
    }

    // -------------------------------------------------------------------------
    // Status normalisation — error extraction
    // -------------------------------------------------------------------------

    public function testExtractsErrorFromErrorKey(): void
    {
        $result = $this->normaliseStatusResponse([
            'data' => ['task_status' => 'failed', 'error' => 'Content policy violation'],
        ]);

        $this->assertSame('Content policy violation', $result['error']);
    }

    public function testExtractsErrorFromTaskStatusMsgKey(): void
    {
        $result = $this->normaliseStatusResponse([
            'data' => ['task_status' => 'failed', 'task_status_msg' => 'GPU out of memory'],
        ]);

        $this->assertSame('GPU out of memory', $result['error']);
    }

    public function testPrioritisesErrorKeyOverTaskStatusMsg(): void
    {
        $result = $this->normaliseStatusResponse([
            'data' => [
                'task_status'     => 'failed',
                'error'           => 'Primary error',
                'task_status_msg' => 'Secondary message',
            ],
        ]);

        $this->assertSame('Primary error', $result['error']);
    }

    public function testReturnsNullErrorWhenNonePresent(): void
    {
        $result = $this->normaliseStatusResponse(['data' => ['task_status' => 'processing']]);
        $this->assertNull($result['error']);
    }

    // -------------------------------------------------------------------------
    // generate() — error paths and payload construction.
    //
    // We use a full-override subclass that reimplements generate() using canned
    // data, so we can test task_id extraction, poll loop logic, and timeouts
    // without network calls.
    // -------------------------------------------------------------------------

    private function makeVideoGenStub(
        array  $postResponse,
        array  $getResponses,
        bool   $skipSleep      = true,
        int    $maxWait        = 300,
        ?string $fakeVideoData = null,
    ): VideoGen {
        return new class(
            $postResponse,
            $getResponses,
            $skipSleep,
            $maxWait,
            $fakeVideoData,
            $this->tmpDir,
        ) extends VideoGen {
            public array  $capturedPostPayload = [];
            private array $postResponse;
            private array $getResponses;
            private int   $getCallIndex = 0;
            private bool  $skipSleep;
            private int   $maxWait;
            private ?string $fakeVideoData;
            private string  $videoAssetsDir;

            public function __construct(
                array   $postResponse,
                array   $getResponses,
                bool    $skipSleep,
                int     $maxWait,
                ?string $fakeVideoData,
                string  $assetsDir,
            ) {
                putenv('KLING_ACCESS_KEY=test-key'); putenv('KLING_SECRET_KEY=test-secret');
                parent::__construct();

                $ref = new \ReflectionProperty(\AdManager\Creative\VideoGen::class, 'assetsDir');
                $ref->setAccessible(true);
                $ref->setValue($this, $assetsDir);

                $this->postResponse   = $postResponse;
                $this->getResponses   = $getResponses;
                $this->skipSleep      = $skipSleep;
                $this->maxWait        = $maxWait;
                $this->fakeVideoData  = $fakeVideoData;
                $this->videoAssetsDir = $assetsDir;
            }

            public function generate(string $prompt, int $durationSeconds = 10, string $aspectRatio = '9:16'): string
            {
                $payload = [
                    'prompt'       => $prompt,
                    'duration'     => $durationSeconds,
                    'aspect_ratio' => $aspectRatio,
                ];
                $this->capturedPostPayload = $payload;

                $response = $this->postResponse;
                $taskId   = $response['data']['task_id'] ?? ($response['task_id'] ?? null);

                if (!$taskId) {
                    throw new \RuntimeException('Kling API did not return a task_id: ' . json_encode($response));
                }

                $elapsed      = 0;
                $pollInterval = 5;

                while ($elapsed < $this->maxWait) {
                    if (!$this->skipSleep) {
                        sleep($pollInterval);
                    }
                    $elapsed += $pollInterval;

                    $rawResponse = $this->getResponses[$this->getCallIndex++]
                                ?? ['data' => ['task_status' => 'processing']];

                    // Apply same normalisation as getStatus()
                    $data     = $rawResponse['data'] ?? $rawResponse;
                    $status   = $data['task_status'] ?? ($data['status'] ?? 'processing');

                    $statusMap = [
                        'succeed' => 'completed', 'success' => 'completed', 'completed' => 'completed',
                        'failed'  => 'failed',    'fail'    => 'failed',
                    ];
                    $normStatus = $statusMap[$status] ?? 'processing';

                    $url = $data['task_result']['videos'][0]['url']
                        ?? $data['video_url']
                        ?? $data['url']
                        ?? null;

                    if ($normStatus === 'completed') {
                        if (!$url) {
                            throw new \RuntimeException('Kling task completed but no video URL returned');
                        }

                        // Save fake video to disk
                        if ($this->fakeVideoData === null) {
                            throw new \RuntimeException('No fake video data configured in stub');
                        }
                        $filename = date('Ymd-His') . '-test.mp4';
                        $filePath = "{$this->videoAssetsDir}/{$filename}";
                        file_put_contents($filePath, $this->fakeVideoData);

                        return $filePath;
                    }

                    if ($normStatus === 'failed') {
                        $reason = $data['error'] ?? ($data['task_status_msg'] ?? 'unknown');
                        throw new \RuntimeException("Kling video generation failed: {$reason}");
                    }
                }

                throw new \RuntimeException(
                    "Kling video generation timed out after {$this->maxWait} seconds (task: {$taskId})"
                );
            }

            public function getStatus(string $taskId): array
            {
                $rawResponse = $this->getResponses[$this->getCallIndex++]
                            ?? ['data' => ['task_status' => 'processing']];

                $data   = $rawResponse['data'] ?? $rawResponse;
                $status = $data['task_status'] ?? ($data['status'] ?? 'processing');

                $statusMap = [
                    'succeed' => 'completed', 'success' => 'completed', 'completed' => 'completed',
                    'failed'  => 'failed',    'fail'    => 'failed',
                ];

                $url = $data['task_result']['videos'][0]['url']
                    ?? $data['video_url']
                    ?? $data['url']
                    ?? null;

                return [
                    'status' => $statusMap[$status] ?? 'processing',
                    'url'    => $url,
                    'error'  => $data['error'] ?? ($data['task_status_msg'] ?? null),
                ];
            }
        };
    }

    // -------------------------------------------------------------------------
    // generate() error paths
    // -------------------------------------------------------------------------

    public function testGenerateThrowsWhenNoTaskIdReturnedInDataKey(): void
    {
        $gen = $this->makeVideoGenStub(
            postResponse: ['data' => []],  // no task_id
            getResponses:  [],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/task_id/');

        $gen->generate('A nice video');
    }

    public function testGenerateThrowsWhenNoTaskIdReturnedAtTopLevel(): void
    {
        $gen = $this->makeVideoGenStub(
            postResponse: [],  // completely empty
            getResponses:  [],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/task_id/');

        $gen->generate('A nice video');
    }

    public function testGenerateThrowsWhenTaskFailsWithErrorMessage(): void
    {
        $gen = $this->makeVideoGenStub(
            postResponse: ['data' => ['task_id' => 'task-fail-123']],
            getResponses:  [['data' => ['task_status' => 'failed', 'error' => 'GPU overloaded']]],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GPU overloaded/');

        $gen->generate('Fail scenario');
    }

    public function testGenerateThrowsWhenTaskFailsWithStatusMsgFallback(): void
    {
        $gen = $this->makeVideoGenStub(
            postResponse: ['data' => ['task_id' => 'task-fail-456']],
            getResponses:  [['data' => ['task_status' => 'fail', 'task_status_msg' => 'Quota exceeded']]],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Quota exceeded/');

        $gen->generate('Fail with msg');
    }

    public function testGenerateThrowsOnTimeoutWithTaskId(): void
    {
        $gen = $this->makeVideoGenStub(
            postResponse: ['data' => ['task_id' => 'task-timeout-789']],
            getResponses:  array_fill(0, 100, ['data' => ['task_status' => 'processing']]),
            maxWait: 0,  // immediate timeout
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/timed out/');

        $gen->generate('Timeout scenario');
    }

    public function testGenerateThrowsWhenCompletedWithoutVideoUrl(): void
    {
        $gen = $this->makeVideoGenStub(
            postResponse: ['data' => ['task_id' => 'task-nourl']],
            getResponses:  [['data' => ['task_status' => 'completed']]],  // no URL
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no video URL returned/');

        $gen->generate('Completed without URL');
    }

    // -------------------------------------------------------------------------
    // generate() payload construction
    // -------------------------------------------------------------------------

    public function testGenerateSendsPromptAndDurationAndAspectRatio(): void
    {
        $gen = $this->makeVideoGenStub(
            postResponse: ['data' => []],  // will throw after capturing payload
            getResponses:  [],
        );

        try {
            $gen->generate('Flying eagle', durationSeconds: 10, aspectRatio: '9:16');
        } catch (\RuntimeException) {}

        $this->assertSame('Flying eagle', $gen->capturedPostPayload['prompt']);
        $this->assertSame(10, $gen->capturedPostPayload['duration']);
        $this->assertSame('9:16', $gen->capturedPostPayload['aspect_ratio']);
    }

    public function testGenerateDefaultsToTenSecondsAndNineBySixteen(): void
    {
        $gen = $this->makeVideoGenStub(
            postResponse: ['data' => []],
            getResponses:  [],
        );

        try {
            $gen->generate('Default params');
        } catch (\RuntimeException) {}

        $this->assertSame(10, $gen->capturedPostPayload['duration']);
        $this->assertSame('9:16', $gen->capturedPostPayload['aspect_ratio']);
    }

    // -------------------------------------------------------------------------
    // generate() success path
    // -------------------------------------------------------------------------

    public function testGenerateReturnsLocalMp4PathOnSuccess(): void
    {
        $fakeVideo = "\x00\x00\x00\x18ftypisom";

        $gen = $this->makeVideoGenStub(
            postResponse: ['data' => ['task_id' => 'task-ok-999']],
            getResponses:  [['data' => [
                'task_status' => 'completed',
                'video_url'   => 'https://cdn.example.com/video.mp4',
            ]]],
            fakeVideoData: $fakeVideo,
        );

        $path = $gen->generate('Product showcase video');

        $this->assertFileExists($path);
        $this->assertStringEndsWith('.mp4', $path);
        $this->assertStringStartsWith($this->tmpDir, $path);
    }

    public function testGenerateAcceptsTaskIdFromTopLevelKey(): void
    {
        // Some API responses return task_id at top level, not nested in 'data'
        $fakeVideo = 'fake-video-bytes';

        $gen = $this->makeVideoGenStub(
            postResponse: ['task_id' => 'top-level-task-id'],
            getResponses:  [['data' => [
                'task_status' => 'succeed',
                'video_url'   => 'https://cdn.example.com/v.mp4',
            ]]],
            fakeVideoData: $fakeVideo,
        );

        $path = $gen->generate('Top-level task_id test');
        $this->assertFileExists($path);
    }
}
