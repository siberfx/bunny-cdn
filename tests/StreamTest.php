<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Tests;

use PHPUnit\Framework\TestCase;
use Siberfx\BunnyCdn\BunnyAPIException;
use Siberfx\BunnyCdn\BunnyAPIStream;

final class StreamTest extends TestCase
{
    private FakeHttpClient $http;
    private BunnyAPIStream $bunny;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->bunny = new BunnyAPIStream('api-key', 'stream-key', $this->http);
        $this->bunny->setStreamLibraryId(42);
    }

    public function testUsesStreamHostAndLibraryKey(): void
    {
        $this->bunny->getVideo('vid');
        $request = $this->http->last();
        self::assertSame('https://video.bunnycdn.com/library/42/videos/vid', $request['url']);
        self::assertSame('stream-key', $request['headers']['AccessKey']);
    }

    public function testLibraryIdRequired(): void
    {
        $this->expectException(BunnyAPIException::class);
        new BunnyAPIStream('a', 'b', $this->http)->listVideos();
    }

    public function testCollections(): void
    {
        $this->bunny->getStreamCollections(search: 'cats');
        self::assertSame('https://video.bunnycdn.com/library/42/collections?page=1&itemsPerPage=100&search=cats&orderBy=date&includeThumbnails=false', $this->http->last()['url']);

        $this->bunny->setStreamCollectionGuid('col');
        $this->bunny->updateCollection('renamed');
        self::assertSame(['name' => 'renamed'], $this->http->lastJson());
        $this->bunny->listVideosForCollectionId();
        self::assertSame('https://video.bunnycdn.com/library/42/videos?page=1&itemsPerPage=100&collection=col&orderBy=date', $this->http->last()['url']);
    }

    public function testCreateAndUpdateVideo(): void
    {
        $this->bunny->setStreamCollectionGuid('col');
        $this->bunny->createVideoForCollection('Title', 5);
        self::assertSame(['title' => 'Title', 'collectionId' => 'col', 'thumbnailTime' => 5], $this->http->lastJson());

        $this->bunny->updateVideo('vid', ['title' => 'New']);
        self::assertSame(['POST', 'https://video.bunnycdn.com/library/42/videos/vid'], [$this->http->last()['method'], $this->http->last()['url']]);
    }

    public function testUploadVideoIsOctetStream(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vid');
        file_put_contents($file, 'video-bytes');
        try {
            $this->bunny->uploadVideo('vid', $file, ['enabledResolutions' => '720p']);
            $request = $this->http->last();
            self::assertSame('PUT', $request['method']);
            self::assertSame('https://video.bunnycdn.com/library/42/videos/vid?enabledResolutions=720p', $request['url']);
            self::assertSame('application/octet-stream', $request['headers']['Content-Type']);
            self::assertSame('video-bytes', $request['body']);
        } finally {
            unlink($file);
        }
    }

    public function testThumbnailCaptionsAndFetch(): void
    {
        $this->bunny->setThumbnail('vid', 'https://img.test/a.jpg');
        self::assertSame('https://video.bunnycdn.com/library/42/videos/vid/thumbnail?thumbnailUrl=https%3A%2F%2Fimg.test%2Fa.jpg', $this->http->last()['url']);

        $this->bunny->addCaptions('vid', 'en', 'English', "WEBVTT\n");
        self::assertSame('https://video.bunnycdn.com/library/42/videos/vid/captions/en', $this->http->last()['url']);
        self::assertSame(['label' => 'English', 'captionsFile' => base64_encode("WEBVTT\n")], $this->http->lastJson());

        $this->bunny->fetchVideo('https://src.test/v.mp4', 'col', 'Title');
        self::assertSame('https://video.bunnycdn.com/library/42/videos/fetch?collectionId=col', $this->http->last()['url']);
        self::assertSame(['url' => 'https://src.test/v.mp4', 'title' => 'Title'], $this->http->lastJson());
    }

    public function testNewVideoEndpoints(): void
    {
        $this->bunny->transcribeVideo('vid', ['en', 'de'], 'en', true);
        self::assertSame('https://video.bunnycdn.com/library/42/videos/vid/transcribe?force=true', $this->http->last()['url']);
        self::assertSame(['targetLanguages' => ['en', 'de'], 'sourceLanguage' => 'en'], $this->http->lastJson());

        $this->bunny->repackageVideo('vid');
        self::assertSame('https://video.bunnycdn.com/library/42/videos/vid/repackage?keepOriginalFiles=true', $this->http->last()['url']);

        $this->bunny->getVideoResolutions('vid');
        self::assertSame('https://video.bunnycdn.com/library/42/videos/vid/resolutions', $this->http->last()['url']);

        $this->bunny->cleanupVideoResolutions('vid', ['resolutionsToDelete' => '240p', 'dryRun' => true]);
        self::assertSame('https://video.bunnycdn.com/library/42/videos/vid/resolutions/cleanup?resolutionsToDelete=240p&dryRun=true', $this->http->last()['url']);

        $this->bunny->setStreamVideoGuid('vid');
        $this->bunny->getVideoPlayData();
        self::assertSame('https://video.bunnycdn.com/library/42/videos/vid/play', $this->http->last()['url']);
    }

    public function testVideoSizeAndResolutions(): void
    {
        $this->http->push(200, ['storageSize' => 1048576 * 3, 'availableResolutions' => '360p,720p']);
        self::assertSame(3, $this->bunny->videoSize('vid'));
        $this->http->push(200, ['storageSize' => 0, 'availableResolutions' => '360p,720p']);
        self::assertSame(['360p', '720p'], $this->bunny->videoResolutionsArray('vid'));
    }
}
