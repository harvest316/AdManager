<?php

declare(strict_types=1);

namespace AdManager\Tests\Meta;

use AdManager\Meta\Assets;
use AdManager\Meta\Client;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for Meta\Assets.
 *
 * Assets manages image and video uploads via Client::postMultipart() and
 * Client::get_api(). We inject a mock Client via the singleton slot and verify:
 * - uploadImage() throws when file not found
 * - uploadImage() extracts hash and url from nested response
 * - uploadImage() throws when hash is missing in response
 * - uploadVideo() throws when file not found
 * - uploadVideo() returns video ID from response
 * - uploadVideo() throws when id missing in response
 * - uploadVideo() includes title when provided
 * - waitForProcessing() returns immediately when status is 'ready'
 * - waitForProcessing() throws on 'error' status
 * - waitForProcessing() throws when timeout exceeded
 * - listImages() and listVideos() hit correct endpoints
 *
 * File-existence checks are bypassed by creating a real temp file or by
 * testing the thrown exception directly.
 */
class AssetsTest extends TestCase
{
    private const FAKE_AD_ACCOUNT_ID = 'act_111222333';
    private const FAKE_VIDEO_ID      = '555000555';

    /** @var string Temp file path for upload tests */
    private string $tmpFile = '';

    protected function setUp(): void
    {
        // Create a real temp file so file_exists() returns true
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'admanager_test_');
        file_put_contents($this->tmpFile, 'fake content');
    }

    protected function tearDown(): void
    {
        Client::reset();
        if ($this->tmpFile && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    // -------------------------------------------------------------------------
    // uploadImage()
    // -------------------------------------------------------------------------

    public function testUploadImageThrowsWhenFileNotFound(): void
    {
        $this->injectMockClient($this->buildPassthroughClientMock());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Image file not found');

        $assets = new Assets();
        $assets->uploadImage('/nonexistent/path/image.jpg');
    }

    public function testUploadImagePostsToAdImagesEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('postMultipart')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return ['images' => ['filename' => ['hash' => 'abc123', 'url' => 'https://example.com/img.jpg']]];
             });
        $this->injectMockClient($mock);

        $assets = new Assets();
        $assets->uploadImage($this->tmpFile);

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/adimages', $capture->endpoint);
    }

    public function testUploadImageReturnsHashAndUrl(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('postMultipart')->willReturn([
            'images' => ['testfile.jpg' => ['hash' => 'abc123hash', 'url' => 'https://cdn.facebook.com/img.jpg']],
        ]);
        $this->injectMockClient($mock);

        $assets = new Assets();
        $result = $assets->uploadImage($this->tmpFile);

        $this->assertSame('abc123hash', $result['hash']);
        $this->assertSame('https://cdn.facebook.com/img.jpg', $result['url']);
    }

    public function testUploadImageThrowsWhenHashMissingInResponse(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('postMultipart')->willReturn(['images' => []]); // empty images
        $this->injectMockClient($mock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to extract image hash');

        $assets = new Assets();
        $assets->uploadImage($this->tmpFile);
    }

    public function testUploadImageThrowsWhenImagesKeyMissing(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('postMultipart')->willReturn([]); // no 'images' key at all
        $this->injectMockClient($mock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to extract image hash');

        $assets = new Assets();
        $assets->uploadImage($this->tmpFile);
    }

    public function testUploadImageUrlDefaultsToEmptyStringWhenMissing(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('postMultipart')->willReturn([
            'images' => ['file.jpg' => ['hash' => 'xyz789']],  // no 'url' key
        ]);
        $this->injectMockClient($mock);

        $assets = new Assets();
        $result = $assets->uploadImage($this->tmpFile);

        $this->assertSame('', $result['url']);
    }

    // -------------------------------------------------------------------------
    // uploadVideo()
    // -------------------------------------------------------------------------

    public function testUploadVideoThrowsWhenFileNotFound(): void
    {
        $this->injectMockClient($this->buildPassthroughClientMock());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Video file not found');

        $assets = new Assets();
        $assets->uploadVideo('/nonexistent/path/video.mp4');
    }

    public function testUploadVideoPostsToAdVideosEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('postMultipart')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return ['id' => self::FAKE_VIDEO_ID];
             });
        $this->injectMockClient($mock);

        $assets = new Assets();
        $assets->uploadVideo($this->tmpFile);

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/advideos', $capture->endpoint);
    }

    public function testUploadVideoReturnsVideoId(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('postMultipart')->willReturn(['id' => self::FAKE_VIDEO_ID]);
        $this->injectMockClient($mock);

        $assets = new Assets();
        $result = $assets->uploadVideo($this->tmpFile);

        $this->assertSame(self::FAKE_VIDEO_ID, $result);
    }

    public function testUploadVideoIncludesTitleWhenProvided(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('postMultipart')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->data = $data;
                 return ['id' => self::FAKE_VIDEO_ID];
             });
        $this->injectMockClient($mock);

        $assets = new Assets();
        $assets->uploadVideo($this->tmpFile, 'Free Website Audit Demo');

        $this->assertArrayHasKey('title', $capture->data);
        $this->assertSame('Free Website Audit Demo', $capture->data['title']);
    }

    public function testUploadVideoOmitsTitleWhenEmpty(): void
    {
        $capture = new \stdClass();
        $capture->data = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('postMultipart')
             ->willReturnCallback(function (string $endpoint, array $data) use ($capture): array {
                 $capture->data = $data;
                 return ['id' => self::FAKE_VIDEO_ID];
             });
        $this->injectMockClient($mock);

        $assets = new Assets();
        $assets->uploadVideo($this->tmpFile); // no title

        $this->assertArrayNotHasKey('title', $capture->data);
    }

    public function testUploadVideoThrowsWhenIdMissingInResponse(): void
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('postMultipart')->willReturn([]); // no 'id' key
        $this->injectMockClient($mock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to extract video ID');

        $assets = new Assets();
        $assets->uploadVideo($this->tmpFile);
    }

    // -------------------------------------------------------------------------
    // waitForProcessing()
    // -------------------------------------------------------------------------

    public function testWaitForProcessingReturnsImmediatelyWhenReady(): void
    {
        $readyStatus = ['status' => ['video_status' => 'ready'], 'id' => self::FAKE_VIDEO_ID];

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')->willReturn($readyStatus);
        $this->injectMockClient($mock);

        $assets = new Assets();
        $result = $assets->waitForProcessing(self::FAKE_VIDEO_ID, 60, 1);

        $this->assertSame($readyStatus, $result);
    }

    public function testWaitForProcessingThrowsOnErrorStatus(): void
    {
        $errorStatus = ['status' => ['video_status' => 'error', 'error' => 'encode failed']];

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')->willReturn($errorStatus);
        $this->injectMockClient($mock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Video processing failed for ' . self::FAKE_VIDEO_ID);

        $assets = new Assets();
        $assets->waitForProcessing(self::FAKE_VIDEO_ID, 60, 1);
    }

    public function testWaitForProcessingThrowsOnTimeout(): void
    {
        // Return 'processing' forever — timeout of 0 seconds will expire immediately
        $processingStatus = ['status' => ['video_status' => 'processing']];

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')->willReturn($processingStatus);
        $this->injectMockClient($mock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Video processing timeout');

        $assets = new Assets();
        // Timeout of -1 seconds ensures the timeout check fires on the first loop iteration
        $assets->waitForProcessing(self::FAKE_VIDEO_ID, -1, 0);
    }

    public function testWaitForProcessingQueriesCorrectVideoId(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return ['status' => ['video_status' => 'ready']];
             });
        $this->injectMockClient($mock);

        $assets = new Assets();
        $assets->waitForProcessing(self::FAKE_VIDEO_ID);

        $this->assertSame(self::FAKE_VIDEO_ID, $capture->endpoint);
    }

    // -------------------------------------------------------------------------
    // listImages()
    // -------------------------------------------------------------------------

    public function testListImagesCallsAdImagesEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return ['data' => []];
             });
        $this->injectMockClient($mock);

        $assets = new Assets();
        $assets->listImages();

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/adimages', $capture->endpoint);
    }

    public function testListImagesReturnsDataArray(): void
    {
        $fakeData = [['hash' => 'abc', 'name' => 'image1.jpg']];

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')->willReturn(['data' => $fakeData]);
        $this->injectMockClient($mock);

        $assets = new Assets();
        $this->assertSame($fakeData, $assets->listImages());
    }

    // -------------------------------------------------------------------------
    // listVideos()
    // -------------------------------------------------------------------------

    public function testListVideosCallsAdVideosEndpoint(): void
    {
        $capture = new \stdClass();
        $capture->endpoint = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->endpoint = $endpoint;
                 return ['data' => []];
             });
        $this->injectMockClient($mock);

        $assets = new Assets();
        $assets->listVideos();

        $this->assertSame(self::FAKE_AD_ACCOUNT_ID . '/advideos', $capture->endpoint);
    }

    public function testListVideosIncludesStatusField(): void
    {
        $capture = new \stdClass();
        $capture->params = null;

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')
             ->willReturnCallback(function (string $endpoint, array $params) use ($capture): array {
                 $capture->params = $params;
                 return ['data' => []];
             });
        $this->injectMockClient($mock);

        $assets = new Assets();
        $assets->listVideos();

        $this->assertArrayHasKey('fields', $capture->params);
        $this->assertStringContainsString('status', $capture->params['fields']);
        $this->assertStringContainsString('title', $capture->params['fields']);
    }

    public function testListVideosReturnsDataArray(): void
    {
        $fakeData = [['id' => '999', 'title' => 'Demo Video']];

        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('get_api')->willReturn(['data' => $fakeData]);
        $this->injectMockClient($mock);

        $assets = new Assets();
        $this->assertSame($fakeData, $assets->listVideos());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildPassthroughClientMock(): Client
    {
        $mock = $this->createMock(Client::class);
        $mock->method('adAccountId')->willReturn(self::FAKE_AD_ACCOUNT_ID);
        $mock->method('postMultipart')->willReturn([]);
        $mock->method('get_api')->willReturn(['data' => []]);
        return $mock;
    }

    private function injectMockClient(Client $client): void
    {
        $ref = new \ReflectionProperty(Client::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $client);
    }
}
